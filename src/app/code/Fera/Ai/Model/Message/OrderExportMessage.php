<?php

namespace Fera\Ai\Model\Message;

class OrderExportMessage
{
    /** @var int */
    private $orderId;
    
    /** @var int */
    private $storeId;

    /**
     * @param int $orderId
     * @param int $storeId
     */
    public function __construct(int $orderId, int $storeId)
    {
        $this->orderId = $orderId;
        $this->storeId = $storeId;
    }

    /**
     * @return int
     */
    public function getOrderId(): int
    {
        return $this->orderId;
    }

    /**
     * @return int
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }
}
