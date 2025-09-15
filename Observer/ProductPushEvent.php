<?php

declare(strict_types=1);

namespace Fera\Ai\Observer;

use Fera\Ai\Api\Data\Queue\ExportProduct\MessageInterfaceFactory;
use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Services\StoreGroupService;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

class ProductPushEvent implements ObserverInterface
{
    public function __construct(
        private BundleType $bundleType,
        private FeraHelper $helper,
        private PublisherInterface $publisher,
        private LoggerInterface $logger,
        private MessageInterfaceFactory $messageDataFactory,
        private StoreGroupService $storeGroupService
    ) {
    }

    public function execute(Observer $observer)
    {
        $product = $observer->getEvent()->getProduct();

        try {
            if (!$product instanceof Product) {
                throw new UnexpectedValueException(
                    'Incorrect type for Product, expected ' . Product::class . ', got ' . get_debug_type($product)
                );
            }

            $productId = (int)$product->getId();

            if ((int)$product->getStatus() !== Status::STATUS_ENABLED) {
                return;
            }

            // Skip simple products that are part of bundle products
            if ($product->getTypeId() === 'simple') {
                $parentIds = $this->bundleType->getParentIdsByChild($product->getId());
                if (!empty($parentIds)) {
                    return;
                }
            }

            $storeIds = $this->getAffectedStoreIds($product);
            
            foreach ($storeIds as $storeId) {
                if (!$this->helper->isEnabled($storeId)) {
                    continue;
                }
                
                $message = $this->messageDataFactory->create();
                $message->setProductId($productId);
                $message->setStoreId($storeId);
                
                $this->publisher->publish(TopicInterface::EXPORT_PRODUCT, $message);
            }
        } catch (\Throwable $e) {
            $productIdStr = isset($productId) ? (string)$productId : 'unknown';
            $this->logger->error(
                "Failed to publish product export messages: {$productIdStr}. Error: {$e->getMessage()}",
                [
                'exception' => $e
                ]
            );

            throw $e;
        }
    }

    /**
     * Get store IDs that are affected by the current product save operation
     * This method checks the product's store ID to determine the scope
     *
     * @param Product $product
     * @return int[]
     */
    private function getAffectedStoreIds($product): array
    {
        $productStoreId = $product->getStoreId();
        $mainStoresMap = $this->storeGroupService->getStoresToMainStoresMap();
        if ($productStoreId !== 0) {
            $mappedStoreId = $mainStoresMap[$productStoreId] ?? null;
            
            if ($mappedStoreId === null) {
                $this->helper->debug("Fera is not configured for store: {$productStoreId}");
                return [];
            }

            return [$mappedStoreId];
        }

        $result = [];
        $productStoreIds = $product->getStoreIds();

        foreach ($productStoreIds as $productStoreId) {
            $mappedStoreId = $mainStoresMap[(int) $productStoreId] ?? null;

            if ($mappedStoreId === null) {
                continue;
            }

            $result[$mappedStoreId] = true;
        }

        return array_keys($result);
    }
}
