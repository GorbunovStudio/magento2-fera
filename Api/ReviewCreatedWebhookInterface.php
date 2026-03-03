<?php

declare(strict_types=1);

namespace Fera\Ai\Api;

interface ReviewCreatedWebhookInterface
{
    /**
     * Process Fera "review_create" webhook.
     *
     * @return void
     */
    public function execute(): void;
}
