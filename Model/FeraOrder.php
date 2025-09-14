<?php

declare(strict_types=1);

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\FeraOrderInterface;
use Fera\Ai\Model\ResourceModel\FeraOrder as FeraOrderResource;
use Magento\Framework\Model\AbstractModel;
use UnexpectedValueException;

class FeraOrder extends AbstractModel implements FeraOrderInterface
{
    protected function _construct(): void
    {
        $this->_init(FeraOrderResource::class);
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

    public function setOrderId(int $value): static
    {
        return $this->setData(self::ORDER_ID, $value);
    }

    public function setFeraId(string $value): static
    {
        return $this->setData(self::FERA_ID, $value);
    }

    public function setExportedAt(string $value): static
    {
        return $this->setData(self::EXPORTED_AT, $value);
    }
}
