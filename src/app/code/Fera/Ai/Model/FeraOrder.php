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

    public function setOrderId(int $value): self
    {
        return $this->setData(self::ORDER_ID, $value);
    }

    public function setFeraId(string $value): self
    {
        return $this->setData(self::FERA_ID, $value);
    }

    public function setExportedAt(string $value): self
    {
        return $this->setData(self::EXPORTED_AT, $value);
    }
}
