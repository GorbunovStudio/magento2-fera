<?php

declare(strict_types=1);

namespace Fera\Ai\Cron;

use Fera\Ai\Api\Data\FeraOrderFulfillmentInterface;
use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\ResourceModel\FeraOrderFulfillment as FulfillmentResource;
use Fera\Ai\Model\ResourceModel\FeraOrderFulfillment\CollectionFactory;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class EnqueueFulfillmentExports
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private StoreManagerInterface $storeManager,
        private FeraHelper $helper,
        private CollectionFactory $collectionFactory,
        private PublisherInterface $publisher,
        private LoggerInterface $logger,
        private FulfillmentResource $fulfillmentResource,
        private DateTime $dateTime
    ) {
    }

    public function execute(): void
    {
        try {
            $processedCount = 0;

            foreach ($this->storeManager->getStores(false) as $store) {
                $storeId = (int)$store->getId();

                if (!$this->helper->isEnabled($storeId)) {
                    continue;
                }

                $delayDays = $this->helper->getFulfillmentExportDelayDays($storeId);
                $processedCount += $this->processStoreOrders($storeId, $delayDays);
            }

            if ($processedCount > 0) {
                $this->logger->debug("Enqueued {$processedCount} fulfillment export(s)");
            }
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Failed to enqueue fulfillment exports: ' . $exception->getMessage(),
                [
                    'exception' => $exception,
                    'trace' => $exception->getTraceAsString()
                ]
            );
        }
    }

    private function processStoreOrders(int $storeId, int $delayDays): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraOrderFulfillmentInterface::STORE_ID, $storeId)
            ->addFieldToFilter(FeraOrderFulfillmentInterface::COMPLETED_AT, ['notnull' => true])
            ->addFieldToFilter(FeraOrderFulfillmentInterface::EXPORTED_AT, ['null' => true])
            ->addFieldToFilter(FeraOrderFulfillmentInterface::ENQUEUED_AT, ['null' => true])
            ->setPageSize(self::BATCH_SIZE);

        if ($delayDays > 0) {
            $thresholdDate = $this->dateTime->gmtDate('Y-m-d H:i:s', strtotime("-{$delayDays} days"));
            $collection->addFieldToFilter(
                FeraOrderFulfillmentInterface::COMPLETED_AT,
                ['lteq' => $thresholdDate]
            );
        }

        $connection = $this->fulfillmentResource->getConnection();
        $tableName = $this->fulfillmentResource->getMainTable();

        $count = 0;
        foreach ($collection as $fulfillment) {
            $connection->beginTransaction();
            try {
                $orderId = $fulfillment->getOrderId();
                $connection->update(
                    $tableName,
                    [FeraOrderFulfillmentInterface::ENQUEUED_AT => $this->dateTime->gmtDate()],
                    [FeraOrderFulfillmentInterface::ORDER_ID . ' = ?' => $orderId]
                );

                $this->publisher->publish(TopicInterface::EXPORT_ORDER_FULFILLMENT, $orderId);
                $count++;
                $connection->commit();
            } catch (Throwable $exception) {
                $connection->rollBack();
                throw $exception;
            }
        }

        return $count;
    }
}
