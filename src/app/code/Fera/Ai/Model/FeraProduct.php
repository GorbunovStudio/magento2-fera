<?php

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\FeraProductInterface;
use Fera\Ai\Model\ResourceModel\FeraProduct as FeraProductResource;
use Magento\Framework\Model\AbstractModel;

class FeraProduct extends AbstractModel implements FeraProductInterface
{
    protected function _construct(): void
    {
        $this->_init(FeraProductResource::class);
    }

    public function getProductId(): int
    {
        return (int) $this->getData(self::PRODUCT_ID);
    }

    public function getFeraId(): string
    {
        return (string) $this->getData(self::FERA_ID);
    }

    public function getExportedAt(): string
    {
        return (string) $this->getData(self::EXPORTED_AT);
    }

    public function setProductId(int $value): self
    {
        $this->setData(self::PRODUCT_ID, $value);
        return $this;
    }

    public function setFeraId(string $value): self
    {
        $this->setData(self::FERA_ID, $value);
        return $this;
    }

    public function setExportedAt(string $value): self
    {
        $this->setData(self::EXPORTED_AT, $value);
        return $this;
    }
}
