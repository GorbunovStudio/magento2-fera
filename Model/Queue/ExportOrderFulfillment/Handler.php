<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\ExportOrderFulfillment;

use Fera\Ai\Api\ApiClient\OrdersClientInterface;
use Fera\Ai\Api\Data\FeraOrderFulfillmentInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\OrderExportManager;
use Fera\Ai\Model\ResourceModel\FeraOrderFulfillment as FulfillmentResource;
use Fera\Ai\Services\OrderExporter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Magento\Framework\DB\Adapter\AdapterInterface;
use UnexpectedValueException;

class Handler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private FeraHelper $helper,
        private LoggerInterface $logger,
        private OrderExportManager $orderExportManager,
        private OrderExporter $orderExporter,
        private OrdersClientInterface $ordersClient,
        private ResourceConnection $resourceConnection,
        private FulfillmentResource $fulfillmentResource,
        private DateTime $dateTime
    ) {
    }

    public function process(int $orderId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            $this->processFulfillment($orderId, $connection);
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();
            
            $this->logger->error(
                $exception->getMessage(),
                ['exception' => $exception, 'trace' => $exception->getTrace()]
            );
            
            throw new RuntimeException(
                'Unable to process the queue message: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        } finally {
            if ($this->orderRepository instanceof ResetAfterRequestInterface) {
                // Reset the repository state to avoid stale data issues
                $this->orderRepository->_resetState();
            }
            $this->orderExporter->_resetState();
        }
    }

    private function processFulfillment(int $orderId, AdapterInterface $connection): void
    {
        $order = $this->orderRepository->get($orderId);
        
        $storeId = (int) $order->getStoreId();

        if (!$this->helper->isEnabled($storeId)) {
            return;
        }

        if ($order->getState() !== Order::STATE_COMPLETE) {
            throw new UnexpectedValueException(
                "Order {$orderId} is not complete. Current state: {$order->getState()}"
            );
        }

        $feraId = $this->orderExportManager->getFeraId($orderId);
        if (!$feraId) {
            $feraId = $this->orderExporter->pushOrder($order, [], false);
            if (!$feraId) {
                $this->helper->debug('Order export skipped for order ' . $orderId . ', skipping status update');
                return;
            }
        }

        $orderData = [
            'fulfilled_at' => $this->helper->formatDate($order->getUpdatedAt() ?? ''),
            'external_id' => $orderId,
        ];

        $this->markAsExported($orderId, $connection);
        $this->updateOrderStatus($orderData, $storeId, $feraId);
    }

    /**
     * @param array{fulfilled_at:string,external_id:int} $data
     * @param int $storeId
     * @param string $feraId
     */
    private function updateOrderStatus(array $data, int $storeId, string $feraId): void
    {
        $this->ordersClient->fulfill($feraId, $data, $storeId);
    }

    private function markAsExported(int $orderId, AdapterInterface $connection): void
    {
        $tableName = $this->fulfillmentResource->getMainTable();

        $connection->update(
            $tableName,
            [
                FeraOrderFulfillmentInterface::EXPORTED_AT => $this->dateTime->gmtDate(),
            ],
            [
                FeraOrderFulfillmentInterface::ORDER_ID . ' = ?' => $orderId,
                FeraOrderFulfillmentInterface::EXPORTED_AT . ' IS NULL'
            ]
        );
    }
}
