<?php

declare(strict_types=1);

namespace Fera\Ai\Api;

interface ReviewUpdatedWebhookInterface
{
    /**
     * Process Fera "review_updated" webhook.
     *
     * @return void
     */
    public function execute(): void;
}
