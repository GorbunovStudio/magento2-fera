<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Api\ApiClient\CustomersClientInterface;
use Fera\Ai\Api\ApiClient\OrdersClientInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Sales\Api\Data\OrderInterface;
use RuntimeException;

/**
 * @phpstan-import-type FeraOrder from OrdersClientInterface
 * @phpstan-import-type FeraCustomerData from CustomersClientInterface
 * @phpstan-import-type FeraPersistedCustomerData from CustomersClientInterface
 */
class OrderUpdater implements ResetAfterRequestInterface
{
    public function __construct(
        private FeraHelper $helper,
        private EventManager $eventManager,
        private DataObjectFactory $dataObjectFactory,
        private OrderDataBuilder $orderDataBuilder,
        private OrdersClientInterface $ordersClient,
        private CustomersClientInterface $customersClient
    ) {
    }
    
    public function update(OrderInterface $order, string $feraId): void
    {
        $storeId = (int) $order->getStoreId();
        $orderId = $order->getEntityId();
        if ($orderId === null) {
            throw new RuntimeException('Order does not have an entity ID');
        }

        $orderData = $this->orderDataBuilder->buildOrderData($order, [], false);

        $orderData = $this->enrichOrderData($order, $orderData);

        $this->helper->debug(
            "Order {$orderId}: Sending update to Fera"
        );

        $responseData = $this->sendUpdate($orderData, $feraId, $storeId);
        
        // Check if we need to sync customer data
        if (isset($responseData['customer_id'])) {
            $this->syncCustomerIfNeeded($orderData, $responseData['customer_id'], $feraId, $storeId);
        }
    }

    public function _resetState(): void
    {
        $this->orderDataBuilder->_resetState();
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
     * @phpstan-return FeraOrder
     */
    private function sendUpdate(array $data, string $feraId, int $storeId): array
    {
        $response = $this->ordersClient->update($feraId, $data, $storeId);

        $this->helper->debug("Successfully updated order {$feraId} in Fera API.");
        return $response;
    }

    /**
     * Sync customer data if needed
     *
     * @param array $orderData
     * @phpstan-param FeraOrder $orderData
     * @param string $customerId
     * @param string $feraOrderId
     * @param int $storeId
     */
    private function syncCustomerIfNeeded(array $orderData, string $customerId, string $feraOrderId, int $storeId): void
    {
        if (!isset($orderData['customer'])) {
            $this->helper->debug("Customer {$customerId}: No customer data in order, skipping sync");
            return;
        }
        
        $localCustomerData = $orderData['customer'];
        $remoteCustomerData = $this->fetchCustomer($customerId, $storeId);
        
        $localEmail = strtolower(trim($localCustomerData['email']));
        $remoteEmail = strtolower(trim($remoteCustomerData['email']));
        
        if ($localEmail === $remoteEmail) {
            if ($this->needsCustomerUpdate($remoteCustomerData, $localCustomerData)) {
                $this->helper->debug("Customer {$customerId}: Non-email data differs, sending update");
                $this->updateCustomer($customerId, $localCustomerData, $storeId);
            } else {
                $this->helper->debug("Customer {$customerId}: Data is up to date, skipping update");
            }
        } else {
            $this->handleEmailChange($localCustomerData, $remoteCustomerData, $customerId, $feraOrderId, $storeId);
        }
    }

    /**
     * Handle customer email change scenarios
     *
     * @param array $localCustomerData
     * @phpstan-param FeraCustomerData $localCustomerData
     * @param array $remoteCustomerData
     * @phpstan-param FeraPersistedCustomerData $remoteCustomerData
     * @param string $currentCustomerId
     * @param string $feraOrderId
     * @param int $storeId
     */
    private function handleEmailChange(
        array $localCustomerData,
        array $remoteCustomerData,
        string $currentCustomerId,
        string $feraOrderId,
        int $storeId
    ): void {
        $localEmail = $localCustomerData['email'];
        $this->helper->debug(
            "Customer {$currentCustomerId}: Email changed to {$localEmail}, searching for existing customer"
        );
        
        $existingFeraCustomer = $this->customersClient->findByEmail($localEmail, $storeId);
        
        if ($existingFeraCustomer) {
            $existingCustomerId = $existingFeraCustomer['id'];
            $this->helper->debug(
                "Customer {$currentCustomerId}: Found existing customer {$existingCustomerId} with email {$localEmail}"
            );
            
            if ($this->needsCustomerUpdate($existingFeraCustomer, $localCustomerData)) {
                $this->helper->debug("Customer {$existingCustomerId}: Updating existing customer data");
                $this->updateCustomer($existingCustomerId, $localCustomerData, $storeId);
            }
            
            if ($currentCustomerId !== $existingCustomerId) {
                $this->reassignOrderCustomer($feraOrderId, $existingCustomerId, $storeId);
            }
        } else {
            $localExternalId = $localCustomerData['external_id'] ?? null;
            $remoteExternalId = $remoteCustomerData['external_id'] ?? null;
            
            if ($localExternalId !== null && $localExternalId === $remoteExternalId) {
                $this->helper->debug(
                    "Customer {$currentCustomerId}: External IDs match, updating current customer with new email"
                );
                $this->updateCustomer($currentCustomerId, $localCustomerData, $storeId);
            } else {
                $this->helper->debug("Customer {$currentCustomerId}: External IDs don't match, creating new customer");
                $newCustomerId = $this->customersClient->create($localCustomerData, $storeId);
                $this->reassignOrderCustomer($feraOrderId, $newCustomerId, $storeId);
            }
        }
    }

    /**
     * Fetch customer data from Fera API
     *
     * @param string $customerId
     * @param int $storeId
     * @return array
     * @phpstan-return FeraPersistedCustomerData
     * @throws \Fera\Ai\Exception\FeraApiException
     */
    private function fetchCustomer(string $customerId, int $storeId): array
    {
        return $this->customersClient->get($customerId, $storeId);
    }

    /**
     * Check if customer data needs to be updated
     *
     * @param array $remote
     * @phpstan-param FeraPersistedCustomerData $remote
     * @param array $local
     * @phpstan-param FeraCustomerData $local
     * @return bool
     */
    private function needsCustomerUpdate(array $remote, array $local): bool
    {
        $remoteExternalId = $remote['external_id'] ?? null;
        $localExternalId = $local['external_id'] ?? null;
        if ($remoteExternalId !== $localExternalId) {
            return true;
        }
        
        $remoteName = trim($remote['name']);
        $localName = trim($local['name']);
        if ($remoteName !== $localName) {
            return true;
        }
        
        $remoteEmail = strtolower(trim($remote['email']));
        $localEmail = strtolower(trim($local['email']));
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
        $this->customersClient->update($customerId, $customerData, $storeId);
        $this->helper->debug("Successfully updated customer {$customerId} in Fera API.");
    }

    private function reassignOrderCustomer(string $feraOrderId, string $newCustomerId, int $storeId): void
    {
        $this->ordersClient->update($feraOrderId, ['customer_id' => $newCustomerId], $storeId);
        $this->helper->debug("Successfully reassigned order {$feraOrderId} to customer {$newCustomerId}");
    }
}
