<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Sales\Api\ShipmentRepositoryInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\Message\OrderStatusUpdateMessage;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

class ExportOrderStatusUpdateConsumer
{
    /** @var ShipmentRepositoryInterface */
    private $shipmentRepository;
    /** @var FeraHelper */
    private $helper;
    /** @var CurlFactory */
    private $curlFactory;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ShipmentRepositoryInterface $shipmentRepository,
        FeraHelper $helper,
        CurlFactory $curlFactory,
        LoggerInterface $logger
    ) {
        $this->shipmentRepository = $shipmentRepository;
        $this->helper = $helper;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
    }

    /**
     * Process order status update message
     *
     * @param OrderStatusUpdateMessage $message
     */
    public function process(OrderStatusUpdateMessage $message): void
    {
        $shipmentId = $message->getShipmentId();
        $storeId = $message->getStoreId();
        
        try {
            if (!$this->helper->isEnabled($storeId)) {
                return;
            }

            /** @var \Magento\Sales\Api\Data\ShipmentInterface $shipment */
            $shipment = $this->shipmentRepository->get($shipmentId);
            if (!$shipment->getId()) {
                $this->logger->warning("Shipment not found for status update: {$shipmentId} (store: {$storeId})");
                return;
            }

            /** @var \Magento\Sales\Api\Data\OrderInterface $order */
            $order = $shipment->getOrder();
            $orderId = $order->getId();
            $shipmentData = [
                'fulfilled_at' => $this->helper->formatDate($shipment->getCreatedAt()),
                'external_id' => $orderId,
            ];

            $this->updateOrderStatus($shipmentData, $storeId);
            
            $this->logger->info("Successfully updated order status: {$orderId} (shipment: {$shipmentId}, store: {$storeId})");
        } catch (\Throwable $e) {
            $this->logger->error(
                "Failed to update order status for shipment: {$shipmentId} (store: {$storeId}). Error: {$e->getMessage()}",
                ['exception' => $e]
            );
        }
    }

    private function updateOrderStatus(array $data, int $storeId): void
    {
        $url = $this->helper->getApiUrl($storeId) . "v3/private/orders/" . $data['external_id'] . "/fulfill";
        $curl = $this->curlFactory->create();
        $curl->addHeader("Content-Type", "application/json");
        $curl->addHeader("SECRET-KEY", $this->helper->getSecretKey($storeId));
        $curl->setOption(CURLOPT_CUSTOMREQUEST, "PUT");
        $curl->post($url, $this->helper->jsonEncode($data));
        $response = $curl->getBody();
    }
}
