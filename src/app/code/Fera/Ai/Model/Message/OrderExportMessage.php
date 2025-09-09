<?php

namespace Fera\Ai\Model\Message;

use Fera\Ai\Api\Data\OrderExportMessageInterface;

class OrderExportMessage implements OrderExportMessageInterface
{
    /** @var int|null */
    private $orderId;

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function setOrderId(int $value): static
    {
        $this->orderId = $value;
        return $this;
    }
}
