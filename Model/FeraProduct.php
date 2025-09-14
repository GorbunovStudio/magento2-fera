<?php

declare(strict_types=1);

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\FeraProductInterface;
use Fera\Ai\Model\ResourceModel\FeraProduct as FeraProductResource;
use Magento\Framework\Model\AbstractModel;
use UnexpectedValueException;

class FeraProduct extends AbstractModel implements FeraProductInterface
{
    protected function _construct(): void
    {
        $this->_init(FeraProductResource::class);
    }

    public function getProductId(): int
    {
        $value = parent::getData(self::PRODUCT_ID);
        if (!is_numeric($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::PRODUCT_ID . ': expected int, got ' . get_debug_type($value)
            );
        }
        return (int) $value;
    }

    public function getFeraId(): string
    {
        $value = parent::getData(self::FERA_ID);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::FERA_ID . ': expected string, got ' . get_debug_type($value)
            );
        }
        return $value;
    }

    public function getExportedAt(): string
    {
        $value = parent::getData(self::EXPORTED_AT);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::EXPORTED_AT . ': expected string, got ' . get_debug_type($value)
            );
        }
        return $value;
    }

    public function setProductId(int $value): static
    {
        $this->setData(self::PRODUCT_ID, $value);
        return $this;
    }

    public function setFeraId(string $value): static
    {
        $this->setData(self::FERA_ID, $value);
        return $this;
    }

    public function setExportedAt(string $value): static
    {
        $this->setData(self::EXPORTED_AT, $value);
        return $this;
    }
}
