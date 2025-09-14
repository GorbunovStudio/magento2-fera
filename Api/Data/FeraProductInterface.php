<?php

declare(strict_types=1);

namespace Fera\Ai\Api\Data;

interface FeraProductInterface
{
    public const ID = 'id';
    public const PRODUCT_ID = 'product_id';
    public const FERA_ID = 'fera_id';
    public const STORE_ID = 'store_id';
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
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @param int $value
     * @return $this
     */
    public function setProductId(int $value): static;

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

    /**
     * @param int $value
     * @return $this
     */
    public function setStoreId(int $value): static;
}
