<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Shipment;
use Fera\Ai\Helper\Data as FeraHelper;
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
     * @param int $shipmentId
     */
    public function process(int $shipmentId): void
    {
        if ($shipmentId <= 0) {
            $this->logger->warning("Invalid shipment ID: {$shipmentId}");
            return;
        }

        $dbConnection = $this->resourceConnection->getConnection();
        $dbConnection->beginTransaction();

        try {
            $shipment = $this->shipmentRepository->get($shipmentId);
            if (!$shipment instanceof Shipment) {
                $type = is_object($shipment) ? get_class($shipment) : gettype($shipment);
                throw new \UnexpectedValueException(
                    'Incorrect type for Shipment: expected ' . Shipment::class . ', got ' . $type
                );
            }

            $order = $shipment->getOrder();
            $storeId = $order->getStoreId();

            if (!$this->helper->isEnabled($storeId)) {
                $dbConnection->rollBack();
                return;
            }

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
