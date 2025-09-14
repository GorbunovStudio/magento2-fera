<?php

declare(strict_types=1);

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\FeraOrderInterface;
use Fera\Ai\Model\FeraOrderFactory;
use Fera\Ai\Model\ResourceModel\FeraOrder as FeraOrderResource;
use Fera\Ai\Model\ResourceModel\FeraOrder\CollectionFactory as FeraOrderCollectionFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use UnexpectedValueException;

class OrderExportManager
{
    public function __construct(
        private FeraOrderResource $resource,
        private FeraOrderFactory $factory,
        private FeraOrderCollectionFactory $collectionFactory,
        private DateTime $dateTime
    ) {
    }

    public function isExported(int $orderId): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraOrderInterface::ORDER_ID, (string) $orderId);
        $collection->setPageSize(1);
        return (bool) $collection->getSize();
    }

    public function getFeraId(int $orderId): ?string
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraOrderInterface::ORDER_ID, (string) $orderId);
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        if (!$item->getId()) {
            return null;
        }
        return $item->getFeraId();
    }

    public function saveSuccessfulExport(OrderInterface $order, string $feraId): void
    {
        if (!$order->getEntityId()) {
            throw new UnexpectedValueException(
                'Incorrect type for Order ID: expected int, got ' . get_debug_type($order->getEntityId())
            );
        }

        $model = $this->factory->create();
        $model->setOrderId((int) $order->getEntityId());
        $model->setFeraId($feraId);
        $model->setExportedAt($this->dateTime->gmtDate());
        $this->resource->save($model);
    }

    /**
     * Get exported order IDs from a list of order IDs
     *
     * @param int[] $orderIds
     * @return int[]
     */
    public function getExportedOrderIds(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraOrderInterface::ORDER_ID, ['in' => $orderIds]);
        $collection->addFieldToSelect(FeraOrderInterface::ORDER_ID);

        $exportedOrderIds = [];
        foreach ($collection->getItems() as $item) {
            $exportedOrderIds[] = (int) $item->getOrderId();
        }

        return $exportedOrderIds;
    }
}
