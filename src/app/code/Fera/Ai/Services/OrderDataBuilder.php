<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Directory\Helper\Data as DirectoryHelperData;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * @phpstan-type FeraLineItem array{
 *     product_id: int,
 *     price?: float,
 *     total?: float,
 *     name: string,
 *     quantity: int,
 *     variant_id?: int
 * }
 *
 * @phpstan-type FeraCustomerData array{
 *     external_id?: int,
 *     name: string,
 *     email: string,
 *     phone_number?: string|null
 * }
 *
 * @phpstan-type FeraAddressData array{
 *     name: string,
 *     address1: string,
 *     address2: string,
 *     city_name: string,
 *     region_name: string,
 *     zip_code: string
 * }
 * @phpstan-type FeraOrder array{
 *     total?: float,
 *     total_usd?: float,
 *     external_updated_at?: string,
 *     line_items?: array<int, FeraLineItem>,
 *     external_id: string,
 *     number?: string,
 *     external_created_at?: string,
 *     customer?: FeraCustomerData,
 *     tags?: array<string>,
 *     source_name?: string,
 *     shipping_address?: FeraAddressData,
 *     billing_address?: FeraAddressData,
 *     phone_number?: string|null,
 *     is_cancelled?: bool
 * }
 */

class OrderDataBuilder
{
    private FeraHelper $helper;
    private DirectoryHelperData $directoryHelper;
    private CustomerRepositoryInterface $customerRepository;

    public function __construct(
        FeraHelper $helper,
        DirectoryHelperData $directoryHelper,
        CustomerRepositoryInterface $customerRepository
    ) {
        $this->helper = $helper;
        $this->directoryHelper = $directoryHelper;
        $this->customerRepository = $customerRepository;
    }

    /**
     * Build order data for Fera API - used for both creation and updates
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return array
     * @phpstan-return FeraOrder
     */
    public function buildOrderData(OrderInterface $order): array
    {
        $storeId = (int) $order->getStoreId();
        $minimizeDataSharing = $this->helper->isMinimizeDataSharingEnabled($storeId);
        
        $currencyCode = $order->getOrderCurrencyCode() ?? "USD";
        $total = $this->calculateRemainingTotal($order);
        $totalUsd = $this->convertToUsd($total, $currencyCode);
        $lineItems = $this->getLineItems($order, $minimizeDataSharing);

        $orderId = $order->getEntityId();
        if (!is_numeric($orderId)) {
            throw new \RuntimeException(
                'Order entity ID is not numeric: ' . get_debug_type($orderId)
            );
        }

        $data = [
            'external_updated_at' => $this->helper->formatDate($order->getUpdatedAt() ?? ''),
            'external_id' => (string) $orderId,
            'number' => $order->getIncrementId() ?? '',
            'external_created_at' => $this->helper->formatDate($order->getCreatedAt() ?? ''),
            'customer' => $this->getCustomerData($order, $minimizeDataSharing),
            'tags' => [],
            'source_name' => 'web',
            'line_items' => $lineItems,
            'is_cancelled' => empty($lineItems)
        ];

        if (!$minimizeDataSharing) {
            $data['total'] = $total;
            $data['total_usd'] = $totalUsd;

            $shippingAddress = $this->getShippingAddress($order);
            if ($shippingAddress) {
                $data['shipping_address'] = $this->getAddressData($shippingAddress);
            }
            
            $billingAddress = $order->getBillingAddress();
            if ($billingAddress) {
                $data['billing_address'] = $this->getAddressData($billingAddress);
                $data['phone_number'] = $billingAddress->getTelephone();
            }
        }

        return $data;
    }

    public function calculateRemainingTotal(OrderInterface $order): float
    {
        return $order->getGrandTotal() - $order->getTotalCanceled() - $order->getTotalRefunded();
    }

    public function convertToUsd(float $amount, string $currencyCode): float
    {
        return $this->directoryHelper->currencyConvert($amount, $currencyCode, 'USD');
    }

    /**
     * Get serialized line items from order
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param bool $minimizeDataSharing
     * @return array
     * @phpstan-return array<int, FeraLineItem>
     */
    public function getLineItems(OrderInterface $order, bool $minimizeDataSharing = false): array
    {
        $items = $this->helper->serializeQuoteItems($order->getItems());
        
        if ($minimizeDataSharing) {
            return array_map(static function (array $item): array {
                unset($item['price'], $item['total']);
                return $item;
            }, $items);
        }
        
        return $items;
    }

    /**
     * Get customer data for Fera API
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param bool $minimizeDataSharing
     * @return array
     * @phpstan-return FeraCustomerData
     */
    public function getCustomerData(OrderInterface $order, bool $minimizeDataSharing = false): array
    {
        $customerId = $order->getCustomerId();
        $name = $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname();
        $email = (string)$order->getCustomerEmail();

        if ($customerId) {
            try {
                $customer = $this->customerRepository->getById((int)$customerId);
                $firstName = trim((string)$customer->getFirstname());
                $lastName = trim((string)$customer->getLastname());
                $computedName = $firstName . ' ' . $lastName;
                if ($computedName !== '') {
                    $name = $computedName;
                }
                if ($customer->getEmail()) {
                    $email = (string)$customer->getEmail();
                }
            } catch (NoSuchEntityException $e) {
                $entityId = $order->getEntityId();
                $orderId = is_numeric($entityId) ? (string) $entityId : 'unknown';
                $this->helper->log(
                    'Customer not found for order ' . $orderId . ': ' . $e->getMessage()
                );
            }
        }

        $result = [
            'name' => $name,
            'email' => $email,
        ];

        if (!$minimizeDataSharing) {
            $result['phone_number'] = $order->getBillingAddress() ? $order->getBillingAddress()->getTelephone() : null;
        }

        if ($customerId) {
            $result['external_id'] = (int)$customerId;
        }

        return $result;
    }

    /**
     * Convert address data for Fera API
     *
     * @param \Magento\Sales\Api\Data\OrderAddressInterface $address
     * @return array
     * @phpstan-return FeraAddressData
     */
    public function getAddressData(OrderAddressInterface $address): array
    {
        $street = $address->getStreet();
        $address1 = $street[0] ?? '';
        $address2 = $street[1] ?? '';

        return [
            'name' => $address->getFirstname() . ' ' . $address->getLastname(),
            'address1' => $address1,
            'address2' => $address2,
            'city_name' => $address->getCity(),
            'region_name' => $address->getRegion() ?? '',
            'zip_code' => $address->getPostcode(),
        ];
    }

    public function getShippingAddress(OrderInterface $order) : ?OrderAddressInterface
    {
        $assignments = $order->getExtensionAttributes()?->getShippingAssignments() ?? [];
        $firstAssignment = reset($assignments);

        if (!$firstAssignment) {
            return null;
        }

        return $firstAssignment->getShipping()->getAddress();
    }
}
