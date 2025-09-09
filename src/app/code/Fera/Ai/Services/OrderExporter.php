<?php

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Directory\Helper\Data as DirectoryHelperData;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\DataObjectFactory;

class OrderExporter
{
    protected $_storeManager;
    protected $_curlFactory;
    protected $helper;
    protected $directoryHelper;
    protected $eventManager;
    protected $dataObjectFactory;

    public function __construct(
        FeraHelper $helper,
        CurlFactory $curlFactory,
        StoreManagerInterface $storeManager,
        DirectoryHelperData $directoryHelper,
        EventManager $eventManager,
        DataObjectFactory $dataObjectFactory
    ) {
        $this->helper = $helper;
        $this->_curlFactory = $curlFactory;
        $this->_storeManager = $storeManager;
        $this->directoryHelper = $directoryHelper;
        $this->eventManager = $eventManager;
        $this->dataObjectFactory = $dataObjectFactory;
    }

    /**
     * Build payload and push order to Fera API
     *
     * @param Order $order
     */
    public function pushOrder(Order $order)
    {
        $storeId = $order->getStoreId();
        
        if (!$this->helper->isEnabled($storeId)) {
            return;
        }

        $store = $this->_storeManager->getStore($storeId);
        $currencyCode = $store->getCurrentCurrencyCode();
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
            $orderData['shipping_address'] = $this->getBillingData($order);
            $orderData['phone_number'] = $order->getBillingAddress()->getTelephone();
        }

        $payload = $this->dataObjectFactory->create(['data' => $orderData]);
        $this->eventManager->dispatch('fera_export_order_data_ready', [
            'order' => $order,
            'orderData' => $payload,
        ]);

        $this->send($payload->getData(), $storeId);
    }

    protected function send(array $data, $storeId = null)
    {
        $url = $this->helper->getApiUrl($storeId) . 'v3/private/orders.json';
        $curl = $this->_curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('SECRET-KEY', $this->helper->getSecretKey($storeId));
        $curl->post($url, $this->helper->jsonEncode($data));
        $curl->getBody();
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
