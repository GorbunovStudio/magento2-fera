<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\ExportOrderUpdate;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\OrderExportManager;
use Fera\Ai\Services\OrderUpdater;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Handler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private FeraHelper $helper,
        private LoggerInterface $logger,
        private OrderExportManager $orderExportManager,
        private OrderUpdater $orderUpdater,
        private ResourceConnection $resourceConnection
    ) {
    }

    public function process(int $orderId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            $this->processOrderUpdate($orderId);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            
            $this->logger->error(
                'Unable to process the queue message: ' . $exception->getMessage(),
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
            $this->orderUpdater->_resetState();
        }
    }

    private function processOrderUpdate(int $orderId): void
    {
        $order = $this->orderRepository->get($orderId);
        $storeId = (int) $order->getStoreId();

        if (!$this->helper->isEnabled($storeId)) {
            return;
        }

        $feraId = $this->orderExportManager->getFeraId($orderId);
        if (!$feraId) {
            return;
        }

        $this->orderUpdater->update($order, $feraId);
    }
}
