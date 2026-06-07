<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\NotifyNegativeReview;

use Fera\Ai\Api\Data\Queue\NotifyNegativeReview\MessageInterface;
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

    public function setStoreId(int $value): static
    {
        $this->setData(self::STORE_ID, $value);
        return $this;
    }

    public function getReviewId(): string
    {
        $value = parent::getData(self::REVIEW_ID);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::REVIEW_ID . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
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
        $value = parent::getData(self::FERA_STORE_ID);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::FERA_STORE_ID . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    public function setFeraStoreId(string $value): static
    {
        $this->setData(self::FERA_STORE_ID, $value);
        return $this;
    }

    public function getExternalOrderId(): string
    {
        $value = parent::getData(self::EXTERNAL_ORDER_ID);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::EXTERNAL_ORDER_ID . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    public function setExternalOrderId(string $value): static
    {
        $this->setData(self::EXTERNAL_ORDER_ID, $value);
        return $this;
    }

    public function getCustomerName(): string
    {
        $value = parent::getData(self::CUSTOMER_NAME);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::CUSTOMER_NAME . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    public function setCustomerName(string $value): static
    {
        $this->setData(self::CUSTOMER_NAME, $value);
        return $this;
    }

    public function getReviewTitle(): string
    {
        $value = parent::getData(self::REVIEW_TITLE);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::REVIEW_TITLE . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    public function setReviewTitle(string $value): static
    {
        $this->setData(self::REVIEW_TITLE, $value);
        return $this;
    }

    public function getReviewBody(): string
    {
        $value = parent::getData(self::REVIEW_BODY);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::REVIEW_BODY . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    public function setReviewBody(string $value): static
    {
        $this->setData(self::REVIEW_BODY, $value);
        return $this;
    }

    public function getProductName(): string
    {
        $value = parent::getData(self::PRODUCT_NAME);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::PRODUCT_NAME . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    public function setProductName(string $value): static
    {
        $this->setData(self::PRODUCT_NAME, $value);
        return $this;
    }
    
    public function getCustomerEmail(): string
    {
        $value = parent::getData(self::CUSTOMER_EMAIL);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::CUSTOMER_EMAIL . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    public function setCustomerEmail(string $value): static
    {
        $this->setData(self::CUSTOMER_EMAIL, $value);
        return $this;
    }

    public function getExternalProductId(): string
    {
        $value = parent::getData(self::EXTERNAL_PRODUCT_ID);
        if (!is_string($value)) {
            throw new UnexpectedValueException(
                'Incorrect type for ' . self::EXTERNAL_PRODUCT_ID . ': expected string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    public function setExternalProductId(string $value): static
    {
        $this->setData(self::EXTERNAL_PRODUCT_ID, $value);
        return $this;
    }
}
