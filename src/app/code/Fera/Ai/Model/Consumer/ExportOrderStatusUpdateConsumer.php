<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

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
    /** @var ResourceConnection */
    private $resourceConnection;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        FeraHelper $helper,
        CurlFactory $curlFactory,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection
    ) {
        $this->orderRepository = $orderRepository;
        $this->helper = $helper;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Process order status update message
     *
     * @param int $orderId
     */
    public function process(int $orderId): void
    {
        if ($orderId <= 0) {
            $this->logger->warning("Invalid order ID: {$orderId}");
            return;
        }

        $dbConnection = $this->resourceConnection->getConnection();
        $dbConnection->beginTransaction();

        try {
            $order = $this->orderRepository->get($orderId);
            $storeId = $order->getStoreId();

            if (!$this->helper->isEnabled($storeId)) {
                $dbConnection->rollBack();

                return;
            }

            if ($order->getState() !== Order::STATE_COMPLETE) {
                $dbConnection->rollBack();

                return;
            }

            $orderData = [
                'fulfilled_at' => $this->helper->formatDate($order->getUpdatedAt()),
                'external_id' => $orderId,
            ];

            $this->updateOrderStatus($orderData, $storeId);

            $dbConnection->commit();
        } catch (\Throwable $exception) {
            $dbConnection->rollBack();

            $this->logger->error(
                $exception->getMessage(),
                ['exception' => $exception, 'trace' => $exception->getTrace()]
            );
            
            throw new \RuntimeException(
                'Unable to process the queue message: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
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
