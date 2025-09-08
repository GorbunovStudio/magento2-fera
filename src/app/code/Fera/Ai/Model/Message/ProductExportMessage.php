<?php

namespace Fera\Ai\Model\Message;

class ProductExportMessage
{
    /** @var int */
    private $productId;
    
    /** @var int */
    private $storeId;

    /**
     * @param int $productId
     * @param int $storeId
     */
    public function __construct(int $productId, int $storeId)
    {
        $this->productId = $productId;
        $this->storeId = $storeId;
    }

    /**
     * @return int
     */
    public function getProductId(): int
    {
        return $this->productId;
    }

    /**
     * @return int
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }
}
