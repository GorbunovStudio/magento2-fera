<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Api\ApiClient\OrdersClientInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Directory\Helper\Data as DirectoryHelperData;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;

/**
 * @phpstan-import-type FeraLineItem from OrdersClientInterface
 * @phpstan-import-type FeraCustomerData from OrdersClientInterface
 * @phpstan-import-type FeraAddressData from OrdersClientInterface
 * @phpstan-import-type FeraOrder from OrdersClientInterface
 */
class OrderDataBuilder implements ResetAfterRequestInterface
{
    public function __construct(
        private FeraHelper $helper,
        private DirectoryHelperData $directoryHelper,
        private CustomerRepositoryInterface $customerRepository,
        private CustomerRegistry $customerRegistry,
        private CollectionFactory $productCollectionFactory,
        private Status $productStatus
    ) {
    }

    public function _resetState(): void
    {
        $this->customerRegistry->_resetState();
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

        $isCancelled = in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true);

        $data = [
            'external_updated_at' => $this->helper->formatDate($order->getUpdatedAt() ?? ''),
            'external_id' => (string) $orderId,
            'number' => $order->getIncrementId() ?? '',
            'external_created_at' => $this->helper->formatDate($order->getCreatedAt() ?? ''),
            'customer' => $this->getCustomerData($order, $minimizeDataSharing),
            'tags' => [],
            'source_name' => 'web',
            'line_items' => $lineItems,
            'is_cancelled' => $isCancelled
        ];

        if ($order->getState() === Order::STATE_COMPLETE) {
            if (!$order instanceof Order) {
                throw new \RuntimeException(
                    'Incorrect type for Product: expected ' . Order::class . ', got ' . get_debug_type($order)
                );
            }

            $shipmentsCollection = $order->getShipmentsCollection();
            if ($shipmentsCollection === false) {
                throw new \RuntimeException(
                    'Shipments collection is not available on the order instance. Order ID: ' . $orderId
                );
            }

            $shipments = $shipmentsCollection->getItems();

            $fulfilledAt = $order->getCreatedAt();

            foreach ($shipments as $shipment) {
                if ($fulfilledAt === null || $shipment->getCreatedAt() > $fulfilledAt) {
                    $fulfilledAt = $shipment->getCreatedAt();
                }
            }

            if (is_string($fulfilledAt) && $fulfilledAt !== '') {
                $data['fulfilled_at'] = $this->helper->formatDate($fulfilledAt);
            }
        }

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
        $items = $this->serializeOrderItems($order->getItems(), (int) $order->getStoreId());
        
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

    /**
     * Get map of enabled product IDs from order items
     *
     * @param \Magento\Sales\Api\Data\OrderItemInterface[] $items
     * @param int $storeId
     * @return array<int, bool>
     */
    private function getEnabledProductIdMap(array $items, int $storeId): array
    {
        $productIds = [];
        foreach ($items as $item) {
            $productId = (int) $item->getProductId();
            if ($productId > 0) {
                $productIds[] = $productId;
            }
        }

        if (empty($productIds)) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', ['in' => array_unique($productIds)]);
        $collection->addAttributeToSelect('status');
        $collection->setStoreId($storeId);

        $enabledMap = [];
        foreach ($collection as $product) {
            $enabledMap[(int) $product->getId()] = (int) $product->getStatus() === Status::STATUS_ENABLED;
        }

        foreach ($productIds as $productId) {
            if (!isset($enabledMap[$productId])) {
                $enabledMap[$productId] = false;
            }
        }

        return $enabledMap;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderItemInterface[] $items
     * @param int $storeId
     * @return mixed[]
     * @phpstan-return array<int, array{
     *     product_id:int,
     *     price:float,
     *     total:float,
     *     name:string,
     *     quantity:int,
     *     variant_id?:int
     * }>
     */
    public function serializeOrderItems(array $items, int $storeId): array
    {
        $enabledProductMap = $this->getEnabledProductIdMap($items, $storeId);
        $parentTypeMap = [];
        $itemMap = [];
        $childItems = [];

        foreach ($items as $orderItem) {
            if ($orderItem->getParentItemId()) {
                $childItems[] = $orderItem;
                continue;
            }

            $parentId = $orderItem->getItemId();
            $parentType = $orderItem->getProductType();
            $parentTypeMap[$parentId] = $parentType;

            $productId = (int) $orderItem->getProductId();
            if (!($enabledProductMap[$productId] ?? false)) {
                continue;
            }
            
            $data = $this->buildItemData($orderItem);
            if ($data !== null) {
                $itemMap[$parentId] = $data;
            }
        }

        foreach ($childItems as $orderItem) {
            $parentId = $orderItem->getParentItemId();
            $parentType = isset($parentTypeMap[$parentId]) ? $parentTypeMap[$parentId] : null;

            if ($parentType === 'configurable') {
                $childProductId = (int) $orderItem->getProductId();
                $isChildEnabled = $enabledProductMap[$childProductId] ?? false;
                
                if ($this->getRemainingQuantity($orderItem) <= 0 || !$isChildEnabled) {
                    unset($itemMap[$parentId]);
                } elseif (isset($itemMap[$parentId])) {
                    $itemMap[$parentId]['name'] = $orderItem->getName() ?? '';
                    $itemMap[$parentId]['variant_id'] = $childProductId;
                }
                continue;
            }

            if ($parentType === 'bundle') {
                continue;
            }

            if (!($enabledProductMap[(int) $orderItem->getProductId()] ?? false)) {
                continue;
            }
            
            $data = $this->buildItemData($orderItem);
            if ($data !== null) {
                $itemMap[$orderItem->getItemId()] = $data;
            }
        }

        return array_values($itemMap);
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderItemInterface $item
     */
    private function getRemainingQuantity(OrderItemInterface $item): int
    {
        $qtyOrdered = (float) $item->getQtyOrdered();
        $qtyRefunded = (float) $item->getQtyRefunded();
        $qtyCanceled = (float) $item->getQtyCanceled();
        $left = (int) max(0, (int) round($qtyOrdered) - (int) round($qtyRefunded) - (int) round($qtyCanceled));

        return $left;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderItemInterface $item
     * @return array{product_id:int,price:float,total:float,name:string,quantity:int}|null
     */
    private function buildItemData(OrderItemInterface $item): ?array
    {
        $qty = $this->getRemainingQuantity($item);
        if ($qty <= 0) {
            return null;
        }

        $rowTotal = (float) ($item->getRowTotal() ?? 0.0);
        $qtyOrdered = (float) $item->getQtyOrdered();
        if ($qtyOrdered > 0 && $qty < (int) round($qtyOrdered)) {
            $ratio = $qty / $qtyOrdered;
            $rowTotal = $rowTotal * $ratio;
        }

        return [
            'product_id' => (int) $item->getProductId(),
            'price' => $item->getPrice() ?? 0.0,
            'total' => $rowTotal,
            'name' => $item->getName() ?? '',
            'quantity' => $qty,
        ];
    }
}
