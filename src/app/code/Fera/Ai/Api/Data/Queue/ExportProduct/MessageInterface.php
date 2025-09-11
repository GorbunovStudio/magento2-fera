<?php

declare(strict_types=1);

namespace Fera\Ai\Api\Data\Queue\ExportProduct;

interface MessageInterface
{
    /**
     * @return int|null
     */
    public function getProductId(): ?int;

    /**
     * @param int $value
     * @return self
     */
    public function setProductId(int $value): self;

    /**
     * @return int|null
     */
    public function getStoreId(): ?int;

    /**
     * @param int $value
     * @return self
     */
    public function setStoreId(int $value): self;
}
