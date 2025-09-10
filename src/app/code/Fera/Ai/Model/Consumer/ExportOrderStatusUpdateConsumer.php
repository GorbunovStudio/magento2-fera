<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Order;
use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ExportOrderStatusUpdateConsumer
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;
    /** @var FeraHelper */
    private $helper;
    /** @var CurlFactory */
    private $curlFactory;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        FeraHelper $helper,
        CurlFactory $curlFactory,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->helper = $helper;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
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

            $orderData = [
                'fulfilled_at' => $this->helper->formatDate($order->getUpdatedAt()),
                'external_id' => $orderId,
            ];

            $this->updateOrderStatus($orderData, $storeId);
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

    private function updateOrderStatus(array $data, int $storeId): void
    {
        $url = $this->helper->getApiUrl($storeId) . "v3/private/orders/" . $data['external_id'] . "/fulfill";
        $curl = $this->curlFactory->create();
        $curl->addHeader("Content-Type", "application/json");
        $curl->addHeader("SECRET-KEY", $this->helper->getSecretKey($storeId));
        $curl->setOption(CURLOPT_CUSTOMREQUEST, "PUT");
        $curl->post($url, $this->helper->jsonEncode($data));
        $response = $curl->getBody();
    }
}
