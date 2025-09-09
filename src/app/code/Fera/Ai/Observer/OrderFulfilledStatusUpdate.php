<?php

namespace Fera\Ai\Observer;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Interfaces\MessageTopicInterface;
use Fera\Ai\Api\Data\OrderStatusUpdateMessageDataInterfaceFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;

class OrderFulfilledStatusUpdate implements ObserverInterface
{
    /** @var FeraHelper */
    private $helper;
    /** @var PublisherInterface */
    private $publisher;
    /** @var OrderStatusUpdateMessageDataInterfaceFactory */
    private $messageDataFactory;

    public function __construct(
        FeraHelper $helper,
        PublisherInterface $publisher,
        OrderStatusUpdateMessageDataInterfaceFactory $messageDataFactory
    ) {
        $this->helper = $helper;
        $this->publisher = $publisher;
        $this->messageDataFactory = $messageDataFactory;
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
        if (!$shipment) {
            return;
        }

        $order = $shipment->getOrder();
        $storeId = (int)$order->getStoreId();
        $shipmentId = (int)$shipment->getId();
        
        $message = $this->messageDataFactory->create();
        $message->setShipmentId($shipmentId);
        $message->setStoreId($storeId);
        
        $this->publisher->publish(MessageTopicInterface::EXPORT_ORDER_STATUS_UPDATE, $message);
    }
}
