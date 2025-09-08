<?php

declare(strict_types=1);

namespace Fera\Ai\Api\Data;

interface OrderExportMessageInterface
{
    /**
     * @return int|null
     */
    public function getOrderId(): ?int;

    /**
     * @param int $value
     * @return static
     */
    public function setOrderId(int $value): static;

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
