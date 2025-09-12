<?php

namespace Fera\Ai\Model\Queue\ExportOrderStatusUpdate;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Order;
use Magento\Framework\HTTP\Client\CurlFactory;
use Fera\Ai\Services\OrderExporter;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\OrderExportManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

class Handler
{
    private OrderRepositoryInterface $orderRepository;
    private FeraHelper $helper;
    private CurlFactory $curlFactory;
    private LoggerInterface $logger;
    private OrderExportManager $orderExportManager;
    private OrderExporter $orderExporter;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        FeraHelper $helper,
        CurlFactory $curlFactory,
        LoggerInterface $logger,
        OrderExportManager $orderExportManager,
        OrderExporter $orderExporter
    ) {
        $this->orderRepository = $orderRepository;
        $this->helper = $helper;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
        $this->orderExportManager = $orderExportManager;
        $this->orderExporter = $orderExporter;
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
            if (!$order instanceof Order) {
                throw new UnexpectedValueException(
                    'Incorrect type for Order, expected ' . Order::class . ', got ' . get_class($order)
                );
            }

            $storeId = (int) $order->getStoreId();

            if (!$this->helper->isEnabled($storeId)) {
                return;
            }

            if ($order->getState() !== Order::STATE_COMPLETE) {
                throw new RuntimeException("Order {$orderId} is not complete. Current state: {$order->getState()}");
            }

            $feraId = $this->orderExportManager->getFeraId($orderId);
            if (!$feraId) {
                $feraId = $this->orderExporter->pushOrder($order);
                if (!$feraId) {
                    $this->helper->debug('Order export skipped for order ' . $orderId . ', skipping status update');
                    return;
                }
            }

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
            if ($this->orderRepository instanceof OrderRepository) {
                // Reset the repository state to avoid stale data issues
                $this->orderRepository->_resetState();
            }
        }
    }

    /**
     * @param array{fulfilled_at:string,external_id:int} $data
     * @param int $storeId
     * @param string $feraId
     */
    private function updateOrderStatus(array $data, int $storeId, string $feraId): void
    {
        $url = $this->helper->getApiUrl($storeId) . 'v3/private/orders/' . $feraId . '/fulfill';
        $curl = $this->curlFactory->create();
        $curl->addHeader("Content-Type", "application/json");
        $curl->addHeader("SECRET-KEY", $this->helper->getSecretKey($storeId));
        $curl->setOption(CURLOPT_CUSTOMREQUEST, "PUT");
        $curl->post($url, $this->helper->jsonEncode($data));
        $response = $curl->getBody();
    }
}
