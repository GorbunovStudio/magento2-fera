<?php

namespace Fera\Ai\Model\Message;

use Fera\Ai\Api\Data\OrderStatusUpdateMessageInterface;

class OrderStatusUpdateMessage implements OrderStatusUpdateMessageInterface
{
    /** @var int|null */
    private $shipmentId;

    public function getShipmentId(): ?int
    {
        return $this->shipmentId;
    }

    public function setShipmentId(int $value): static
    {
        $this->shipmentId = $value;
        return $this;
    }
}
