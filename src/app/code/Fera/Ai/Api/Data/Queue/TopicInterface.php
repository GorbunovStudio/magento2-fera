<?php

namespace Fera\Ai\Api\Data\Queue;

interface TopicInterface
{
    public const EXPORT_ORDER = 'fera.export.order';
    public const EXPORT_ORDER_STATUS_UPDATE = 'fera.export.order.status.update';
    public const EXPORT_PRODUCT = 'fera.export.product';
}
