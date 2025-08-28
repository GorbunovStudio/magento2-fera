<?php

namespace Fera\Ai\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Fera\Ai\Helper\Data as FeraHelper;

class OrderFulfilledStatusUpdate implements ObserverInterface
{
    protected $helper;
    protected $publisher;

    /**
     * Order fulfilled status update constructor.
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
     * Publish shipment ID to message queue for order status update
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        $shipment = $observer->getEvent()->getShipment();
        if (!$shipment || !$shipment->getId()) {
            return;
        }

        // Publish shipment ID to the queue
        $this->publisher->publish('fera.export.order.status.update', $shipment->getId());

        return;
    }
}
