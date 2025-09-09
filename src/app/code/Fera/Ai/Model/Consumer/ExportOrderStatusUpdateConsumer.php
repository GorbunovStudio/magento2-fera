<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Sales\Api\ShipmentRepositoryInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Api\Data\OrderStatusUpdateMessageDataInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\App\ResourceConnection;
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
    /** @var ResourceConnection */
    private $resourceConnection;

    public function __construct(
        ShipmentRepositoryInterface $shipmentRepository,
        FeraHelper $helper,
        CurlFactory $curlFactory,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection
    ) {
        $this->shipmentRepository = $shipmentRepository;
        $this->helper = $helper;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Process order status update message
     *
     * @param OrderStatusUpdateMessageDataInterface $message
     */
    public function process(OrderStatusUpdateMessageDataInterface $message): void
    {
        $shipmentId = $message->getShipmentId();
        $storeId = $message->getStoreId();
        
        if ($shipmentId === null || $storeId === null) {
            $this->logger->warning("Invalid order status update message: shipmentId={$shipmentId}, storeId={$storeId}");
            return;
        }

        $dbConnection = $this->resourceConnection->getConnection();
        $dbConnection->beginTransaction();

        try {
            if (!$this->helper->isEnabled($storeId)) {
                $dbConnection->rollBack();
                return;
            }

            /** @var \Magento\Sales\Api\Data\ShipmentInterface $shipment */
            $shipment = $this->shipmentRepository->get($shipmentId);
            if (!$shipment->getId()) {
                $this->logger->warning("Shipment not found for status update: {$shipmentId} (store: {$storeId})");
                $dbConnection->rollBack();
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

            $dbConnection->commit();
            $this->logger->info("Successfully updated order status: {$orderId} (shipment: {$shipmentId}, store: {$storeId})");
        } catch (\Throwable $exception) {
            $dbConnection->rollBack();

            $this->logger->error(
                $exception->getMessage(),
                ['exception' => $exception, 'trace' => $exception->getTrace()]
            );
            
            throw new \RuntimeException(
                'Unable to process the queue message: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
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
