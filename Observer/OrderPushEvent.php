<?php

declare(strict_types=1);

namespace Fera\Ai\Observer;

use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
use UnexpectedValueException;

class OrderPushEvent implements ObserverInterface
{
    /** @var Order[] */
    private array $newOrders = [];
    
    public function __construct(
        private FeraHelper $helper,
        private PublisherInterface $publisher
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $eventName = (string)$event->getName();

        $order = $event->getOrder();
        if (!$order instanceof Order) {
            throw new UnexpectedValueException(
                'Incorrect type for Order, expected ' . Order::class . ', got ' . get_debug_type($order)
            );
        }

        if (!$this->helper->isEnabled((int)$order->getStoreId())) {
            return;
        }

        if ($eventName === 'sales_order_place_after') {
            $this->newOrders[] = $order;
            
            return;
        }

        $orderId = $order->getId();
        if (!is_numeric($orderId)) {
            throw new UnexpectedValueException(
                'Incorrect type for Order ID: expected int, got ' . get_debug_type($orderId)
            );
        }
        $orderId = (int)$orderId;
        $idx = array_search($order, $this->newOrders, true);

        if ($idx === false && $order->getOrigData() === null) {
            // Original data is missing - order was never read from DB - likely a repeated save of a new order
            return;
        }

        if ($idx !== false) {
            unset($this->newOrders[$idx]);

            if (!$this->helper->shouldExportOrderOnCreation((int)$order->getStoreId())) {
                return;
            }

            $this->publisher->publish(TopicInterface::EXPORT_ORDER, $orderId);
        } elseif ($this->hasRelevantOrderUpdates($order)) {
            $this->publisher->publish(TopicInterface::EXPORT_ORDER_UPDATE, $orderId);
        }

        if ($this->hasOrderBecomeComplete($order)) {
            $this->publisher->publish(TopicInterface::EXPORT_ORDER_FULFILLMENT, $orderId);
            return;
        }
    }

    private function hasOrderBecomeComplete(Order $order): bool
    {
        $currentState = $order->getState();
        $originalState = $order->getOrigData('state');
        
        return $currentState === Order::STATE_COMPLETE &&
               $originalState !== Order::STATE_COMPLETE;
    }

    private function hasRelevantOrderUpdates(Order $order): bool
    {
        $currentCustomerId = $this->normalizeCustomerId($order->getCustomerId());
        $originalCustomerId = $this->normalizeCustomerId($order->getOrigData('customer_id'));
        if ($currentCustomerId !== $originalCustomerId) {
            return true;
        }

        $currentState = $order->getState();
        $originalState = $order->getOrigData('state');
        if ($currentState !== $originalState &&
            in_array($currentState, [Order::STATE_CANCELED, Order::STATE_COMPLETE], true)
        ) {
            return true;
        }

        $nameFields = ['customer_firstname', 'customer_middlename', 'customer_lastname'];
        foreach ($nameFields as $field) {
            $currentValue = $this->normalizeString($order->getData($field));
            $originalValue = $this->normalizeString($order->getOrigData($field));
            if ($currentValue !== $originalValue) {
                return true;
            }
        }

        $currentEmail = $this->normalizeString($order->getCustomerEmail());
        $originalEmail = $this->normalizeString($order->getOrigData('customer_email'));
        if ($currentEmail !== $originalEmail) {
            return true;
        }

        return false;
    }

    private function normalizeCustomerId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        if (is_numeric($value)) {
            return (int)$value;
        }
        
        return null;
    }

    private function normalizeString(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        
        return trim($value);
    }
}
