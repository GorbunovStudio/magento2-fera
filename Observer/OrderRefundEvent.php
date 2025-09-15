<?php

declare(strict_types=1);

namespace Fera\Ai\Observer;

use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Block\Order\Creditmemo;
use UnexpectedValueException;

class OrderRefundEvent implements ObserverInterface
{
    public function __construct(
        private FeraHelper $helper,
        private PublisherInterface $publisher
    ) {
        $this->helper = $helper;
        $this->publisher = $publisher;
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        
        $creditmemo = $event->getCreditmemo();
        if (!$creditmemo instanceof Creditmemo) {
            throw new UnexpectedValueException(
                'Incorrect type for Order, expected ' . CreditmemoInterface::class . ', got ' . get_debug_type($creditmemo)
            );
        }

        $order = $creditmemo->getOrder();

        if (!$this->helper->isEnabled((int) $order->getStoreId())) {
            return;
        }

        $orderId = $order->getId();
        if (!is_numeric($orderId)) {
            throw new UnexpectedValueException(
                'Incorrect type for Order ID: expected int, got ' . get_debug_type($orderId)
            );
        }
        $orderId = (int)$orderId;

        $this->publisher->publish(TopicInterface::EXPORT_ORDER_UPDATE, $orderId);
        
        $this->helper->debug("Published order update for order {$orderId}");
    }
}
