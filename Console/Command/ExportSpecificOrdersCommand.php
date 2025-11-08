<?php

declare(strict_types=1);

namespace Fera\Ai\Console\Command;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\ResourceModel\FeraOrder as FeraOrderResource;
use Fera\Ai\Services\OrderExporter;
use Fera\Ai\Services\StoreGroupService;
use InvalidArgumentException;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ExportSpecificOrdersCommand extends Command
{
    private const NAME = 'fera:orders:export:specific';
    private const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private OrderCollectionFactory $orderCollectionFactory,
        private OrderRepositoryInterface $orderRepository,
        private OrderExporter $orderExporter,
        private FeraHelper $feraHelper,
        private StoreGroupService $storeGroupService,
        private AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Export specific orders listed in a CSV file to Fera.ai')
            ->addOption(
                'ids-csv',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to CSV file containing order entity IDs (single column, comma or semicolon delimiter)'
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Orders to process per batch',
                (string) self::DEFAULT_BATCH_SIZE
            )
            ->addOption('max', 'm', InputOption::VALUE_OPTIONAL, 'Maximum number of order IDs to process')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'List candidate orders without exporting them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            try {
                $this->appState->setAreaCode(Area::AREA_ADMINHTML);
            } catch (LocalizedException $e) {
                $this->feraHelper->debug('Area code set attempt ignored: ' . $e->getMessage());
            }

            $csvFilePath = $this->requireFilePathOption($input, 'ids-csv');
            $batchSize = $this->optionalIntOption($input, 'batch-size', self::DEFAULT_BATCH_SIZE);
            if ($batchSize === null || $batchSize <= 0) {
                $batchSize = self::DEFAULT_BATCH_SIZE;
            }

            $maxCount = $this->optionalIntOption($input, 'max');
            if ($maxCount !== null && $maxCount <= 0) {
                $maxCount = null;
            }

            $isDryRun = (bool) $input->getOption('dry-run');

            $counters = [
                'processed' => 0,
                'exported' => 0,
                'skippedNoFeraId' => 0,
                'alreadyExported' => 0,
                'missing' => 0,
                'storeNotConfigured' => 0,
                'errors' => 0,
            ];

            $this->readIdsStreamed(
                $csvFilePath,
                $batchSize,
                $maxCount,
                function (array $batchIds) use ($output, &$counters, $isDryRun): void {
                    $this->processBatch($batchIds, $output, $counters, $isDryRun);
                }
            );

            $output->writeln('');
            $output->writeln(
                sprintf(
                    'Export summary — exported: %d, skipped_no_fera_id: %d, already_exported: %d, '
                    . 'missing: %d, store_not_configured: %d, errors: %d, processed: %d',
                    $counters['exported'],
                    $counters['skippedNoFeraId'],
                    $counters['alreadyExported'],
                    $counters['missing'],
                    $counters['storeNotConfigured'],
                    $counters['errors'],
                    $counters['processed']
                )
            );

            if ($isDryRun) {
                $output->writeln('<info>Dry run completed. No orders were exported.</info>');
            }

            return $counters['errors'] > 0 ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        } finally {
            $this->orderExporter->_resetState();
        }
    }

    /**
     * Process a batch of order IDs
     *
     * @param list<int> $ids
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param array<string, int> $counters Pass by reference to update counters
     * @param bool $isDryRun
     * @return void
     */
    // phpcs:ignore Magento2.Commenting.FunctionComment.MissingParamCommentReference
    private function processBatch(array $ids, OutputInterface $output, array &$counters, bool $isDryRun): void
    {
        if (empty($ids)) {
            return;
        }

        $collection = $this->createBatchCollection($ids);
        
        $foundIds = [];
        $orderData = [];
        /** @var \Magento\Sales\Model\Order $order */
        foreach ($collection as $order) {
            $entityIdValue = $order->getEntityId();
            if (!is_numeric($entityIdValue)) {
                continue;
            }
            $entityId = (int) $entityIdValue;
            $foundIds[$entityId] = true;
            $orderData[$entityId] = [
                'entity_id' => $entityId,
                'store_id' => (int) $order->getStoreId(),
                'exported_order_id' => $order->getData('exported_order_id'),
            ];
        }

        $missingIds = array_diff($ids, array_keys($foundIds));
        if (!empty($missingIds)) {
            $counters['missing'] += count($missingIds);
            $counters['processed'] += count($missingIds);
            $output->writeln(sprintf(
                '<comment>Missing orders (not found in database): %s</comment>',
                implode(', ', $missingIds)
            ));
        }

        $storesToMainStores = $this->storeGroupService->getStoresToMainStoresMap();

        foreach ($ids as $entityId) {
            if (!isset($orderData[$entityId])) {
                continue;
            }

            $data = $orderData[$entityId];
            $storeId = $data['store_id'];
            $exportedOrderId = $data['exported_order_id'];

            if ($exportedOrderId !== null) {
                $counters['alreadyExported']++;
                $counters['processed']++;
                $output->writeln(sprintf('Order %d already exported, skipping', $entityId));
                continue;
            }

            if (!isset($storesToMainStores[$storeId])) {
                $counters['storeNotConfigured']++;
                $counters['processed']++;
                $output->writeln(sprintf(
                    '<comment>Order %d store %d is not configured for Fera, skipping</comment>',
                    $entityId,
                    $storeId
                ));
                continue;
            }

            try {
                $order = $this->orderRepository->get($entityId);
            } catch (Throwable $exception) {
                $counters['errors']++;
                $counters['processed']++;
                $message = sprintf('Failed to load order %d: %s', $entityId, $exception->getMessage());
                $output->writeln('<error>' . $message . '</error>');
                $this->feraHelper->log($message);
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $output->writeln($exception->getTraceAsString());
                }
                continue;
            }

            if ($isDryRun) {
                $counters['processed']++;
                $output->writeln(sprintf(
                    '[DRY RUN] Would export order %d (increment: %s, store: %d, total: %s)',
                    $entityId,
                    $order->getIncrementId(),
                    $storeId,
                    $order->getGrandTotal()
                ));
                continue;
            }

            try {
                $feraId = $this->orderExporter->pushOrder($order, ['backfill'], true);
            } catch (Throwable $exception) {
                $counters['errors']++;
                $counters['processed']++;
                $message = sprintf('Order %d export failed: %s', $entityId, $exception->getMessage());
                $output->writeln('<error>' . $message . '</error>');
                $this->feraHelper->log($message);
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $output->writeln($exception->getTraceAsString());
                }
                continue;
            }

            $counters['processed']++;

            if ($feraId === null) {
                $counters['skippedNoFeraId']++;
                $output->writeln(sprintf(
                    'Processed order %d but received no Fera ID',
                    $entityId
                ));
            } else {
                $counters['exported']++;
                $output->writeln(sprintf(
                    'Exported order %d, Fera ID %s. Total exported: %d',
                    $entityId,
                    $feraId,
                    $counters['exported']
                ));
            }
        }

        $this->orderExporter->_resetState();
    }

    /**
     * @param list<int> $ids
     */
    private function createBatchCollection(array $ids): OrderCollection
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToSelect(['entity_id', 'store_id']);
        $collection->addFieldToFilter('entity_id', ['in' => $ids]);

        $select = $collection->getSelect();
        $feraOrderTable = $collection->getTable(FeraOrderResource::TABLE_NAME);
        $select->joinLeft(
            ['fera_order' => $feraOrderTable],
            'main_table.entity_id = fera_order.order_id',
            ['exported_order_id' => 'fera_order.fera_id']
        );

        return $collection;
    }

    private function requireFilePathOption(InputInterface $input, string $optionName): string
    {
        $raw = $input->getOption($optionName);
        if (!is_string($raw) || trim($raw) === '') {
            throw new InvalidArgumentException(sprintf('Option --%s is required.', $optionName));
        }

        $filePath = trim($raw);

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException(sprintf('CSV file not found: %s', $filePath));
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (!is_readable($filePath)) {
            throw new InvalidArgumentException(sprintf('CSV file is not readable: %s', $filePath));
        }

        return $filePath;
    }

    private function optionalIntOption(InputInterface $input, string $optionName, ?int $default = null): ?int
    {
        $raw = $input->getOption($optionName);
        if ($raw === null || $raw === '') {
            return $default;
        }

        if (is_array($raw)) {
            throw new InvalidArgumentException(sprintf('Option --%s must be a single value.', $optionName));
        }

        if (!is_numeric($raw)) {
            throw new InvalidArgumentException(sprintf('Option --%s must be numeric.', $optionName));
        }

        return (int) $raw;
    }

    private function readIdsStreamed(string $filePath, int $batchSize, ?int $max, callable $onBatch): void
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException(sprintf('Failed to open CSV file: %s', $filePath));
        }

        try {
            $delimiter = null;
            $seenIds = [];
            $currentBatch = [];
            $totalProcessed = 0;
            $isFirstNonEmptyLine = true;

            // Detect delimiter from first non-empty line
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            while (($line = fgets($handle)) !== false) {
                $trimmedLine = trim($line);
                if ($trimmedLine === '') {
                    continue;
                }

                $delimiter = $this->detectDelimiterFromLine($trimmedLine);
                rewind($handle);
                break;
            }

            if ($delimiter === null) {
                return;
            }

            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if (empty($row)) {
                    continue;
                }

                $firstValue = $row[0] ?? '';
                if (!is_string($firstValue) || trim($firstValue) === '') {
                    continue;
                }

                $value = $isFirstNonEmptyLine ? $this->removeBom($firstValue) : $firstValue;
                $value = trim($value, "; \n\r\t\v\0");

                if ($isFirstNonEmptyLine) {
                    $isFirstNonEmptyLine = false;
                    if (!is_numeric($value)) {
                        // Skip header row
                        continue;
                    }
                }

                if ($value === '' || !is_numeric($value)) {
                    continue;
                }

                $id = (int) $value;
                if ($id <= 0) {
                    continue;
                }

                if (isset($seenIds[$id])) {
                    continue;
                }

                $seenIds[$id] = true;
                $currentBatch[] = $id;

                if (count($currentBatch) >= $batchSize) {
                    $onBatch($currentBatch);
                    $totalProcessed += count($currentBatch);
                    $currentBatch = [];

                    if ($max !== null && $totalProcessed >= $max) {
                        break;
                    }
                }
            }

            if (!empty($currentBatch)) {
                if ($max !== null) {
                    $remaining = $max - $totalProcessed;
                    if ($remaining > 0) {
                        $currentBatch = array_slice($currentBatch, 0, $remaining);
                        $onBatch($currentBatch);
                    }
                } else {
                    $onBatch($currentBatch);
                }
            }
        } finally {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            fclose($handle);
        }
    }

    private function detectDelimiterFromLine(string $line): string
    {
        $commaCount = substr_count($line, ',');
        $semicolonCount = substr_count($line, ';');

        return $semicolonCount > $commaCount ? ';' : ',';
    }

    private function removeBom(string $text): string
    {
        if (str_starts_with($text, "\x00\x00\xFE\xFF")) {
            return substr($text, 4);
        }
        if (str_starts_with($text, "\xFF\xFE\x00\x00")) {
            return substr($text, 4);
        }
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            return substr($text, 3);
        }
        if (str_starts_with($text, "\xFE\xFF")) {
            return substr($text, 2);
        }
        if (str_starts_with($text, "\xFF\xFE")) {
            return substr($text, 2);
        }

        return $text;
    }
}
