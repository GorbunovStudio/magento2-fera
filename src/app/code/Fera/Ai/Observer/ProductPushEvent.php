<?php

namespace Fera\Ai\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Store\Model\StoreManagerInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Interfaces\MessageTopicInterface;
use Fera\Ai\Api\Data\ProductExportMessageDataInterfaceFactory;
use Magento\Catalog\Model\Product;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

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
     * @var ProductExportMessageDataInterfaceFactory
     */
    private $messageDataFactory;

    /**
     * ProductPushEvent constructor.
     *
     * @param StoreManagerInterface $storeManager
     * @param FeraHelper $helper
     * @param PublisherInterface $publisher
     * @param LoggerInterface $logger
     * @param ProductExportMessageDataInterfaceFactory $messageDataFactory
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        FeraHelper $helper,
        PublisherInterface $publisher,
        LoggerInterface $logger,
        ProductExportMessageDataInterfaceFactory $messageDataFactory
    ) {
        $this->storeManager = $storeManager;
        $this->helper = $helper;
        $this->publisher = $publisher;
        $this->logger = $logger;
        $this->messageDataFactory = $messageDataFactory;
    }

    /**
     * Publish product ID to message queue for export in all relevant stores
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        $product = $observer->getEvent()->getProduct();

        if (!$product instanceof Product) {
            $type = is_object($product) ? get_class($product) : gettype($product);
            throw new UnexpectedValueException(
                'Incorrect type for Product, expected ' . Product::class . ', got ' . $type
            );
        }

        $productId = (int)$product->getId();

        try {
            $storeIds = $this->getAffectedStoreIds($product);
            
            foreach ($storeIds as $storeId) {
                if (!$this->helper->isEnabled($storeId)) {
                    continue;
                }
                
                $message = $this->messageDataFactory->create();
                $message->setProductId($productId);
                $message->setStoreId($storeId);
                
                $this->publisher->publish(MessageTopicInterface::EXPORT_PRODUCT, $message);
            }
        } catch (\Throwable $e) {
            $this->logger->error("Failed to publish product export messages: {$productId}. Error: {$e->getMessage()}", [
                'exception' => $e
            ]);

            throw $e;
        }
    }

    /**
     * Get store IDs that are affected by the current product save operation
     * This method checks the product's store ID to determine the scope
     * 
     * @param Product $product
     * @return array
     */
    private function getAffectedStoreIds($product): array
    {
        $productStoreId = (int)$product->getStoreId();
        $productWebsiteIds = $product->getWebsiteIds();
        
        if ($productStoreId === 0) {
            // Global scope (All Store Views) - schedule for all stores where product is assigned
            $storeIds = [];
            $stores = $this->storeManager->getStores(true);
            
            foreach ($stores as $store) {
                $storeId = (int)$store->getId();
                $storeWebsiteId = $store->getWebsiteId();
                
                if (in_array($storeWebsiteId, $productWebsiteIds)) {
                    $storeIds[] = $storeId;
                }
            }
            
            return $storeIds;
        } else {
            // Specific store view - only schedule for this store if it's enabled and product is assigned to its website
            $store = $this->storeManager->getStore($productStoreId);
            
            if (in_array($store->getWebsiteId(), $productWebsiteIds)) {
                return [$productStoreId];
            } else {
                // Product not assigned to this store's website - no stores to update
                return [];
            }
        }
    }
}
