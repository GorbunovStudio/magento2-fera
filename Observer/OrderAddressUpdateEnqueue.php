<?php

declare(strict_types=1);

namespace Fera\Ai\Observer;

use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Address;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

class OrderAddressUpdateEnqueue implements ObserverInterface
{
    public function __construct(
        private PublisherInterface $publisher,
        private LoggerInterface $logger,
        private FeraHelper $helper,
        private OrderRepositoryInterface $orderRepository
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $address = $observer->getEvent()->getDataObject();
            if (!$address instanceof Address) {
                throw new UnexpectedValueException(
                    'Incorrect type for OrderAddress: expected ' . Address::class .
                    ', got ' . get_debug_type($address)
                );
            }

            // Skip if this is initial address creation during order placement
            if ($this->isInitialCreation($address)) {
                return;
            }

            $orderId = $this->getOrderId($address);
            if ($orderId === null) {
                return;
            }

            if (!$this->hasRelevantChanges($address)) {
                return;
            }

            $order = $this->orderRepository->get($orderId);
            $storeId = (int) $order->getStoreId();

            if (!$this->helper->isEnabled($storeId)) {
                return;
            }

            // Skip address updates if data sharing is minimized since we don't send addresses anyway
            if ($this->helper->isMinimizeDataSharingEnabled($storeId)) {
                return;
            }

            $this->publisher->publish(TopicInterface::EXPORT_ORDER_UPDATE, $orderId);

            $addressType = $this->getAddressTypeLabel($address);
            $this->logger->debug(
                "Order {$orderId}: Published order update message due to {$addressType} address changes"
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Failed to publish order update message for address change: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
            // Do not rethrow to avoid blocking address save
        }
    }

    private function isInitialCreation(Address $address): bool
    {
        return $address->isObjectNew();
    }

    private function getOrderId(Address $address): ?int
    {
        $parentId = $address->getParentId();
        if (!is_numeric($parentId)) {
            return null;
        }

        return (int) $parentId;
    }

    private function hasRelevantChanges(Address $address): bool
    {
        $fieldsToCheck = [
            'firstname',
            'lastname',
            'company',
            'city',
            'region',
            'postcode',
            'country_id',
            'telephone'
        ];

        foreach ($fieldsToCheck as $field) {
            if ($this->hasFieldChanged($address, $field)) {
                return true;
            }
        }

        if ($this->hasStreetChanged($address)) {
            return true;
        }

        return false;
    }

    private function hasFieldChanged(Address $address, string $field): bool
    {
        $original = $address->getOrigData($field);
        $current = $address->getData($field);

        // Normalize strings by trimming
        if (is_string($original)) {
            $original = trim($original);
        }
        if (is_string($current)) {
            $current = trim($current);
        }

        return $original !== $current;
    }

    private function hasStreetChanged(Address $address): bool
    {
        $originalStreet = $address->getOrigData('street');
        if (!is_string($originalStreet) && !is_array($originalStreet)) {
            $originalStreet = '';
        }

        if (!is_array($originalStreet)) {
            $originalStreet = explode(PHP_EOL, $originalStreet);
        }

        $currentStreet = $address->getStreet();

        // Normalize both arrays by trimming each line
        $originalStreet = array_map('trim', $originalStreet);
        $currentStreet = array_map('trim', $currentStreet);

        return $originalStreet !== $currentStreet;
    }

    private function getAddressTypeLabel(Address $address): string
    {
        $addressType = $address->getAddressType();
        return $addressType ?: 'unknown';
    }
}
