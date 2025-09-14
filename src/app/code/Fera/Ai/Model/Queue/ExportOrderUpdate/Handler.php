<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\ExportOrderUpdate;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\OrderExportManager;
use Fera\Ai\Services\OrderUpdater;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Handler
{
    private OrderRepositoryInterface $orderRepository;
    private FeraHelper $helper;
    private LoggerInterface $logger;
    private OrderExportManager $orderExportManager;
    private OrderUpdater $orderUpdater;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        FeraHelper $helper,
        LoggerInterface $logger,
        OrderExportManager $orderExportManager,
        OrderUpdater $orderUpdater
    ) {
        $this->orderRepository = $orderRepository;
        $this->helper = $helper;
        $this->logger = $logger;
        $this->orderExportManager = $orderExportManager;
        $this->orderUpdater = $orderUpdater;
    }

    public function process(int $orderId): void
    {
        try {
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
        } catch (\Throwable $exception) {
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
            if ($this->orderRepository instanceof OrderRepository) {
                // Reset the repository state to avoid stale data issues
                $this->orderRepository->_resetState();
            }
        }
    }
}
