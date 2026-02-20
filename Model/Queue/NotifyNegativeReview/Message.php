<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\NotifyNegativeReview;

use Fera\Ai\Api\Data\Queue\NotifyNegativeReview\MessageInterface;
use Magento\Framework\DataObject;

class Message extends DataObject implements MessageInterface
{
    private const STORE_ID = 'store_id';
    private const REVIEW_ID = 'review_id';
    private const RATING = 'rating';
    private const FERA_STORE_ID = 'fera_store_id';
    private const EXTERNAL_ORDER_ID = 'external_order_id';
    private const CUSTOMER_NAME = 'customer_name';
    private const HEADING = 'heading';
    private const BODY = 'body';
    private const PRODUCT_NAME = 'product_name';

    public function getStoreId(): int
    {
        $value = parent::getData(self::STORE_ID);
        if (!is_numeric($value)) {
            return 0;
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
            return '';
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
            return 0.0;
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
            return '';
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
            return '';
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
            return '';
        }

        return $value;
    }

    public function setCustomerName(string $value): static
    {
        $this->setData(self::CUSTOMER_NAME, $value);
        return $this;
    }

    public function getHeading(): string
    {
        $value = parent::getData(self::HEADING);
        if (!is_string($value)) {
            return '';
        }

        return $value;
    }

    public function setHeading(string $value): static
    {
        $this->setData(self::HEADING, $value);
        return $this;
    }

    public function getBody(): string
    {
        $value = parent::getData(self::BODY);
        if (!is_string($value)) {
            return '';
        }

        return $value;
    }

    public function setBody(string $value): static
    {
        $this->setData(self::BODY, $value);
        return $this;
    }

    public function getProductName(): string
    {
        $value = parent::getData(self::PRODUCT_NAME);
        if (!is_string($value)) {
            return '';
        }

        return $value;
    }

    public function setProductName(string $value): static
    {
        $this->setData(self::PRODUCT_NAME, $value);
        return $this;
    }
}
