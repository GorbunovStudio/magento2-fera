<?php

declare(strict_types=1);

namespace Fera\Ai\Api\Data;

interface ReviewSnapshotInterface
{
    public const ID = 'id';
    public const REVIEW_ID = 'review_id';
    public const HEADING = 'heading';
    public const BODY = 'body';
    public const RATING = 'rating';
    public const MEDIA = 'media';
    public const MAGENTO_STORE_ID = 'magento_store_id';
    public const SUBJECT = 'subject';
    public const EXTERNAL_ORDER_ID = 'external_order_id';
    public const EXTERNAL_PRODUCT_ID = 'external_product_id';
    public const FERA_PRODUCT_ID = 'fera_product_id';
    public const PRODUCT_NAME = 'product_name';
    public const STATE = 'state';
    public const IS_TEST = 'is_test';
    public const FERA_CREATED_AT = 'fera_created_at';
    public const FERA_UPDATED_AT = 'fera_updated_at';

    public function getReviewId(): string;

    public function getHeading(): string;

    public function getBody(): string;

    public function getRating(): float;

    public function getMedia(): string;

    public function getMagentoStoreId(): ?int;

    public function getSubject(): ?string;

    public function getExternalOrderId(): ?string;

    public function getExternalProductId(): ?string;

    public function getFeraProductId(): ?string;

    public function getProductName(): ?string;

    public function getState(): ?string;

    public function getIsTest(): ?bool;

    public function getFeraCreatedAt(): ?string;

    public function getFeraUpdatedAt(): ?string;

    public function setReviewId(string $value): static;

    public function setHeading(string $value): static;

    public function setBody(string $value): static;

    public function setRating(float $value): static;

    public function setMedia(string $value): static;

    public function setMagentoStoreId(?int $value): static;

    public function setSubject(?string $value): static;

    public function setExternalOrderId(?string $value): static;

    public function setExternalProductId(?string $value): static;

    public function setFeraProductId(?string $value): static;

    public function setProductName(?string $value): static;

    public function setState(?string $value): static;

    public function setIsTest(?bool $value): static;

    public function setFeraCreatedAt(?string $value): static;

    public function setFeraUpdatedAt(?string $value): static;
}
