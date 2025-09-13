<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Sales\Model\Order;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\DataObjectFactory;
use RuntimeException;

/**
 * @phpstan-import-type FeraOrder from OrderDataBuilder
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

        $this->sendUpdate($orderData, $feraId, $storeId);
    }

    /**
     * Send PUT request to Fera API
     *
     * @param array $data
     * @phpstan-param FeraOrder $data
     * @param string $feraId
     * @param int $storeId
     */
    private function sendUpdate(array $data, string $feraId, int $storeId): void
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
    }
}
