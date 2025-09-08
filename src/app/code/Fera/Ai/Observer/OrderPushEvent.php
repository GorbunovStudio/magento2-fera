<?php

namespace Fera\Ai\Observer;

use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
use UnexpectedValueException;
use Fera\Ai\Interface\MessageTopicInterface;

class OrderPushEvent implements ObserverInterface
{
    /** @var Order[] */
    private $newOrders = [];
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
     * Publish order ID to message queue for export using two-event pattern.
     *
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $eventName = (string)$event->getName();

        if ($eventName === 'sales_order_place_after') {
            /** @var Order|null $order */
            $order = $event->getData('order');
            if ($order instanceof Order) {
                $this->newOrders[] = $order;
            }
            return;
        }

        /** @var mixed $order */
        $order = $event->getOrder();
        if (!$order instanceof Order) {
            throw new UnexpectedValueException(
                'Incorrect type for Order, expected ' . Order::class . ', got ' . get_debug_type($order)
            );
        }

        $idx = array_search($order, $this->newOrders, true);
        if ($idx === false) {
            return;
        }

        unset($this->newOrders[$idx]);

        if (!$this->helper->isEnabled()) {
            return;
        }

        $orderId = (int)$order->getId();
        if ($orderId <= 0) {
            return;
        }

        $this->publisher->publish(MessageTopicInterface::EXPORT_ORDER, $orderId);
    }
}
