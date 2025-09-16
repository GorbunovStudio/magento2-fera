<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Api\ApiClient\OrdersClientInterface;
use Fera\Ai\Api\Data\Queue\ExportProduct\MessageInterfaceFactory;
use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\ProductExportManager;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * @phpstan-import-type FeraOrder from OrdersClientInterface
 *
 * Service responsible for scheduling product exports for products that don't have Fera mappings
 */
class MissingProductExportScheduler
{
    public function __construct(
        private FeraHelper $helper,
        private StoreGroupService $storeGroupService,
        private ProductExportManager $productExportManager,
        private PublisherInterface $publisher,
        private MessageInterfaceFactory $productExportMessageFactory
    ) {
    }

    /**
     * Schedule product exports for any products in order line items that don't have Fera mappings
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $orderData
     * @phpstan-param FeraOrder $orderData
     */
    public function scheduleForOrder(OrderInterface $order, array $orderData): void
    {
        $orderStoreId = (int) $order->getStoreId();
        $mainStoresMap = $this->storeGroupService->getStoresToMainStoresMap();
        
        if (!isset($mainStoresMap[$orderStoreId])) {
            $this->helper->debug("Fera is not configured for store: {$orderStoreId}");
            return;
        }

        $mainStoreId = $mainStoresMap[$orderStoreId];
        
        if (!$this->helper->isEnabled($mainStoreId)) {
            $this->helper->debug("Fera is disabled for store: {$mainStoreId}");
            return;
        }

        $productIds = [];
        foreach ($orderData['line_items'] as $lineItem) {
            $productIds[(int) $lineItem['product_id']] = true;
        }
        $productIds = array_keys($productIds);

        if (empty($productIds)) {
            return;
        }

        $existingMappings = $this->productExportManager->getFeraIdsByProductIds($productIds, $mainStoreId);
        $missingProductIds = array_diff($productIds, array_keys($existingMappings));

        if (empty($missingProductIds)) {
            $this->helper->debug('All products in order ' . $order->getEntityId() . ' already have Fera mappings');
            return;
        }

        $scheduledCount = 0;
        foreach ($missingProductIds as $productId) {
            $message = $this->productExportMessageFactory->create();
            $message->setProductId($productId);
            $message->setStoreId($mainStoreId);
            
            $this->publisher->publish(TopicInterface::EXPORT_PRODUCT, $message);
            $scheduledCount++;
        }

        $this->helper->debug(
            "Scheduled {$scheduledCount} product exports for order " . $order->getEntityId() .
            " (store {$mainStoreId}): " . implode(', ', $missingProductIds)
        );
    }
}
