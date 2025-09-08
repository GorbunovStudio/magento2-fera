<?php

namespace Fera\Ai\Observer;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Interface\MessageTopicInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;

class OrderFulfilledStatusUpdate implements ObserverInterface
{
    /** @var FeraHelper */
    private $helper;
    /** @var PublisherInterface */
    private $publisher;

    public function __construct(
        FeraHelper $helper,
        PublisherInterface $publisher
    ) {
        $this->helper = $helper;
        $this->publisher = $publisher;
    }

    /**
     * Publish shipment ID to message queue for order status update.
     *
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(Observer $observer): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        $shipment = $observer->getEvent()->getShipment();
        if (!$shipment || !$shipment->getId()) {
            return;
        }

        $this->publisher->publish(MessageTopicInterface::EXPORT_ORDER_STATUS_UPDATE, (int)$shipment->getId());
    }
}
