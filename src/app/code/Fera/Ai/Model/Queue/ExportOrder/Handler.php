<?php

namespace Fera\Ai\Model\Queue\ExportOrder;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderRepository;
use Fera\Ai\Services\OrderExporter;
use Fera\Ai\Helper\Data as FeraHelper;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Handler
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
            $order = $this->orderRepository->get($orderId);

            $storeId = $order->getStoreId();

            if (!$this->helper->isEnabled($storeId)) {
                return;
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
            if (!$this->orderRepository instanceof OrderRepository) {
                $type = is_object($this->orderRepository) ? get_class($this->orderRepository) : gettype($this->orderRepository);
                throw new RuntimeException(
                    'Incorrect type for OrderRepository, expected ' . OrderRepositoryInterface::class . ', got ' . $type
                );
            }

            // Reset the repository state to avoid stale data issues
            $this->orderRepository->_resetState();
        }
    }
}
