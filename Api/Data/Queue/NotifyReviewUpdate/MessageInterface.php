<?php

declare(strict_types=1);

namespace Fera\Ai\Api\Data\Queue\NotifyReviewUpdate;

interface MessageInterface
{
    public function getStoreId(): int;

    public function setStoreId(int $value): static;

    public function getReviewId(): string;

    public function setReviewId(string $value): static;

    public function getRating(): float;

    public function setRating(float $value): static;

    public function getFeraStoreId(): string;

    public function setFeraStoreId(string $value): static;

    public function getExternalOrderId(): string;

    public function setExternalOrderId(string $value): static;

    public function getCustomerName(): string;

    public function setCustomerName(string $value): static;

    public function getCustomerEmail(): string;

    public function setCustomerEmail(string $value): static;

    public function getReviewTitle(): string;

    public function setReviewTitle(string $value): static;

    public function getReviewBody(): string;

    public function setReviewBody(string $value): static;

    public function getProductName(): string;

    public function setProductName(string $value): static;

    public function getExternalProductId(): string;

    public function setExternalProductId(string $value): static;

    /**
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function getChangedFields(): array;

    /**
     * @param array<string, array{before: mixed, after: mixed}> $value
     * @return $this
     */
    public function setChangedFields(array $value): static;
}
