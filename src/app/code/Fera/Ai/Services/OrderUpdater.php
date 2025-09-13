<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Sales\Model\Order;
use RuntimeException;

/**
 * @phpstan-import-type FeraOrder from OrderDataBuilder
 * @phpstan-import-type FeraCustomerData from OrderDataBuilder
 *
 * @phpstan-type FeraOrderResponse array{customer_id?: string}
 */
class OrderUpdater
{
    protected CurlFactory $curlFactory;
    protected FeraHelper $helper;
    protected EventManager $eventManager;
    protected DataObjectFactory $dataObjectFactory;
    private OrderDataBuilder $orderDataBuilder;

    public function __construct(
        FeraHelper $helper,
        CurlFactory $curlFactory,
        EventManager $eventManager,
        DataObjectFactory $dataObjectFactory,
        OrderDataBuilder $orderDataBuilder
    ) {
        $this->helper = $helper;
        $this->curlFactory = $curlFactory;
        $this->eventManager = $eventManager;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->orderDataBuilder = $orderDataBuilder;
    }
    
    public function update(Order $order, string $feraId): void
    {
        $storeId = (int) $order->getStoreId();
        $orderId = $order->getEntityId();
        if (!is_numeric($orderId)) {
            throw new RuntimeException('Order entity ID is not numeric: ' . get_debug_type($orderId));
        }

        $orderData = $this->orderDataBuilder->buildOrderData($order);

        $payload = $this->dataObjectFactory->create(['data' => $orderData]);
        $this->eventManager->dispatch('fera_export_order_data_ready', [
            'order' => $order,
            'orderData' => $payload,
        ]);

        /** @phpstan-var FeraOrder $orderData */
        $orderData = $payload->getData();

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
        $url = $this->helper->getApiUrl($storeId) . 'v3/private/orders/' . $feraId;
        $curl = $this->curlFactory->create();
        
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('SECRET-KEY', $this->helper->getSecretKey($storeId));
        $curl->setOption('CUSTOMREQUEST', 'PUT');
        $curl->post($url, $this->helper->jsonEncode($data));
        
        $response = $curl->getBody();
        $httpCode = (int) $curl->getStatus();

        if (!in_array($httpCode, [200, 204], true)) {
            throw new RuntimeException(sprintf(
                'Failed to update order %s in Fera API. HTTP Status: %d, Response: %s',
                $feraId,
                $httpCode,
                (string) $response
            ));
        }

        $this->helper->debug("Successfully updated order {$feraId} in Fera API. HTTP {$httpCode}");
        
        // Parse response for 200, return empty array for 204
        if ($httpCode === 200 && $response) {
            $decoded = json_decode((string) $response, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        
        return [];
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
     */
    private function fetchCustomer(string $customerId, int $storeId): array
    {
        $url = $this->helper->getApiUrl($storeId) . 'v3/private/customers/' . $customerId;
        $curl = $this->curlFactory->create();
        
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('SECRET-KEY', $this->helper->getSecretKey($storeId));
        $curl->get($url);
        
        $response = $curl->getBody();
        $httpCode = (int) $curl->getStatus();

        if ($httpCode !== 200) {
            throw new RuntimeException(sprintf(
                'Failed to fetch customer %s from Fera API. HTTP Status: %d, Response: %s',
                $customerId,
                $httpCode,
                (string) $response
            ));
        }

        $decoded = json_decode((string) $response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'Invalid JSON response when fetching customer %s: %s',
                $customerId,
                json_last_error_msg()
            ));
        }

        $result = [
            'name' => (string) ($decoded['name'] ?? ''),
            'email' => (string) ($decoded['email'] ?? ''),
            'phone_number' => $decoded['phone_number'] ?? null,
        ];
        
        if (isset($decoded['external_id']) && $decoded['external_id'] !== null) {
            $result['external_id'] = $decoded['external_id'];
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
        $url = $this->helper->getApiUrl($storeId) . 'v3/private/customers/' . $customerId;
        $curl = $this->curlFactory->create();
        
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('SECRET-KEY', $this->helper->getSecretKey($storeId));
        $curl->setOption('CUSTOMREQUEST', 'PUT');
        $curl->post($url, $this->helper->jsonEncode($customerData));

        $response = $curl->getBody();
        $httpCode = (int) $curl->getStatus();

        if (!in_array($httpCode, [200, 204], true)) {
            throw new RuntimeException(sprintf(
                'Failed to update customer %s in Fera API. HTTP Status: %d, Response: %s',
                $customerId,
                $httpCode,
                (string) $response
            ));
        }

        $this->helper->debug("Successfully updated customer {$customerId} in Fera API. HTTP {$httpCode}");
    }
}
