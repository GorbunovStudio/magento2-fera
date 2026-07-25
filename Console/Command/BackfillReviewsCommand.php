<?php

declare(strict_types=1);

namespace Fera\Ai\Console\Command;

use Fera\Ai\Api\ApiClient\ReviewsClientInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Services\ReviewSnapshot\SnapshotBuilder;
use Fera\Ai\Services\ReviewSnapshot\SnapshotRepository;
use Fera\Ai\Services\StoreGroupService;
use InvalidArgumentException;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class BackfillReviewsCommand extends Command
{
    private const NAME = 'fera:reviews:backfill';
    private const DEFAULT_PAGE_SIZE = 100;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(
        private ReviewsClientInterface $reviewsClient,
        private SnapshotBuilder $snapshotBuilder,
        private SnapshotRepository $snapshotRepository,
        private StoreGroupService $storeGroupService,
        private AppState $appState,
        private FeraHelper $feraHelper
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Backfill local Fera review snapshots from the Private API')
            ->addOption('store-id', null, InputOption::VALUE_OPTIONAL, 'Store ID to process')
            ->addOption(
                'page-size',
                null,
                InputOption::VALUE_OPTIONAL,
                'Reviews to request per page (1-100)',
                (string) self::DEFAULT_PAGE_SIZE
            )
            ->addOption(
                'max-pages',
                null,
                InputOption::VALUE_OPTIONAL,
                'Diagnostic maximum pages per account (0 = no limit)',
                '0'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Fetch and validate reviews without saving them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            try {
                $this->appState->setAreaCode(Area::AREA_ADMINHTML);
            } catch (Throwable) {
                // The area code may already be initialized by Magento.
            }

            $pageSize = $this->readIntOption(
                $input->getOption('page-size'),
                'page-size',
                1,
                self::MAX_PAGE_SIZE,
                self::DEFAULT_PAGE_SIZE
            );
            $maxPages = $this->readIntOption($input->getOption('max-pages'), 'max-pages', 0, null, 0);
            $groups = $this->selectAccountGroups($input, $output);
            if ($groups === []) {
                return Cli::RETURN_FAILURE;
            }

            $dryRun = (bool) $input->getOption('dry-run');
            $failedAccounts = 0;
            $totalFetched = 0;
            $totalSaved = 0;

            foreach (array_keys($groups) as $canonicalStoreId) {
                $counters = ['pages' => 0, 'fetched' => 0, 'saved' => 0];
                $limited = false;

                try {
                    $page = 1;
                    while (true) {
                        if ($maxPages > 0 && $page > $maxPages) {
                            $limited = true;
                            break;
                        }

                        $response = $this->reviewsClient->list($page, $pageSize, (int) $canonicalStoreId);
                        $items = $response['data'];
                        if ($items === []) {
                            break;
                        }

                        $counters['pages']++;
                        foreach ($items as $review) {
                            if (!is_array($review)) {
                                throw new InvalidArgumentException('Invalid review record');
                            }

                            $snapshot = $this->snapshotBuilder->build($review, (int) $canonicalStoreId);
                            $counters['fetched']++;
                            if (!$dryRun) {
                                $this->snapshotRepository->save($snapshot);
                                $counters['saved']++;
                            }
                        }

                        $pageCount = $response['meta']['page_count'] ?? null;
                        if (is_numeric($pageCount) && $page >= (int) $pageCount) {
                            break;
                        }

                        if (count($items) < $pageSize) {
                            break;
                        }

                        $page++;
                    }
                } catch (Throwable $exception) {
                    $totalFetched += $counters['fetched'];
                    $totalSaved += $counters['saved'];
                    $failedAccounts++;
                    $message = sprintf(
                        'Fera account store %d failed after pages=%d, fetched=%d, saved=%d: %s',
                        (int) $canonicalStoreId,
                        $counters['pages'],
                        $counters['fetched'],
                        $counters['saved'],
                        $exception->getMessage()
                    );
                    $output->writeln('<error>' . $message . '</error>');
                    $this->feraHelper->log($message);
                    if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                        $output->writeln($exception->getTraceAsString());
                    }
                    continue;
                }

                $totalFetched += $counters['fetched'];
                $totalSaved += $counters['saved'];
                $output->writeln(sprintf(
                    'Fera account store %d: pages=%d, fetched=%d, saved=%d%s%s',
                    (int) $canonicalStoreId,
                    $counters['pages'],
                    $counters['fetched'],
                    $counters['saved'],
                    $dryRun ? ', dry_run=true' : '',
                    $limited ? ', limited=true' : ''
                ));
            }

            $output->writeln(sprintf(
                'Backfill summary: accounts_failed=%d, fetched=%d, saved=%d, incomplete_snapshots=%d',
                $failedAccounts,
                $totalFetched,
                $totalSaved,
                $this->snapshotRepository->countIncomplete()
            ));

            return $failedAccounts > 0 ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        } catch (Throwable $exception) {
            $message = 'Fera review backfill could not be completed: ' . $exception->getMessage();
            $output->writeln('<error>' . $message . '</error>');
            $this->feraHelper->log($message);
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $output->writeln($exception->getTraceAsString());
            }
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * @return array<int, array<int>>
     */
    private function selectAccountGroups(InputInterface $input, OutputInterface $output): array
    {
        $groups = $this->storeGroupService->getByFeraAccount();
        $storeIdOption = $input->getOption('store-id');
        if ($storeIdOption === null || $storeIdOption === '') {
            if ($groups === []) {
                $output->writeln('<error>No enabled Fera accounts are configured.</error>');
            }

            return $groups;
        }

        $storeId = $this->readIntOption($storeIdOption, 'store-id', 1, null, null);
        $storeMap = $this->storeGroupService->getStoresToMainStoresMap();
        if (!isset($storeMap[$storeId])) {
            $output->writeln(sprintf('<error>Fera is not configured for Store %d.</error>', $storeId));
            return [];
        }

        $canonicalStoreId = $storeMap[$storeId];
        return isset($groups[$canonicalStoreId]) ? [$canonicalStoreId => $groups[$canonicalStoreId]] : [];
    }

    private function readIntOption(
        mixed $value,
        string $name,
        int $minimum,
        ?int $maximum,
        ?int $default
    ): int {
        if (($value === null || $value === '') && $default !== null) {
            return $default;
        }

        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException(sprintf('Option --%s must be an integer.', $name));
        }

        $stringValue = (string) $value;
        if (!preg_match('/^\d+$/', $stringValue)) {
            throw new InvalidArgumentException(sprintf('Option --%s must be an integer.', $name));
        }

        $result = (int) $stringValue;
        if ($result < $minimum || ($maximum !== null && $result > $maximum)) {
            $range = $maximum === null ? sprintf('%d or greater', $minimum) : sprintf('%d-%d', $minimum, $maximum);
            throw new InvalidArgumentException(sprintf('Option --%s must be %s.', $name, $range));
        }

        return $result;
    }
}
