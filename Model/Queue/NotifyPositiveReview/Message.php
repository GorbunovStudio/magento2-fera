<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\NotifyPositiveReview;

use Fera\Ai\Api\Data\Queue\NotifyPositiveReview\MessageInterface;
use Magento\Framework\DataObject;
use UnexpectedValueException;

class Message extends DataObject implements MessageInterface
{
    private const STORE_ID = 'store_id';
    private const REVIEW_ID = 'review_id';
    private const RATING = 'rating';
    private const FERA_STORE_ID = 'fera_store_id';
    private const EXTERNAL_ORDER_ID = 'external_order_id';
    private const CUSTOMER_NAME = 'customer_name';
    private const CUSTOMER_EMAIL = 'customer_email';
    private const REVIEW_TITLE = 'review_title';
    private const REVIEW_BODY = 'review_body';
    private const PRODUCT_NAME = 'product_name';
    private const EXTERNAL_PRODUCT_ID = 'external_product_id';
    private const MEDIA_JSON = 'media_json';

    public function getStoreId(): int
    {
        return $this->getInt(self::STORE_ID);
    }

    public function setStoreId(int $value): static
    {
        $this->setData(self::STORE_ID, $value);
        return $this;
    }

    public function getReviewId(): string
    {
        return $this->getString(self::REVIEW_ID);
    }

    public function setReviewId(string $value): static
    {
        $this->setData(self::REVIEW_ID, $value);
        return $this;
    }

    public function getRating(): float
    {
        $value = parent::getData(self::RATING);
        if (!is_numeric($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::RATING . ': expected float, got ' . get_debug_type($value)
            );
        }

        return (float) $value;
    }

    public function setRating(float $value): static
    {
        $this->setData(self::RATING, $value);
        return $this;
    }

    public function getFeraStoreId(): string
    {
        return $this->getString(self::FERA_STORE_ID);
    }

    public function setFeraStoreId(string $value): static
    {
        $this->setData(self::FERA_STORE_ID, $value);
        return $this;
    }

    public function getExternalOrderId(): string
    {
        return $this->getString(self::EXTERNAL_ORDER_ID);
    }

    public function setExternalOrderId(string $value): static
    {
        $this->setData(self::EXTERNAL_ORDER_ID, $value);
        return $this;
    }

    public function getCustomerName(): string
    {
        return $this->getString(self::CUSTOMER_NAME);
    }

    public function setCustomerName(string $value): static
    {
        $this->setData(self::CUSTOMER_NAME, $value);
        return $this;
    }

    public function getCustomerEmail(): string
    {
        return $this->getString(self::CUSTOMER_EMAIL);
    }

    public function setCustomerEmail(string $value): static
    {
        $this->setData(self::CUSTOMER_EMAIL, $value);
        return $this;
    }

    public function getReviewTitle(): string
    {
        return $this->getString(self::REVIEW_TITLE);
    }

    public function setReviewTitle(string $value): static
    {
        $this->setData(self::REVIEW_TITLE, $value);
        return $this;
    }

    public function getReviewBody(): string
    {
        return $this->getString(self::REVIEW_BODY);
    }

    public function setReviewBody(string $value): static
    {
        $this->setData(self::REVIEW_BODY, $value);
        return $this;
    }

    public function getProductName(): string
    {
        return $this->getString(self::PRODUCT_NAME);
    }

    public function setProductName(string $value): static
    {
        $this->setData(self::PRODUCT_NAME, $value);
        return $this;
    }

    public function getExternalProductId(): string
    {
        return $this->getString(self::EXTERNAL_PRODUCT_ID);
    }

    public function setExternalProductId(string $value): static
    {
        $this->setData(self::EXTERNAL_PRODUCT_ID, $value);
        return $this;
    }

    public function getMediaJson(): string
    {
        $value = parent::getData(self::MEDIA_JSON);
        if ($value === null) {
            return '';
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::MEDIA_JSON . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    public function setMediaJson(string $value): static
    {
        $this->setData(self::MEDIA_JSON, $value);
        return $this;
    }

    private function getInt(string $key): int
    {
        $value = parent::getData($key);
        if (!is_numeric($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . $key . ': expected int, got ' . get_debug_type($value)
            );
        }

        return (int) $value;
    }

    private function getString(string $key): string
    {
        $value = parent::getData($key);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . $key . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }
}
