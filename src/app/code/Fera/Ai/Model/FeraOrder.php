<?php

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\FeraOrderInterface;
use Fera\Ai\Model\ResourceModel\FeraOrder as FeraOrderResource;
use Magento\Framework\Model\AbstractModel;

class FeraOrder extends AbstractModel implements FeraOrderInterface
{
    protected function _construct(): void
    {
        $this->_init(FeraOrderResource::class);
    }

    public function getOrderId(): int
    {
        return (int) $this->_getData(self::ORDER_ID);
    }

    public function getFeraId(): string
    {
        return (string) $this->_getData(self::FERA_ID);
    }

    public function getExportedAt(): string
    {
        return (string) $this->_getData(self::EXPORTED_AT);
    }

    public function setOrderId(int $orderId): self
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    public function setFeraId(string $feraId): self
    {
        return $this->setData(self::FERA_ID, $feraId);
    }

    public function setExportedAt(string $exportedAt): self
    {
        return $this->setData(self::EXPORTED_AT, $exportedAt);
    }
}
