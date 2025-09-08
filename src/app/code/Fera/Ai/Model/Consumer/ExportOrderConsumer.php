<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Sales\Api\OrderRepositoryInterface;
use Fera\Ai\Services\OrderExporter;
use Fera\Ai\Helper\Data as FeraHelper;
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
     * @param int $orderId
     */
    public function process(int $orderId): void
    {
        try {
            if (!$this->helper->isEnabled()) {
                return;
            }

            $order = $this->orderRepository->get($orderId);

            $this->orderExporter->pushOrder($order);

            $this->logger->info("Successfully exported order: {$orderId}");
        } catch (\Throwable $e) {
            $this->logger->error(
                "Failed to export order: {$orderId}. Error: {$e->getMessage()}",
                ['exception' => $e]
            );
        }
    }
}
