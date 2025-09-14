<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\ProcessCustomerUpdate;

use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\OrderExportManager;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

class Handler
{
    protected const PAGE_SIZE = 100;

    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private SearchCriteriaBuilder $searchCriteriaBuilder,
        private OrderExportManager $orderExportManager,
        private FeraHelper $helper,
        private PublisherInterface $publisher,
        private LoggerInterface $logger,
        private ResourceConnection $resourceConnection
    ) {
    }

    public function process(int $customerId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            $this->processCustomerOrderUpdates($customerId);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            
            $this->logger->error(
                "Customer {$customerId}: Failed to process customer update: " . $exception->getMessage(),
                [
                    'exception' => $exception,
                    'customer_id' => $customerId,
                ]
            );

            throw new RuntimeException(
                "Failed to process customer update for customer {$customerId}: " . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }
    }

    private function processCustomerOrderUpdates(int $customerId): void
    {
        $currentPage = 1;

        do {
            $orders = $this->getOrdersPage($customerId, $currentPage);
            $orderCount = count($orders);

            if ($orderCount === 0) {
                break;
            }

            $orderIds = array_map(static function (OrderInterface $order): int {
                $orderId = $order->getEntityId();
                if (!is_numeric($orderId)) {
                    throw new UnexpectedValueException(
                        'Order entity ID is not numeric: ' . get_debug_type($orderId)
                    );
                }
                return (int) $orderId;
            }, $orders);

            $exportedOrderIds = $this->orderExportManager->getExportedOrderIds($orderIds);
            $exportedOrderIdsSet = array_flip($exportedOrderIds);

            foreach ($orders as $order) {
                $orderId = (int) $order->getEntityId();
                $storeId = (int) $order->getStoreId();

                if (!isset($exportedOrderIdsSet[$orderId])) {
                    continue;
                }

                if (!$this->helper->isEnabled($storeId)) {
                    continue;
                }

                $this->publisher->publish(TopicInterface::EXPORT_ORDER_UPDATE, $orderId);
            }

            $currentPage++;
        } while ($orderCount === static::PAGE_SIZE);
    }

    /**
     * Get orders for a customer with pagination
     *
     * @param int $customerId
     * @param int $currentPage
     * @return \Magento\Sales\Api\Data\OrderInterface[]
     */
    private function getOrdersPage(int $customerId, int $currentPage): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('customer_id', $customerId)
            ->setPageSize(static::PAGE_SIZE)
            ->setCurrentPage($currentPage)
            ->create();

        $searchResult = $this->orderRepository->getList($searchCriteria);
        return $searchResult->getItems();
    }
}
