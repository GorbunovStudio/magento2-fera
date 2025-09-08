<?php

declare(strict_types=1);

namespace Fera\Ai\Api\Data;

interface OrderStatusUpdateMessageDataInterface
{
    /**
     * @return int|null
     */
    public function getShipmentId(): ?int;

    /**
     * @param int $value
     * @return static
     */
    public function setShipmentId(int $value): static;

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
