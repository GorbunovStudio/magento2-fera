<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Services\ReviewSnapshot;

use Fera\Ai\Exception\ReviewSnapshotLockException;
use Fera\Ai\Services\ReviewSnapshot\ReviewSnapshotLock;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReviewSnapshotLockTest extends TestCase
{
    public function testExecutesOperationUnderGlobalReviewLockAndAlwaysReleasesIt(): void
    {
        $lockName = 'fera_review_snapshot_' . hash('sha256', 'review-1');
        /** @var LockManagerInterface&MockObject $lockManager */
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())->method('lock')->with($lockName, 10)->willReturn(true);
        $lockManager->expects(self::once())->method('unlock')->with($lockName);

        self::assertSame('done', (new ReviewSnapshotLock($lockManager))->execute('review-1', static fn(): string => 'done'));
    }

    public function testThrowsWithoutExecutingOrUnlockingWhenLockCannotBeAcquired(): void
    {
        /** @var LockManagerInterface&MockObject $lockManager */
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())->method('lock')->willReturn(false);
        $lockManager->expects(self::never())->method('unlock');

        $this->expectException(ReviewSnapshotLockException::class);
        (new ReviewSnapshotLock($lockManager))->execute('review-1', static function (): void {
            self::fail('The operation must not run when the lock cannot be acquired.');
        });
    }

    public function testReleasesLockWhenOperationFails(): void
    {
        $lockName = 'fera_review_snapshot_' . hash('sha256', 'review-1');
        /** @var LockManagerInterface&MockObject $lockManager */
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $lockManager->expects(self::once())->method('unlock')->with($lockName);

        $this->expectException(\RuntimeException::class);
        (new ReviewSnapshotLock($lockManager))->execute('review-1', static function (): void {
            throw new \RuntimeException('Persistence failed');
        });
    }
}
