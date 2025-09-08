<?php

namespace Fera\Ai\Model\Message;

use Fera\Ai\Api\Data\ProductExportMessageInterface;

class ProductExportMessage implements ProductExportMessageInterface
{
    /** @var int|null */
    private $productId;
    
    /** @var int|null */
    private $storeId;

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function setProductId(int $value): static
    {
        $this->productId = $value;
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
