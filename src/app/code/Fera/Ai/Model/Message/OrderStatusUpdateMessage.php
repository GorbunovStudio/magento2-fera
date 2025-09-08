<?php

namespace Fera\Ai\Model\Message;

use Fera\Ai\Api\Data\OrderStatusUpdateMessageInterface;

class OrderStatusUpdateMessage implements OrderStatusUpdateMessageInterface
{
    /** @var int|null */
    private $shipmentId;
    
    /** @var int|null */
    private $storeId;

    public function getShipmentId(): ?int
    {
        return $this->shipmentId;
    }

    public function setShipmentId(int $value): static
    {
        $this->shipmentId = $value;
        return $this;
    }

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function setStoreId(int $value): static
    {
        $this->storeId = $value;
        return $this;
    }
}
