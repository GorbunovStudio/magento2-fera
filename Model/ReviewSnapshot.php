<?php

declare(strict_types=1);

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\ReviewSnapshotInterface;
use Fera\Ai\Model\ResourceModel\ReviewSnapshot as ReviewSnapshotResource;
use Magento\Framework\Model\AbstractModel;
use UnexpectedValueException;

class ReviewSnapshot extends AbstractModel implements ReviewSnapshotInterface
{
    protected function _construct(): void
    {
        $this->_init(ReviewSnapshotResource::class);
    }

    public function getReviewId(): string
    {
        return $this->requiredString(self::REVIEW_ID);
    }

    public function getHeading(): string
    {
        return $this->requiredString(self::HEADING);
    }

    public function getBody(): string
    {
        return $this->requiredString(self::BODY);
    }

    public function getRating(): float
    {
        $value = parent::getData(self::RATING);
        if (!is_numeric($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::RATING . ': expected numeric, got ' . get_debug_type($value)
            );
        }

        return (float) $value;
    }

    public function getMedia(): string
    {
        return $this->requiredString(self::MEDIA);
    }

    public function getMagentoStoreId(): ?int
    {
        $value = parent::getData(self::MAGENTO_STORE_ID);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::MAGENTO_STORE_ID . ': expected int, got ' . get_debug_type($value)
            );
        }

        return (int) $value;
    }

    public function getSubject(): ?string
    {
        return $this->nullableString(self::SUBJECT);
    }

    public function getExternalOrderId(): ?string
    {
        return $this->nullableString(self::EXTERNAL_ORDER_ID);
    }

    public function getExternalProductId(): ?string
    {
        return $this->nullableString(self::EXTERNAL_PRODUCT_ID);
    }

    public function getFeraProductId(): ?string
    {
        return $this->nullableString(self::FERA_PRODUCT_ID);
    }

    public function getProductName(): ?string
    {
        return $this->nullableString(self::PRODUCT_NAME);
    }

    public function getState(): ?string
    {
        return $this->nullableString(self::STATE);
    }

    public function getIsTest(): ?bool
    {
        $value = parent::getData(self::IS_TEST);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::IS_TEST . ': expected scalar, got ' . get_debug_type($value)
            );
        }

        return !in_array((string) $value, ['0', 'false'], true);
    }

    public function getFeraCreatedAt(): ?string
    {
        return $this->nullableString(self::FERA_CREATED_AT);
    }

    public function getFeraUpdatedAt(): ?string
    {
        return $this->nullableString(self::FERA_UPDATED_AT);
    }

    public function setReviewId(string $value): static
    {
        return $this->setData(self::REVIEW_ID, $value);
    }

    public function setHeading(string $value): static
    {
        return $this->setData(self::HEADING, $value);
    }

    public function setBody(string $value): static
    {
        return $this->setData(self::BODY, $value);
    }

    public function setRating(float $value): static
    {
        return $this->setData(self::RATING, $value);
    }

    public function setMedia(string $value): static
    {
        return $this->setData(self::MEDIA, $value);
    }

    public function setMagentoStoreId(?int $value): static
    {
        return $this->setData(self::MAGENTO_STORE_ID, $value);
    }

    public function setSubject(?string $value): static
    {
        return $this->setData(self::SUBJECT, $value);
    }

    public function setExternalOrderId(?string $value): static
    {
        return $this->setData(self::EXTERNAL_ORDER_ID, $value);
    }

    public function setExternalProductId(?string $value): static
    {
        return $this->setData(self::EXTERNAL_PRODUCT_ID, $value);
    }

    public function setFeraProductId(?string $value): static
    {
        return $this->setData(self::FERA_PRODUCT_ID, $value);
    }

    public function setProductName(?string $value): static
    {
        return $this->setData(self::PRODUCT_NAME, $value);
    }

    public function setState(?string $value): static
    {
        return $this->setData(self::STATE, $value);
    }

    public function setIsTest(?bool $value): static
    {
        return $this->setData(self::IS_TEST, $value === null ? null : (int) $value);
    }

    public function setFeraCreatedAt(?string $value): static
    {
        return $this->setData(self::FERA_CREATED_AT, $value);
    }

    public function setFeraUpdatedAt(?string $value): static
    {
        return $this->setData(self::FERA_UPDATED_AT, $value);
    }

    private function requiredString(string $key): string
    {
        $value = parent::getData($key);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . $key . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    private function nullableString(string $key): ?string
    {
        $value = parent::getData($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . $key . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }
}
