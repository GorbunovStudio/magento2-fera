<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\ExportOrderUpdate;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Order;
use Fera\Ai\Services\OrderUpdater;
use Fera\Ai\Services\OrderExporter;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\OrderExportManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

class Handler
{
    private OrderRepositoryInterface $orderRepository;
    private FeraHelper $helper;
    private LoggerInterface $logger;
    private OrderExportManager $orderExportManager;
    private OrderExporter $orderExporter;
    private OrderUpdater $orderUpdater;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        FeraHelper $helper,
        LoggerInterface $logger,
        OrderExportManager $orderExportManager,
        OrderExporter $orderExporter,
        OrderUpdater $orderUpdater
    ) {
        $this->orderRepository = $orderRepository;
        $this->helper = $helper;
        $this->logger = $logger;
        $this->orderExportManager = $orderExportManager;
        $this->orderExporter = $orderExporter;
        $this->orderUpdater = $orderUpdater;
    }

    /**
     * Process order update message
     *
     * @param int $orderId
     */
    public function process(int $orderId): void
    {
        try {
            $order = $this->orderRepository->get($orderId);
            if (!$order instanceof Order) {
                throw new UnexpectedValueException(
                    'Incorrect type for Order, expected ' . Order::class . ', got ' . get_debug_type($order)
                );
            }

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
