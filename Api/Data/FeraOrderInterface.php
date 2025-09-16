<?php

declare(strict_types=1);

namespace Fera\Ai\Api\Data;

interface FeraOrderInterface
{
    public const ID = 'id';
    public const ORDER_ID = 'order_id';
    public const FERA_ID = 'fera_id';
    public const EXPORTED_AT = 'exported_at';

    /**
     * @return int
     */
    public function getOrderId(): int;

    /**
     * @return string
     */
    public function getFeraId(): string;

    /**
     * @return string
     */
    public function getExportedAt(): string;

    /**
     * @param int $value
     * @return $this
     */
    public function setOrderId(int $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setFeraId(string $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setExportedAt(string $value): static;
}
