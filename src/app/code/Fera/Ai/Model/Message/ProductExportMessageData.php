<?php

namespace Fera\Ai\Model\Message;

use Fera\Ai\Api\Data\ProductExportMessageDataInterface;

class ProductExportMessageData implements ProductExportMessageDataInterface
{
    /** @var int|null */
    private $productId;
    
    /** @var int|null */
    private $storeId;

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function setProductId(int $value): self
    {
        $this->productId = $value;
        return $this;
    }

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function setStoreId(int $value): self
    {
        $this->storeId = $value;
        return $this;
    }
}
