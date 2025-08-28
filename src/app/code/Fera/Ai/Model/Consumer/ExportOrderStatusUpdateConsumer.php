<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\HTTP\Client\Curl as Curl;
use Psr\Log\LoggerInterface;

class ExportOrderStatusUpdateConsumer
{
    /**
     * @var ShipmentRepositoryInterface
     */
    private $shipmentRepository;

    /**
     * @var FeraHelper
     */
    private $helper;

    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param FeraHelper $helper
     * @param Curl $curl
     * @param LoggerInterface $logger
     */
    public function __construct(
        ShipmentRepositoryInterface $shipmentRepository,
        FeraHelper $helper,
        Curl $curl,
        LoggerInterface $logger
    ) {
        $this->shipmentRepository = $shipmentRepository;
        $this->helper = $helper;
        $this->curl = $curl;
        $this->logger = $logger;
    }

    /**
     * Process order status update message
     *
     * @param int $shipmentId
     * @return void
     */
    public function process($shipmentId)
    {
        try {
            if (!$this->helper->isEnabled()) {
                return;
            }

            $shipment = $this->shipmentRepository->get($shipmentId);
            if (!$shipment || !$shipment->getId()) {
                $this->logger->warning('Fera AI: Shipment not found for status update', ['shipment_id' => $shipmentId]);
                return;
            }

            $orderId = $shipment->getOrder()->getId();
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
     * @param array $data
     * @return void
     */
    private function updateOrderStatus($data)
    {
        $url = $this->helper->getApiUrl() . "v3/private/orders/" . $data['external_id'] . "/fulfill";
        $this->curl->addHeader("Content-Type", "application/json");
        $this->curl->addHeader("SECRET-KEY", $this->helper->getSecretKey());
        $this->curl->setOption(CURLOPT_CUSTOMREQUEST, "PUT");
        $this->curl->post($url, $this->helper->jsonEncode($data));
        $response = $this->curl->getBody();
        // Log response if needed
        // $this->logger->info("Fera AI: Order status update response", ['response' => $response]);
    }
}
