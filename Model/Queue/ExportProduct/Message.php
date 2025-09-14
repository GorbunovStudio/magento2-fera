<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\ExportProduct;

use Fera\Ai\Api\Data\Queue\ExportProduct\MessageInterface;

class Message implements MessageInterface
{
    private ?int $productId;
    private ?int $storeId;

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
