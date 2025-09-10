<?php

namespace Fera\Ai\Observer;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Interfaces\MessageTopicInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
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
     * Publish order to message queue for order status completion.
     *
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order) {
            return;
        }

        if (!$order instanceof Order) {
            $type = is_object($order) ? get_class($order) : gettype($order);
            throw new UnexpectedValueException(
                'Incorrect type for Order: expected ' . Order::class . ', got ' . $type
            );
        }

        if (!$this->helper->isEnabled($order->getStoreId())) {
            return;
        }

        if ($this->hasOrderBecomeComplete($order)) {
            $this->publisher->publish(MessageTopicInterface::EXPORT_ORDER_STATUS_UPDATE, (int)$order->getId());
        }
    }

    /**
     * @param Order $order
     * @return bool
     */
    private function hasOrderBecomeComplete(Order $order): bool
    {
        $currentState = $order->getState();
        $originalState = $order->getOrigData('state');
        
        return $currentState === Order::STATE_COMPLETE && 
               $originalState !== Order::STATE_COMPLETE;
    }
}
