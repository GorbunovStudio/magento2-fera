<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Exception\FeraApiException;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Services\ApiClient;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Api\Data\OrderInterface;
use RuntimeException;

/**
 * @phpstan-import-type FeraOrder from OrderDataBuilder
 * @phpstan-import-type FeraCustomerData from OrderDataBuilder
 *
 * @phpstan-type FeraOrderResponse array{customer_id?: string}
 */
class OrderUpdater
{
    protected FeraHelper $helper;
    protected EventManager $eventManager;
    protected DataObjectFactory $dataObjectFactory;
    private OrderDataBuilder $orderDataBuilder;
    private ApiClient $apiClient;

    public function __construct(
        FeraHelper $helper,
        EventManager $eventManager,
        DataObjectFactory $dataObjectFactory,
        OrderDataBuilder $orderDataBuilder,
        ApiClient $apiClient
    ) {
        $this->helper = $helper;
        $this->eventManager = $eventManager;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->orderDataBuilder = $orderDataBuilder;
        $this->apiClient = $apiClient;
    }
    
    public function update(OrderInterface $order, string $feraId): void
    {
        $storeId = (int) $order->getStoreId();
        $orderId = $order->getEntityId();
        if ($orderId === null) {
            throw new RuntimeException('Order does not have an entity ID');
        }

        $orderData = $this->orderDataBuilder->buildOrderData($order);

        $orderData = $this->enrichOrderData($order, $orderData);

        $this->helper->debug(
            "Order {$orderId}: Sending update to Fera"
        );

        $responseData = $this->sendUpdate($orderData, $feraId, $storeId);
        
        // Check if we need to sync customer data
        if (isset($responseData['customer_id'])) {
            $this->syncCustomerIfNeeded($orderData, $responseData['customer_id'], $storeId);
        }
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $data
     * @phpstan-param FeraOrder $data
     * @return array
     * @phpstan-return FeraOrder
     */
    private function enrichOrderData(OrderInterface $order, array $data): array
    {
        $payload = $this->dataObjectFactory->create(['data' => $data]);
        $this->eventManager->dispatch('fera_export_order_data_ready', [
            'order' => $order,
            'orderData' => $payload,
        ]);

        /** @phpstan-var FeraOrder $result */
        $result = $payload->getData();

        return $result;
    }

    /**
     * Send PUT request to Fera API
     *
     * @param array $data
     * @phpstan-param FeraOrder $data
     * @param string $feraId
     * @param int $storeId
     * @return array
     * @phpstan-return FeraOrderResponse
     */
    private function sendUpdate(array $data, string $feraId, int $storeId): array
    {
        /** @var FeraOrderResponse $response */
        $response = $this->apiClient->put('v3/private/orders/' . $feraId, $data, $storeId);

        $this->helper->debug("Successfully updated order {$feraId} in Fera API.");
        return $response;
    }

    /**
     * Sync customer data if needed
     *
     * @param array $orderData
     * @phpstan-param FeraOrder $orderData
     * @param string $customerId
     * @param int $storeId
     */
    private function syncCustomerIfNeeded(array $orderData, string $customerId, int $storeId): void
    {
        if (!isset($orderData['customer'])) {
            $this->helper->debug("Customer {$customerId}: No customer data in order, skipping sync");
            return;
        }
        
        $localCustomerData = $orderData['customer'];
        $remoteCustomerData = $this->fetchCustomer($customerId, $storeId);
        
        if ($this->needsCustomerUpdate($remoteCustomerData, $localCustomerData)) {
            $this->helper->debug("Customer {$customerId}: Data differs, sending update");
            $this->updateCustomer($customerId, $localCustomerData, $storeId);
        } else {
            $this->helper->debug("Customer {$customerId}: Data is up to date, skipping update");
        }
    }

    /**
     * Fetch customer data from Fera API
     *
     * @param string $customerId
     * @param int $storeId
     * @return array
     * @phpstan-return FeraCustomerData
     * @throws \Fera\Ai\Exception\FeraApiException
     */
    private function fetchCustomer(string $customerId, int $storeId): array
    {
        $decoded = $this->apiClient->get('v3/private/customers/' . $customerId, $storeId);

        if (!isset($decoded['name']) || !is_string($decoded['name']) || !isset($decoded['email']) || !is_string($decoded['email'])) {
            throw new FeraApiException(sprintf(
                'Invalid customer data received from Fera API for customer %s',
                $customerId
            ));
        }

        $result = [
            'name' => $decoded['name'],
            'email' => $decoded['email'],
        ];

        if (isset($decoded['phone_number']) && is_string($decoded['phone_number'])) {
            $result['phone_number'] = $decoded['phone_number'];
        }
        
        if (isset($decoded['external_id']) && $decoded['external_id'] !== null) {
            $result['external_id'] = (int) $decoded['external_id'];
        }
        
        return $result;
    }

    /**
     * Check if customer data needs to be updated
     *
     * @param array $remote
     * @phpstan-param FeraCustomerData $remote
     * @param array $local
     * @phpstan-param FeraCustomerData $local
     * @return bool
     */
    private function needsCustomerUpdate(array $remote, array $local): bool
    {
        if (($remote['external_id'] ?? null) !== ($local['external_id'] ?? null)) {
            return true;
        }
        
        $remoteName = trim((string) $remote['name']);
        $localName = trim((string) $local['name']);
        if ($remoteName !== $localName) {
            return true;
        }
        
        $remoteEmail = strtolower(trim((string) $remote['email']));
        $localEmail = strtolower(trim((string) $local['email']));
        if ($remoteEmail !== $localEmail) {
            return true;
        }

        $remotePhone = trim($remote['phone_number'] ?? '');
        $localPhone = trim($local['phone_number'] ?? '');
        if ($remotePhone !== $localPhone) {
            return true;
        }
        
        return false;
    }

    /**
     * Update customer data in Fera API
     *
     * @param string $customerId
     * @param array $customerData
     * @phpstan-param FeraCustomerData $customerData
     * @param int $storeId
     */
    private function updateCustomer(string $customerId, array $customerData, int $storeId): void
    {
        $this->apiClient->put('v3/private/customers/' . $customerId, $customerData, $storeId);
        $this->helper->debug("Successfully updated customer {$customerId} in Fera API.");
    }
}
