<?php

namespace Fera\Ai\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Interface\MessageTopicInterface;
use Psr\Log\LoggerInterface;

class ProductPushEvent implements ObserverInterface
{
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
     * @param FeraHelper $helper
     * @param PublisherInterface $publisher
     * @param LoggerInterface $logger
     */
    public function __construct(
        FeraHelper $helper,
        PublisherInterface $publisher,
        LoggerInterface $logger
    ) {
        $this->helper = $helper;
        $this->publisher = $publisher;
        $this->logger = $logger;
    }

    /**
     * Publish product ID to message queue for export
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) {
            return;
        }

        $productId = (int)$product->getId();
        if ($productId <= 0) {
            return;
        }

        try {
            $this->publisher->publish(MessageTopicInterface::EXPORT_PRODUCT, $productId);
            
            $this->logger->info('Fera AI: Product export message published', ['product_id' => $productId]);
        } catch (\Exception $e) {
            $this->logger->error('Fera AI: Failed to publish product export message', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
