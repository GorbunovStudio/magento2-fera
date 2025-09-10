<?php

namespace Fera\Ai\Observer;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Interfaces\MessageTopicInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order\Shipment;
use UnexpectedValueException;

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
        $shipment = $observer->getEvent()->getShipment();
        if (!$shipment) {
            return;
        }

        if (!$shipment instanceof Shipment) {
            $type = is_object($shipment) ? get_class($shipment) : gettype($shipment);
            throw new UnexpectedValueException(
                'Incorrect type for Shipment: expected ' . Shipment::class . ', got ' . $type
            );
        }

        if (!$this->helper->isEnabled($shipment->getStoreId())) {
            return;
        }
        
        $this->publisher->publish(MessageTopicInterface::EXPORT_ORDER_STATUS_UPDATE, (int)$shipment->getId());
    }
}
