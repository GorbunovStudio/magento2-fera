<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\ExportOrderFulfillment;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\OrderExportManager;
use Fera\Ai\Services\ApiClient;
use Fera\Ai\Services\OrderExporter;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class Handler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private FeraHelper $helper,
        private LoggerInterface $logger,
        private OrderExportManager $orderExportManager,
        private OrderExporter $orderExporter,
        private ApiClient $apiClient
    ) {
    }

    public function process(int $orderId): void
    {
        try {
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
                $feraId = $this->orderExporter->pushOrder($order);
                if (!$feraId) {
                    $this->helper->debug('Order export skipped for order ' . $orderId . ', skipping status update');
                    return;
                }
            }

            $orderData = [
                'fulfilled_at' => $this->helper->formatDate($order->getUpdatedAt() ?? ''),
                'external_id' => $orderId,
            ];

            $this->updateOrderStatus($orderData, $storeId, $feraId);
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

    /**
     * @param array{fulfilled_at:string,external_id:int} $data
     * @param int $storeId
     * @param string $feraId
     */
    private function updateOrderStatus(array $data, int $storeId, string $feraId): void
    {
        $this->apiClient->put('v3/private/orders/' . $feraId . '/fulfill', $data, $storeId);
    }
}
