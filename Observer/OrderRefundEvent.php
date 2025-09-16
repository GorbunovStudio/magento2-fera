<?php

declare(strict_types=1);

namespace Fera\Ai\Observer;

use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

class OrderRefundEvent implements ObserverInterface
{
    public function __construct(
        private FeraHelper $helper,
        private PublisherInterface $publisher,
        private LoggerInterface $logger,
        private State $state
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $event = $observer->getEvent();
            
            $creditmemo = $event->getCreditmemo();
            if (!$creditmemo instanceof Creditmemo) {
                throw new UnexpectedValueException(
                    'Incorrect type for Order, expected ' . Creditmemo::class . ', got ' . get_debug_type($creditmemo)
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
        } catch (\Throwable $exception) {
            // Do not rethrow in prod mode to avoid blocking normal operations
            if ($this->state->getMode() === State::MODE_DEVELOPER) {
                throw $exception;
            }
            
            $this->logger->error(
                'Failed to publish order update message for refund: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }
    }
}
