<?php

declare(strict_types=1);

namespace Fera\Ai\Observer;

use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\FeraOrderFulfillmentFactory;
use Fera\Ai\Model\ResourceModel\FeraOrderFulfillment as FulfillmentResource;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

class OrderPushEvent implements ObserverInterface
{
    /** @var Order[] */
    private array $newOrders = [];
    
    public function __construct(
        private FeraHelper $helper,
        private PublisherInterface $publisher,
        private LoggerInterface $logger,
        private State $state,
        private FeraOrderFulfillmentFactory $fulfillmentFactory,
        private FulfillmentResource $fulfillmentResource,
        private DateTime $dateTime
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
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
                $this->recordPendingFulfillment($orderId, (int)$order->getStoreId());
                return;
            }
        } catch (\Throwable $exception) {
            // Do not rethrow in prod mode to avoid blocking order placement
            if ($this->state->getMode() === State::MODE_DEVELOPER) {
                throw $exception;
            }
            
            $this->logger->error(
                'Failed to publish order export message for new order: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
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

    private function recordPendingFulfillment(int $orderId, int $storeId): void
    {
        $fulfillment = $this->fulfillmentFactory->create();
        $this->fulfillmentResource->load($fulfillment, $orderId, 'order_id');
        
        if (!$fulfillment->getId()) {
            $fulfillment->setOrderId($orderId);
            $fulfillment->setStoreId($storeId);
            $fulfillment->setCompletedAt($this->dateTime->gmtDate());
        } elseif ($fulfillment->getCompletedAt() === null) {
            $fulfillment->setCompletedAt($this->dateTime->gmtDate());
        }
        
        $this->fulfillmentResource->save($fulfillment);
    }
}
