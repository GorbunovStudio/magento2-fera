<?php

namespace Fera\Ai\Observer;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Api\Data\OrderExportMessageDataInterfaceFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
use UnexpectedValueException;
use Fera\Ai\Interfaces\MessageTopicInterface;

class OrderPushEvent implements ObserverInterface
{
    /** @var Order[] */
    private $newOrders = [];
    /** @var FeraHelper */
    private $helper;
    /** @var PublisherInterface */
    private $publisher;
    /** @var OrderExportMessageDataInterfaceFactory */
    private $messageDataFactory;

    public function __construct(
        FeraHelper $helper,
        PublisherInterface $publisher,
        OrderExportMessageDataInterfaceFactory $messageDataFactory
    ) {
        $this->helper = $helper;
        $this->publisher = $publisher;
        $this->messageDataFactory = $messageDataFactory;
    }

    /**
     * Publish order ID to message queue for export using two-event pattern.
     *
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $eventName = (string)$event->getName();

        $order = $event->getOrder();
        if (!$order instanceof Order) {
            $type = is_object($order) ? get_class($order) : gettype($order);
            throw new UnexpectedValueException(
                'Incorrect type for Order, expected ' . Order::class . ', got ' . $type
            );
        }

        if ($eventName === 'sales_order_place_after') {
            $this->newOrders[] = $order;
            
            return;
        }

        $idx = array_search($order, $this->newOrders, true);
        if ($idx === false) {
            return;
        }

        unset($this->newOrders[$idx]);

        $orderId = (int)$order->getId();
        $storeId = (int)$order->getStoreId();
        
        $message = $this->messageDataFactory->create();
        $message->setOrderId($orderId);
        $message->setStoreId($storeId);
        
        $this->publisher->publish(MessageTopicInterface::EXPORT_ORDER, $message);
    }
}
