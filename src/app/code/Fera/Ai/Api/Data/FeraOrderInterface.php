<?php

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
     * @return self
     */
    public function setOrderId(int $value): self;

    /**
     * @param string $value
     * @return self
     */
    public function setFeraId(string $value): self;

    /**
     * @param string $value
     * @return self
     */
    public function setExportedAt(string $value): self;
}
