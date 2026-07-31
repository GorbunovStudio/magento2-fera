<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ReviewSnapshot;

use Fera\Ai\Exception\ReviewSnapshotLockException;
use Magento\Framework\Lock\LockManagerInterface;

class ReviewSnapshotLock
{
    private const LOCK_WAIT_TIMEOUT_SECONDS = 10;
    private const LOCK_PREFIX = 'fera_review_snapshot_';

    public function __construct(
        private LockManagerInterface $lockManager
    ) {
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws ReviewSnapshotLockException
     */
    public function execute(string $reviewId, callable $operation): mixed
    {
        $lockName = self::LOCK_PREFIX . hash('sha256', $reviewId);
        if (!$this->lockManager->lock($lockName, self::LOCK_WAIT_TIMEOUT_SECONDS)) {
            throw new ReviewSnapshotLockException('Review snapshot processing is busy');
        }

        try {
            return $operation();
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }
}
