<?php

namespace Fera\Ai\Model\Queue\ExportOrderStatusUpdate;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Order;
use Magento\Framework\HTTP\Client\CurlFactory;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\OrderExportManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Handler
{
    private OrderRepositoryInterface $orderRepository;
    private FeraHelper $helper;
    private CurlFactory $curlFactory;
    private LoggerInterface $logger;
    private OrderExportManager $orderExportManager;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        FeraHelper $helper,
        CurlFactory $curlFactory,
        LoggerInterface $logger,
        OrderExportManager $orderExportManager
    ) {
        $this->orderRepository = $orderRepository;
        $this->helper = $helper;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
        $this->orderExportManager = $orderExportManager;
    }

    /**
     * Process order status update message
     *
     * @param int $orderId
     */
    public function process(int $orderId): void
    {
        try {
            $order = $this->orderRepository->get($orderId);

            $storeId = $order->getStoreId();

            if (!$this->helper->isEnabled($storeId)) {
                return;
            }

            if ($order->getState() !== Order::STATE_COMPLETE) {
                throw new RuntimeException("Order {$orderId} is not complete. Current state: {$order->getState()}");
            }

            $feraId = $this->orderExportManager->getFeraId($orderId);
            $orderData = [
                'fulfilled_at' => $this->helper->formatDate($order->getUpdatedAt()),
                'external_id' => $orderId,
            ];

            $this->updateOrderStatus($orderData, $storeId, $feraId);
        } catch (\Throwable $exception) {
            $this->logger->error(
                $exception->getMessage(),
                ['exception' => $exception, 'trace' => $exception->getTrace()]
            );
            
            throw new RuntimeException(
                'Unable to process the queue message: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        } finally {
            if (!$this->orderRepository instanceof OrderRepository) {
                $type = is_object($this->orderRepository) ? get_class($this->orderRepository) : gettype($this->orderRepository);
                throw new RuntimeException(
                    'Incorrect type for OrderRepository, expected ' . OrderRepositoryInterface::class . ', got ' . $type
                );
            }

            // Reset the repository state to avoid stale data issues
            $this->orderRepository->_resetState();
        }
    }

    private function updateOrderStatus(array $data, int $storeId, ?string $feraId = null): void
    {
        if ($feraId) {
            $url = $this->helper->getApiUrl($storeId) . 'v3/private/orders/' . $feraId . '/fulfill';
        } else {
            $url = $this->helper->getApiUrl($storeId) . 'v3/private/orders/' . $data['external_id'] . '/fulfill';
        }
        $curl = $this->curlFactory->create();
        $curl->addHeader("Content-Type", "application/json");
        $curl->addHeader("SECRET-KEY", $this->helper->getSecretKey($storeId));
        $curl->setOption(CURLOPT_CUSTOMREQUEST, "PUT");
        $curl->post($url, $this->helper->jsonEncode($data));
        $response = $curl->getBody();
    }
}
