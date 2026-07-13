<?php

declare(strict_types=1);

namespace Fera\Ai\Api\Data\Queue\NotifyPositiveReview;

interface MessageInterface
{
    /**
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @param int $value
     * @return $this
     */
    public function setStoreId(int $value): static;

    /**
     * @return string
     */
    public function getReviewId(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setReviewId(string $value): static;

    /**
     * @return float
     */
    public function getRating(): float;

    /**
     * @param float $value
     * @return $this
     */
    public function setRating(float $value): static;

    /**
     * @return string
     */
    public function getFeraStoreId(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setFeraStoreId(string $value): static;

    /**
     * @return string
     */
    public function getExternalOrderId(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setExternalOrderId(string $value): static;

    /**
     * @return string
     */
    public function getCustomerName(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setCustomerName(string $value): static;

    /**
     * @return string
     */
    public function getCustomerEmail(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setCustomerEmail(string $value): static;

    /**
     * @return string
     */
    public function getReviewTitle(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setReviewTitle(string $value): static;

    /**
     * @return string
     */
    public function getReviewBody(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setReviewBody(string $value): static;

    /**
     * @return string
     */
    public function getProductName(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setProductName(string $value): static;

    /**
     * @return string
     */
    public function getExternalProductId(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setExternalProductId(string $value): static;

    /**
     * @return list<array{id: string, url: string}>
     */
    public function getMedia(): array;

    /**
     * @param list<array{id: string, url: string}> $value
     * @return $this
     */
    public function setMedia(array $value): static;
}
