<?php

namespace Fera\Ai\Observer\Frontend;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Fera\Ai\Helper\Data as FeraHelper;

class OrderPushEvent implements ObserverInterface
{
    protected $helper;
    protected $publisher;

    /**
     * Order push event constructor.
     * @param FeraHelper $helper
     * @param PublisherInterface $publisher
     */
    public function __construct(
        FeraHelper $helper,
        PublisherInterface $publisher
    ) {
        $this->helper = $helper;
        $this->publisher = $publisher;
    }

    /**
     * Publish order ID to message queue for export
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getId()) {
            return;
        }

        // Publish order ID to the queue
        $this->publisher->publish('fera.export.order', $order->getId());

        return;
    }
}
