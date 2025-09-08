<?php

namespace Fera\Ai\Model\Message;

use Fera\Ai\Api\Data\OrderExportMessageInterface;

class OrderExportMessage implements OrderExportMessageInterface
{
    /** @var int|null */
    private $orderId;
    
    /** @var int|null */
    private $storeId;

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function setOrderId(int $value): static
    {
        $this->orderId = $value;
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
