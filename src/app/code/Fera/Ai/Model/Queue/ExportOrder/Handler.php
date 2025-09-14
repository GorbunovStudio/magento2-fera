<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\ExportOrder;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\OrderExportManager;
use Fera\Ai\Services\OrderExporter;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Handler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private OrderExporter $orderExporter,
        private FeraHelper $helper,
        private LoggerInterface $logger,
        private OrderExportManager $orderExportManager
    ) {
    }

    public function process(int $orderId): void
    {
        try {
            $order = $this->orderRepository->get($orderId);

            $storeId = $order->getStoreId();

            if (!$this->helper->isEnabled($storeId)) {
                return;
            }

            $orderId = (int)$order->getEntityId();
            if ($this->orderExportManager->isExported($orderId)) {
                throw new RuntimeException('Order ' . $orderId . ' has already been exported to Fera.');
            }

            $this->orderExporter->pushOrder($order);
        } catch (\Throwable $exception) {
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
            if ($this->orderRepository instanceof OrderRepository) {
                // Reset the repository state to avoid stale data issues
                $this->orderRepository->_resetState();
            }
        }
    }
}
