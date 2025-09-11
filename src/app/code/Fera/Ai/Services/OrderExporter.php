<?php

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Sales\Model\Order;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Directory\Helper\Data as DirectoryHelperData;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\DataObjectFactory;
use Magento\Sales\Model\Order\Address;
use Fera\Ai\Model\OrderExportManager;
use RuntimeException;
use UnexpectedValueException;

class OrderExporter
{
    protected $_storeManager;
    protected $_curlFactory;
    protected $helper;
    protected $directoryHelper;
    protected $eventManager;
    protected $dataObjectFactory;
    private $orderExportManager;

    public function __construct(
        FeraHelper $helper,
        CurlFactory $curlFactory,
        StoreManagerInterface $storeManager,
        DirectoryHelperData $directoryHelper,
        EventManager $eventManager,
        DataObjectFactory $dataObjectFactory,
        OrderExportManager $orderExportManager
    ) {
        $this->helper = $helper;
        $this->_curlFactory = $curlFactory;
        $this->_storeManager = $storeManager;
        $this->directoryHelper = $directoryHelper;
        $this->eventManager = $eventManager;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->orderExportManager = $orderExportManager;
    }

    /**
     * Build payload and push order to Fera API
     *
     * @param Order $order
     */
    public function pushOrder(Order $order)
    {
        $storeId = $order->getStoreId();

        $store = $this->_storeManager->getStore($storeId);
        if (!$store instanceof Store) {
            $type = is_object($store) ? get_class($store) : gettype($store);
            throw new UnexpectedValueException(
                'Incorrect type for Store: expected ' . Store::class . ', got ' . $type
            );
        }

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
            $orderData['billing_address'] = $this->getBillingData($order);
            $orderData['phone_number'] = $order->getBillingAddress()->getTelephone();
        }

        $payload = $this->dataObjectFactory->create(['data' => $orderData]);
        $this->eventManager->dispatch('fera_export_order_data_ready', [
            'order' => $order,
            'orderData' => $payload,
        ]);

        $feraId = $this->send($payload->getData(), (int)$storeId);

        if (!is_string($feraId) || $feraId === '') {
            throw new RuntimeException(sprintf('Invalid Fera ID received for order %d', (int)$order->getId()));
        }

        $this->orderExportManager->saveSuccessfulExport($order, $feraId);
    }

    protected function send(array $data, ?int $storeId = null)
    {
        $url = $this->helper->getApiUrl($storeId) . 'v3/private/orders.json';
        $curl = $this->_curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('SECRET-KEY', $this->helper->getSecretKey($storeId));
        $curl->post($url, $this->helper->jsonEncode($data));
        $response = $curl->getBody();
        $httpCode = (int)$curl->getStatus();

        if (!in_array($httpCode, [200, 201], true)) {
            throw new RuntimeException(sprintf(
                'Failed to create order %d in Fera API. HTTP Status: %d, Response: %s',
                (int)($data['external_id'] ?? 0),
                $httpCode,
                (string)$response
            ));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Invalid JSON from Fera API when creating order %d', (int)($data['external_id'] ?? 0)));
        }

        $feraId = $decoded['id'] ?? ($decoded['data']['id'] ?? null);
        if (!is_string($feraId)) {
            throw new RuntimeException(sprintf('Fera ID is missing in API response for order %d', (int)($data['external_id'] ?? 0)));
        }

        return $feraId;
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

        if (!$billingAddress instanceof Address) {
            $type = is_object($billingAddress) ? get_class($billingAddress) : gettype($billingAddress);
            throw new UnexpectedValueException(
                'Incorrect type for Billing Address, expected ' . Address::class . ', got ' . $type
            );
        }

        return [
            'name' => $billingAddress->getData('firstname') . ' ' . $billingAddress->getData('lastname'),
            'address1' => $billingAddress->getData('street'),
            'city_name' => $billingAddress->getData('city'),
            'region_name' => $billingAddress->getData('region'),
            'zip_code' => $billingAddress->getData('postcode'),
        ];
    }
}
