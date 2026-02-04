<?php

declare(strict_types=1);

namespace Fera\Ai\Cron;

use Fera\Ai\Api\Data\FeraOrderFulfillmentInterface;
use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\ResourceModel\FeraOrderFulfillment\CollectionFactory;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class EnqueueFulfillmentExports
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private StoreManagerInterface $storeManager,
        private FeraHelper $helper,
        private CollectionFactory $collectionFactory,
        private PublisherInterface $publisher,
        private LoggerInterface $logger
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
                $this->logger->info("Enqueued {$processedCount} fulfillment export(s)");
            }
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Failed to enqueue fulfillment exports: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }
    }

    private function processStoreOrders(int $storeId, int $delayDays): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraOrderFulfillmentInterface::STORE_ID, $storeId)
            ->addFieldToFilter(FeraOrderFulfillmentInterface::COMPLETED_AT, ['notnull' => true])
            ->addFieldToFilter(FeraOrderFulfillmentInterface::EXPORTED_AT, ['null' => true])
            ->setPageSize(self::BATCH_SIZE);

        if ($delayDays > 0) {
            $thresholdDate = date('Y-m-d H:i:s', strtotime("-{$delayDays} days"));
            $collection->addFieldToFilter(
                FeraOrderFulfillmentInterface::COMPLETED_AT,
                ['lteq' => $thresholdDate]
            );
        }

        $count = 0;
        foreach ($collection as $fulfillment) {
            $orderId = $fulfillment->getOrderId();
            $this->publisher->publish(TopicInterface::EXPORT_ORDER_FULFILLMENT, $orderId);
            $count++;
        }

        return $count;
    }
}
