<?php

declare(strict_types=1);

namespace Fera\Ai\Api\Data;

interface ProductExportMessageInterface
{
    /**
     * @return int|null
     */
    public function getProductId(): ?int;

    /**
     * @param int $value
     * @return static
     */
    public function setProductId(int $value): static;

    /**
     * @return int|null
     */
    public function getStoreId(): ?int;

    /**
     * @param int $value
     * @return static
     */
    public function setStoreId(int $value): static;
}
