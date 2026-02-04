<?php

declare(strict_types=1);

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\FeraOrderFulfillmentInterface;
use Fera\Ai\Model\ResourceModel\FeraOrderFulfillment as FeraOrderFulfillmentResource;
use Magento\Framework\Model\AbstractModel;
use UnexpectedValueException;

class FeraOrderFulfillment extends AbstractModel implements FeraOrderFulfillmentInterface
{
    protected function _construct(): void
    {
        $this->_init(FeraOrderFulfillmentResource::class);
    }

    public function getOrderId(): int
    {
        $value = parent::getData(self::ORDER_ID);
        if (!is_numeric($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::ORDER_ID . ': expected int, got ' . get_debug_type($value)
            );
        }
        return (int) $value;
    }

    public function getStoreId(): int
    {
        $value = parent::getData(self::STORE_ID);
        if (!is_numeric($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::STORE_ID . ': expected int, got ' . get_debug_type($value)
            );
        }
        return (int) $value;
    }

    public function getCompletedAt(): ?string
    {
        $value = parent::getData(self::COMPLETED_AT);
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::COMPLETED_AT . ': expected string|null, got ' . get_debug_type($value)
            );
        }
        return $value;
    }

    public function getExportedAt(): ?string
    {
        $value = parent::getData(self::EXPORTED_AT);
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::EXPORTED_AT . ': expected string|null, got ' . get_debug_type($value)
            );
        }
        return $value;
    }

    public function setOrderId(int $value): static
    {
        $this->setData(self::ORDER_ID, $value);
        return $this;
    }

    public function setStoreId(int $value): static
    {
        $this->setData(self::STORE_ID, $value);
        return $this;
    }

    public function setCompletedAt(?string $value): static
    {
        $this->setData(self::COMPLETED_AT, $value);
        return $this;
    }

    public function setExportedAt(?string $value): static
    {
        $this->setData(self::EXPORTED_AT, $value);
        return $this;
    }
}
