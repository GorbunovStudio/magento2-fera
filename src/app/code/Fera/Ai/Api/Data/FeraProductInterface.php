<?php

namespace Fera\Ai\Api\Data;

interface FeraProductInterface
{
    public const ID = 'id';
    public const PRODUCT_ID = 'product_id';
    public const FERA_ID = 'fera_id';
    public const EXPORTED_AT = 'exported_at';

    /**
     * @return int
     */
    public function getProductId(): int;

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
    public function setProductId(int $value): self;

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
