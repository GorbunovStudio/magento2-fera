<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
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

            $this->logger->info('Fera AI: Order exported successfully', ['order_id' => $orderId]);

        } catch (NoSuchEntityException $e) {
            $this->logger->error('Fera AI: Order not found for export', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
        } catch (LocalizedException $e) {
            $this->logger->error('Fera AI: Error exporting order', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Fera AI: Unexpected error exporting order', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
