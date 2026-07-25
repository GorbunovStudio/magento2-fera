<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Console\Command;

use Fera\Ai\Api\ApiClient\ReviewsClientInterface;
use Fera\Ai\Console\Command\BackfillReviewsCommand;
use Fera\Ai\Services\ReviewSnapshot\SnapshotBuilder;
use Fera\Ai\Services\ReviewSnapshot\SnapshotRepository;
use Fera\Ai\Services\StoreGroupService;
use Magento\Framework\App\State as AppState;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

class BackfillReviewsCommandTest extends TestCase
{
    public function testProcessesEachCanonicalAccountAndReturnsFailureForPartialAccountFailure(): void
    {
        /** @var ReviewsClientInterface&MockObject $reviewsClient */
        $reviewsClient = $this->createMock(ReviewsClientInterface::class);
        $reviewsClient->expects(self::exactly(3))
            ->method('list')
            ->willReturnCallback(static function (int $page, int $pageSize, int $storeId): array {
                self::assertSame(100, $pageSize);
                if ($storeId === 10) {
                    return ['data' => [['id' => 'review-1']], 'meta' => ['page_count' => 1]];
                }

                if ($storeId === 20 && $page === 1) {
                    return ['data' => [['id' => 'review-2']], 'meta' => ['page_count' => 2]];
                }

                throw new RuntimeException('simulated account failure');
            });

        /** @var SnapshotBuilder&MockObject $snapshotBuilder */
        $snapshotBuilder = $this->createMock(SnapshotBuilder::class);
        $snapshotBuilder->expects(self::exactly(2))
            ->method('build')
            ->willReturnCallback(static fn(array $review): array => ['review_id' => $review['id']]);

        /** @var SnapshotRepository&MockObject $snapshotRepository */
        $snapshotRepository = $this->createMock(SnapshotRepository::class);
        $snapshotRepository->expects(self::exactly(2))->method('save');
        $snapshotRepository->method('countIncomplete')->willReturn(0);

        /** @var StoreGroupService&MockObject $storeGroupService */
        $storeGroupService = $this->createMock(StoreGroupService::class);
        $storeGroupService->method('getByFeraAccount')->willReturn([10 => [10], 20 => [20]]);

        $command = new BackfillReviewsCommand(
            $reviewsClient,
            $snapshotBuilder,
            $snapshotRepository,
            $storeGroupService,
            $this->createMock(AppState::class)
        );
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('accounts_failed=1', $tester->getDisplay());
        self::assertStringContainsString('fetched=2, saved=2', $tester->getDisplay());
        self::assertStringContainsString('incomplete_snapshots=0', $tester->getDisplay());
    }

    public function testReportsWhenNoFeraAccountsAreConfigured(): void
    {
        /** @var ReviewsClientInterface&MockObject $reviewsClient */
        $reviewsClient = $this->createMock(ReviewsClientInterface::class);
        $reviewsClient->expects(self::never())->method('list');

        /** @var SnapshotBuilder&MockObject $snapshotBuilder */
        $snapshotBuilder = $this->createMock(SnapshotBuilder::class);

        /** @var SnapshotRepository&MockObject $snapshotRepository */
        $snapshotRepository = $this->createMock(SnapshotRepository::class);
        $snapshotRepository->expects(self::never())->method('countIncomplete');

        /** @var StoreGroupService&MockObject $storeGroupService */
        $storeGroupService = $this->createMock(StoreGroupService::class);
        $storeGroupService->method('getByFeraAccount')->willReturn([]);

        $command = new BackfillReviewsCommand(
            $reviewsClient,
            $snapshotBuilder,
            $snapshotRepository,
            $storeGroupService,
            $this->createMock(AppState::class)
        );
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('No enabled Fera accounts are configured.', $tester->getDisplay());
    }

    public function testDryRunDoesNotWriteSnapshots(): void
    {
        /** @var ReviewsClientInterface&MockObject $reviewsClient */
        $reviewsClient = $this->createMock(ReviewsClientInterface::class);
        $reviewsClient->expects(self::once())->method('list')->willReturn([
            'data' => [['id' => 'review-1']],
            'meta' => ['page_count' => 1],
        ]);

        /** @var SnapshotBuilder&MockObject $snapshotBuilder */
        $snapshotBuilder = $this->createMock(SnapshotBuilder::class);
        $snapshotBuilder->expects(self::once())->method('build')->willReturn(['review_id' => 'review-1']);

        /** @var SnapshotRepository&MockObject $snapshotRepository */
        $snapshotRepository = $this->createMock(SnapshotRepository::class);
        $snapshotRepository->expects(self::never())->method('save');
        $snapshotRepository->method('countIncomplete')->willReturn(1);

        /** @var StoreGroupService&MockObject $storeGroupService */
        $storeGroupService = $this->createMock(StoreGroupService::class);
        $storeGroupService->method('getByFeraAccount')->willReturn([10 => [10]]);

        $command = new BackfillReviewsCommand(
            $reviewsClient,
            $snapshotBuilder,
            $snapshotRepository,
            $storeGroupService,
            $this->createMock(AppState::class)
        );
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--dry-run' => true, '--max-pages' => '1']));
        self::assertStringContainsString('dry_run=true', $tester->getDisplay());
    }
}
