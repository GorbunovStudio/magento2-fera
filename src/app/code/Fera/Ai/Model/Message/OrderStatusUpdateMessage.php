<?php

namespace Fera\Ai\Model\Message;

class OrderStatusUpdateMessage
{
    /** @var int */
    private $shipmentId;
    
    /** @var int */
    private $storeId;

    /**
     * @param int $shipmentId
     * @param int $storeId
     */
    public function __construct(int $shipmentId, int $storeId)
    {
        $this->shipmentId = $shipmentId;
        $this->storeId = $storeId;
    }

    /**
     * @return int
     */
    public function getShipmentId(): int
    {
        return $this->shipmentId;
    }

    /**
     * @return int
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }
}
