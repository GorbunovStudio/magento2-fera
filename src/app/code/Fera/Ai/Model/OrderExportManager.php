<?php

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\FeraOrderInterface;
use Fera\Ai\Model\ResourceModel\FeraOrder as FeraOrderResource;
use Fera\Ai\Model\ResourceModel\FeraOrder\CollectionFactory as FeraOrderCollectionFactory;
use Fera\Ai\Model\FeraOrderFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\Order;
use UnexpectedValueException;

class OrderExportManager
{
    private FeraOrderResource $resource;
    private FeraOrderFactory $factory;
    private FeraOrderCollectionFactory $collectionFactory;
    private DateTime $dateTime;

    public function __construct(
        FeraOrderResource $resource,
        FeraOrderFactory $factory,
        FeraOrderCollectionFactory $collectionFactory,
        DateTime $dateTime
    ) {
        $this->resource = $resource;
        $this->factory = $factory;
        $this->collectionFactory = $collectionFactory;
        $this->dateTime = $dateTime;
    }

    public function isExported(int $orderId): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraOrderInterface::ORDER_ID, $orderId);
        $collection->setPageSize(1);
        return (bool) $collection->getSize();
    }

    public function getFeraId(int $orderId): ?string
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraOrderInterface::ORDER_ID, $orderId);
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        if (!$item || !$item->getId()) {
            return null;
        }
        return $item->getFeraId();
    }

    public function saveSuccessfulExport(Order $order, string $feraId): void
    {
        if (!$order->getId()) {
            throw new UnexpectedValueException('Incorrect type for Order ID: expected int, got ' . gettype($order->getId()));
        }

        $model = $this->factory->create();
        $model->setOrderId((int) $order->getId());
        $model->setFeraId($feraId);
        $model->setExportedAt($this->dateTime->gmtDate());
        $this->resource->save($model);
    }
}
