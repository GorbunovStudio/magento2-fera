<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\OrderExportManager;
use Fera\Ai\Services\ApiClient;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Api\Data\OrderInterface;
use RuntimeException;

/**
 * @phpstan-import-type FeraOrder from OrderDataBuilder
 */
class OrderExporter
{
    public function __construct(
        private FeraHelper $helper,
        private EventManager $eventManager,
        private DataObjectFactory $dataObjectFactory,
        private OrderExportManager $orderExportManager,
        private OrderDataBuilder $orderDataBuilder,
        private ApiClient $apiClient
    ) {
    }

    public function pushOrder(OrderInterface $order): ?string
    {
        $storeId = (int) $order->getStoreId();
        $orderId = $order->getEntityId();
        if (!is_numeric($orderId)) {
            throw new RuntimeException('Order entity ID is not numeric: ' . get_debug_type($orderId));
        }

        $orderData = $this->orderDataBuilder->buildOrderData($order);
        if (empty($orderData['line_items'])) {
            $this->helper->debug('No line items to export for order ' . $order->getEntityId() . ', skipping export');
            return null;
        }

        $orderData = $this->enrichOrderData($order, $orderData);

        $feraId = $this->send($orderData, (int)$storeId);

        if (!is_string($feraId) || $feraId === '') {
            throw new RuntimeException(sprintf('Invalid Fera ID received for order %d', (int) $orderId));
        }

        $this->orderExportManager->saveSuccessfulExport($order, $feraId);
        
        return $feraId;
    }

    /**
     * Send order data to Fera API
     *
     * @param array $data
     * @phpstan-param FeraOrder $data
     * @param int|null $storeId
     * @return string
     * @throws \RuntimeException
     */
    protected function send(array $data, ?int $storeId = null): string
    {
        $response = $this->apiClient->post('v3/private/orders.json', $data, $storeId);

        $feraId = $response['id'] ?? null;
        if (!is_string($feraId)) {
            throw new RuntimeException(sprintf(
                'Fera ID is missing in API response for order %s',
                $data['external_id']
            ));
        }

        return $feraId;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $data
     * @phpstan-param FeraOrder $data
     * @return array
     * @phpstan-return FeraOrder
     */
    private function enrichOrderData(OrderInterface $order, array $data): array
    {
        $payload = $this->dataObjectFactory->create(['data' => $data]);
        $this->eventManager->dispatch('fera_export_order_data_ready', [
            'order' => $order,
            'orderData' => $payload,
        ]);

        /** @phpstan-var FeraOrder $result */
        $result = $payload->getData();

        return $result;
    }
}
