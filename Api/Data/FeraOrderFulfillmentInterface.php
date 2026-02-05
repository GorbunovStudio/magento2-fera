<?php

declare(strict_types=1);

namespace Fera\Ai\Api\Data;

interface FeraOrderFulfillmentInterface
{
    public const ID = 'id';
    public const ORDER_ID = 'order_id';
    public const STORE_ID = 'store_id';
    public const COMPLETED_AT = 'completed_at';
    public const EXPORTED_AT = 'exported_at';
    public const ENQUEUED_AT = 'enqueued_at';

    /**
     * @return int
     */
    public function getOrderId(): int;

    /**
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @return string|null
     */
    public function getCompletedAt(): ?string;

    /**
     * @return string|null
     */
    public function getExportedAt(): ?string;

    /**
     * @return string|null
     */
    public function getEnqueuedAt(): ?string;

    /**
     * @param int $value
     * @return $this
     */
    public function setOrderId(int $value): static;

    /**
     * @param int $value
     * @return $this
     */
    public function setStoreId(int $value): static;

    /**
     * @param string|null $value
     * @return $this
     */
    public function setCompletedAt(?string $value): static;

    /**
     * @param string|null $value
     * @return $this
     */
    public function setExportedAt(?string $value): static;

    /**
     * @param string|null $value
     * @return $this
     */
    public function setEnqueuedAt(?string $value): static;
}

