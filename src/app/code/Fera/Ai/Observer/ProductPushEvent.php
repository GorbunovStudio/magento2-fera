<?php

namespace Fera\Ai\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Store\Model\StoreManagerInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Interfaces\MessageTopicInterface;
use Fera\Ai\Model\Message\ProductExportMessage;
use Psr\Log\LoggerInterface;

class ProductPushEvent implements ObserverInterface
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var FeraHelper
     */
    protected $helper;

    /**
     * @var PublisherInterface
     */
    private $publisher;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * ProductPushEvent constructor.
     *
     * @param StoreManagerInterface $storeManager
     * @param FeraHelper $helper
     * @param PublisherInterface $publisher
     * @param LoggerInterface $logger
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        FeraHelper $helper,
        PublisherInterface $publisher,
        LoggerInterface $logger
    ) {
        $this->storeManager = $storeManager;
        $this->helper = $helper;
        $this->publisher = $publisher;
        $this->logger = $logger;
    }

    /**
     * Publish product ID to message queue for export in all relevant stores
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) {
            return;
        }

        $productId = (int)$product->getId();
        if ($productId <= 0) {
            return;
        }

        try {
            $storeIds = $this->getRelevantStoreIds($product);
            
            foreach ($storeIds as $storeId) {
                if (!$this->helper->isEnabled($storeId)) {
                    continue;
                }
                
                $message = new ProductExportMessage($productId, $storeId);
                $this->publisher->publish(MessageTopicInterface::EXPORT_PRODUCT, $message);
                
                $this->logger->info("Product export message published: {$productId} for store: {$storeId}");
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to publish product export messages: {$productId}. Error: {$e->getMessage()}", [
                'exception' => $e
            ]);
        }
    }

    private function getRelevantStoreIds($product): array
    {
        $storeIds = [];
        
        try {
            $stores = $this->storeManager->getStores(true);
            
            foreach ($stores as $store) {
                $storeId = (int)$store->getId();
                
                if ($storeId === 0) {
                    continue;
                }
                
                $websiteIds = $product->getWebsiteIds();
                $storeWebsiteId = $store->getWebsiteId();
                
                if (in_array($storeWebsiteId, $websiteIds)) {
                    $storeIds[] = $storeId;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to get relevant store IDs for product: {$product->getId()}. Error: {$e->getMessage()}", [
                'exception' => $e
            ]);
            
            $storeIds = [$product->getStoreId() ?: 1];
        }
        
        return $storeIds;
    }
}
