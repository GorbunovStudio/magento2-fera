<?php

namespace Fera\Ai\Observer\Frontend;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order as Order;
use Magento\Checkout\Model\Session as CheckoutSession;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Services\OrderExporter;

class OrderPushEvent implements ObserverInterface
{
    protected $helper;
    protected $order;
    protected $_checkoutSession;
    protected $orderExporter;

    /**
     * Sales order constructor.
     * @param FeraHelper $helper
     * @param Order $order
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        FeraHelper $helper,
        Order $order,
        CheckoutSession $checkoutSession,
        OrderExporter $orderExporter
    ) {
        $this->helper = $helper;
        $this->order = $order;
        $this->_checkoutSession = $checkoutSession;
        $this->orderExporter = $orderExporter;
    }

    /**
     * get orders data and and send request
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        $orderId = $this->_checkoutSession->getLastOrderId();
        if (!$orderId) {
            return;
        }

        $order = $this->order->load($orderId);
        if (!$order || !$order->getId()) {
            return;
        }

        $this->orderExporter->pushOrder($order);

        return;
    }
}
