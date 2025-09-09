<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Sales\Api\OrderRepositoryInterface;
use Fera\Ai\Services\OrderExporter;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Api\Data\OrderExportMessageDataInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class ExportOrderConsumer
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;
    /** @var OrderExporter */
    private $orderExporter;
    /** @var FeraHelper */
    private $helper;
    /** @var LoggerInterface */
    private $logger;
    /** @var ResourceConnection */
    private $resourceConnection;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderExporter $orderExporter,
        FeraHelper $helper,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderExporter = $orderExporter;
        $this->helper = $helper;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Process order export message
     *
     * @param OrderExportMessageDataInterface $message
     */
    public function process(OrderExportMessageDataInterface $message): void
    {
        $orderId = $message->getOrderId();
        $storeId = $message->getStoreId();
        
        if ($orderId === null || $storeId === null) {
            $this->logger->warning("Invalid order export message: orderId={$orderId}, storeId={$storeId}");
            return;
        }

        $dbConnection = $this->resourceConnection->getConnection();
        $dbConnection->beginTransaction();

        try {
            if (!$this->helper->isEnabled($storeId)) {
                $dbConnection->rollBack();
                return;
            }

            $order = $this->orderRepository->get($orderId);

            $this->orderExporter->pushOrder($order, $storeId);

            $dbConnection->commit();
            $this->logger->info("Successfully exported order: {$orderId} for store: {$storeId}");
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
}
