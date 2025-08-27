<?php

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\HTTP\Client\Curl as Curl;
use Magento\Sales\Model\Order as Order;
use Magento\Store\Model\StoreManagerInterface as StoreManager;
use Magento\Directory\Helper\Data as DirectoryHelperData;

class OrderExporter
{
    protected $_storeManager;
    protected $_curl;
    protected $helper;
    protected $directoryHelper;

    public function __construct(
        FeraHelper $helper,
        Curl $curl,
        StoreManager $storeManager,
        DirectoryHelperData $directoryHelper
    ) {
        $this->helper = $helper;
        $this->_curl = $curl;
        $this->_storeManager = $storeManager;
        $this->directoryHelper = $directoryHelper;
    }

    /**
     * Build payload and push order to Fera API
     */
    public function pushOrder(Order $order)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        $currencyCode = $this->_storeManager->getStore()->getCurrentCurrencyCode();
        $total = $order->getGrandTotal();
        $totalUsd = $this->directoryHelper->currencyConvert($total, $currencyCode, 'USD');

        $orderData = [
            'external_id' => $order->getId(),
            'number' => $order->getIncrementId(),
            'total' => $total,
            'total_usd' => $totalUsd,
            'external_created_at' => $this->helper->formatDate($order->getCreatedAt()),
            'external_updated_at' => $this->helper->formatDate($order->getUpdatedAt()),
            'line_items' => $this->helper->serializeQuoteItems($order->getAllItems()),
            'customer' => $this->getCustomerData($order),
            'external_customer_id' => $order->getCustomerId(),
            'tags' => [],
            'source_name' => 'web',
        ];

        if (!empty($order->getShippingAddress())) {
            $orderData['shipping_address'] = $this->getShippingData($order);
        }
        if (!empty($order->getBillingAddress())) {
            // Match original observer behavior: overwrite shipping with billing and set phone
            $orderData['shipping_address'] = $this->getBillingData($order);
            $orderData['phone_number'] = $order->getBillingAddress()->getTelephone();
        }

        $this->send($orderData);
    }

    protected function send(array $data)
    {
        $url = $this->helper->getApiUrl() . 'v3/private/orders.json';
        $this->_curl->addHeader('Content-Type', 'application/json');
        $this->_curl->addHeader('SECRET-KEY', $this->helper->getSecretKey());
        $this->_curl->post($url, $this->helper->jsonEncode($data));
        $this->_curl->getBody();
    }

    protected function getCustomerData(Order $order)
    {
        $customerId = $order->getCustomerId();
        $customerName = $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname();

        return [
            'external_id' => $customerId,
            'name' => $customerName,
            'email' => $order->getCustomerEmail(),
            'phone_number' => $order->getBillingAddress() ? $order->getBillingAddress()->getTelephone() : null,
        ];
    }

    protected function getShippingData(Order $order)
    {
        $shippingAddress = $order->getShippingAddress();
        return [
            'name' => $shippingAddress->getData('firstname') . ' ' . $shippingAddress->getData('lastname'),
            'address1' => $shippingAddress->getData('street'),
            'city_name' => $shippingAddress->getData('city'),
            'region_name' => $shippingAddress->getData('region'),
            'zip_code' => $shippingAddress->getData('postcode'),
        ];
    }

    protected function getBillingData(Order $order)
    {
        $billingAddress = $order->getBillingAddress();
        return [
            'name' => $billingAddress->getData('firstname') . ' ' . $billingAddress->getData('lastname'),
            'address1' => $billingAddress->getData('street'),
            'city_name' => $billingAddress->getData('city'),
            'region_name' => $billingAddress->getData('region'),
            'zip_code' => $billingAddress->getData('postcode'),
        ];
    }
}
