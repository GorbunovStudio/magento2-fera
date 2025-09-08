<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

class ExportOrderStatusUpdateConsumer
{
    /** @var ShipmentRepositoryInterface */
    private $shipmentRepository;
    /** @var FeraHelper */
    private $helper;
    /** @var CurlFactory */
    private $curlFactory;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ShipmentRepositoryInterface $shipmentRepository,
        FeraHelper $helper,
        CurlFactory $curlFactory,
        LoggerInterface $logger
    ) {
        $this->shipmentRepository = $shipmentRepository;
        $this->helper = $helper;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
    }

    /**
     * Process order status update message
     *
     * @param int $shipmentId
     */
    public function process(int $shipmentId): void
    {
        try {
            if (!$this->helper->isEnabled()) {
                return;
            }

            /** @var \Magento\Sales\Api\Data\ShipmentInterface $shipment */
            $shipment = $this->shipmentRepository->get($shipmentId);
            if (!$shipment->getId()) {
                $this->logger->warning('Fera AI: Shipment not found for status update', ['shipment_id' => $shipmentId]);
                return;
            }

            /** @var \Magento\Sales\Api\Data\OrderInterface $order */
            $order = $shipment->getOrder();
            $orderId = $order->getId();
            $shipmentData = [
                'fulfilled_at' => $this->helper->formatDate($shipment->getCreatedAt()),
                'external_id' => $orderId,
            ];

            $this->updateOrderStatus($shipmentData);
            $this->logger->info('Fera AI: Order status updated successfully', [
                'order_id' => $orderId,
                'shipment_id' => $shipmentId
            ]);

        } catch (NoSuchEntityException $e) {
            $this->logger->error('Fera AI: Shipment not found for status update', [
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage()
            ]);
        } catch (LocalizedException $e) {
            $this->logger->error('Fera AI: Error updating order status', [
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Fera AI: Unexpected error updating order status', [
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Send put request to update order status
     *
     * @param array<string, mixed> $data
     * @return void
     */
    private function updateOrderStatus(array $data): void
    {
        $url = $this->helper->getApiUrl() . "v3/private/orders/" . $data['external_id'] . "/fulfill";
        $curl = $this->curlFactory->create();
        $curl->addHeader("Content-Type", "application/json");
        $curl->addHeader("SECRET-KEY", $this->helper->getSecretKey());
        $curl->setOption(CURLOPT_CUSTOMREQUEST, "PUT");
        $curl->post($url, $this->helper->jsonEncode($data));
        $response = $curl->getBody();
        // Log response if needed
        // $this->logger->info("Fera AI: Order status update response", ['response' => $response]);
    }
}
