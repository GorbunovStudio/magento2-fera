<?php

namespace Fera\Ai\Api\Data;

interface FeraOrderInterface
{
    public const ID = 'id';
    public const ORDER_ID = 'order_id';
    public const FERA_ID = 'fera_id';
    public const EXPORTED_AT = 'exported_at';

    public function getOrderId(): int;
    public function getFeraId(): string;
    public function getExportedAt(): string;

    public function setOrderId(int $orderId): self;
    public function setFeraId(string $feraId): self;
    public function setExportedAt(string $exportedAt): self;
}
