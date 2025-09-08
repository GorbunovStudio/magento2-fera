<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Sales\Api\OrderRepositoryInterface;
use Fera\Ai\Services\OrderExporter;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\Message\OrderExportMessage;
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

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderExporter $orderExporter,
        FeraHelper $helper,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderExporter = $orderExporter;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    /**
     * Process order export message
     *
     * @param OrderExportMessage $message
     */
    public function process(OrderExportMessage $message): void
    {
        $orderId = $message->getOrderId();
        $storeId = $message->getStoreId();
        
        if ($orderId === null || $storeId === null) {
            $this->logger->warning("Invalid order export message: orderId={$orderId}, storeId={$storeId}");
            return;
        }
        
        try {
            if (!$this->helper->isEnabled($storeId)) {
                return;
            }

            $order = $this->orderRepository->get($orderId);

            $this->orderExporter->pushOrder($order, $storeId);

            $this->logger->info("Successfully exported order: {$orderId} for store: {$storeId}");
        } catch (\Throwable $e) {
            $this->logger->error(
                "Failed to export order: {$orderId} for store: {$storeId}. Error: {$e->getMessage()}",
                ['exception' => $e]
            );
        }
    }
}
