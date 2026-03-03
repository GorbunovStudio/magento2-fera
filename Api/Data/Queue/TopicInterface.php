<?php

declare(strict_types=1);

namespace Fera\Ai\Api\Data\Queue;

interface TopicInterface
{
    public const EXPORT_ORDER = 'fera.export.order';
    public const EXPORT_ORDER_FULFILLMENT = 'fera.export.order.fulfillment';
    public const EXPORT_ORDER_UPDATE = 'fera.export.order.update';
    public const EXPORT_PRODUCT = 'fera.export.product';
    public const PROCESS_CUSTOMER_UPDATE = 'fera.process.customer.update';
    public const NOTIFY_NEGATIVE_REVIEW = 'fera.review.notify_negative';
}
