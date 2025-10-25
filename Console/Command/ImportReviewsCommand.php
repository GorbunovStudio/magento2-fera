<?php

declare(strict_types=1);

namespace Fera\Ai\Console\Command;

use Fera\Ai\Api\ApiClient\ReviewsClientInterface;
use Fera\Ai\Exception\HttpRequestException;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Services\StoreGroupService;
use InvalidArgumentException;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * @phpstan-import-type FeraReviewData from ReviewsClientInterface
 */
class ImportReviewsCommand extends Command
{
    private const NAME = 'fera:reviews:import';
    private const DEFAULT_BATCH_SIZE = 100;

    private const COL_EXTERNAL_ORDER_ID = 0;
    private const COL_EXTERNAL_CUSTOMER_ID = 1;
    private const COL_PRODUCT_ID = 2;
    private const COL_HEADING = 3;
    private const COL_BODY = 4;
    private const COL_RATING = 5;
    private const COL_STATE = 6;
    private const COL_IS_VERIFIED = 7;
    private const COL_CREATED_AT = 8;
    private const COL_STORE_REPLY = 10;
    private const COL_STORE_REPLIED_AT = 11;
    private const COL_CUSTOMER_MEDIA_1 = 12;
    private const COL_CUSTOMER_MEDIA_2 = 13;

    private const EXPECTED_HEADERS = [
        'External Order ID',
        'External Customer ID',
        'Product ID',
        'Heading',
        'Body',
        'Rating',
        'State',
        'Is Verified',
        'Created At',
        'Updated At',
        'Store Reply',
        'Store Replied At',
        'Customer Media 1',
        'Customer Media 2',
    ];

    private const VALID_STATES = [
        'pending_approval',
        'pending_update',
        'approved',
        'declined_approval',
    ];

    public function __construct(
        private ReviewsClientInterface $reviewsClient,
        private FeraHelper $feraHelper,
        private StoreGroupService $storeGroupService,
        private AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Import reviews from CSV file to Fera.ai')
            ->addOption(
                'csv',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to CSV file containing reviews to import'
            )
            ->addOption(
                'store-id',
                's',
                InputOption::VALUE_REQUIRED,
                'Store ID (required if multiple stores are enabled)'
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Reviews to process per batch',
                (string) self::DEFAULT_BATCH_SIZE
            )
            ->addOption('max', 'm', InputOption::VALUE_OPTIONAL, 'Maximum number of reviews to process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            try {
                $this->appState->setAreaCode(Area::AREA_ADMINHTML);
            } catch (LocalizedException $e) {
                $this->feraHelper->debug('Area code set attempt ignored: ' . $e->getMessage());
            }

            $csvFilePath = $this->requireFilePathOption($input, 'csv');
            $batchSize = $this->optionalIntOption($input, 'batch-size', self::DEFAULT_BATCH_SIZE);
            if ($batchSize === null || $batchSize <= 0) {
                $batchSize = self::DEFAULT_BATCH_SIZE;
            }

            $maxCount = $this->optionalIntOption($input, 'max');
            if ($maxCount !== null && $maxCount <= 0) {
                $maxCount = null;
            }

            $storeId = $this->resolveStoreId($input);

            $counters = [
                'processed' => 0,
                'imported' => 0,
                'duplicates' => 0,
                'invalid' => 0,
                'errors' => 0,
            ];

            $this->readAndProcessCsv(
                $csvFilePath,
                $storeId,
                $batchSize,
                $maxCount,
                $output,
                $counters
            );

            $output->writeln('');
            $output->writeln(sprintf(
                'Import summary — imported: %d, duplicates: %d, invalid: %d, errors: %d, processed: %d',
                $counters['imported'],
                $counters['duplicates'],
                $counters['invalid'],
                $counters['errors'],
                $counters['processed']
            ));

            return $counters['errors'] > 0 ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
    }

    private function resolveStoreId(InputInterface $input): int
    {
        $storesToMainStores = $this->storeGroupService->getStoresToMainStoresMap();

        if (empty($storesToMainStores)) {
            throw new InvalidArgumentException(
                'No stores are configured and enabled for Fera.ai.'
            );
        }

        $storeIdOption = $input->getOption('store-id');
        if ($storeIdOption === null || $storeIdOption === '') {
            if (count($storesToMainStores) > 1) {
                throw new InvalidArgumentException(
                    'Multiple stores are enabled for Fera.ai. Please specify --store-id.'
                );
            }

            return array_key_first($storesToMainStores);
        }

        if (!is_numeric($storeIdOption)) {
            throw new InvalidArgumentException('Option --store-id must be numeric.');
        }

        $storeId = (int) $storeIdOption;
        if (!isset($storesToMainStores[$storeId])) {
            throw new InvalidArgumentException(
                sprintf('Store ID %d is not configured or not enabled for Fera.ai.', $storeId)
            );
        }

        return $storesToMainStores[$storeId];
    }

    /**
     * Read and process CSV file in batches
     *
     * @param string $filePath
     * @param int $storeId
     * @param int $batchSize
     * @param int|null $maxCount
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param array<string, int> $counters
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    // phpcs:ignore Magento2.Commenting.FunctionComment.MissingParamCommentReference
    private function readAndProcessCsv(
        string $filePath,
        int $storeId,
        int $batchSize,
        ?int $maxCount,
        OutputInterface $output,
        array &$counters
    ): void {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException(sprintf('Failed to open CSV file: %s', $filePath));
        }

        try {
            $delimiter = $this->detectDelimiter($handle);
            $this->validateHeaders($handle, $delimiter);

            $currentBatch = [];
            $lineNumber = 1;

            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNumber++;

                if ($maxCount !== null && $counters['processed'] >= $maxCount) {
                    break;
                }

                if (empty($row) || (count($row) === 1 && trim($row[0]) === '')) {
                    continue;
                }

                $currentBatch[] = ['row' => $row, 'line' => $lineNumber];

                if (count($currentBatch) >= $batchSize) {
                    $this->processBatch($currentBatch, $storeId, $output, $counters);
                    $currentBatch = [];
                }
            }

            if (!empty($currentBatch)) {
                if ($maxCount !== null) {
                    $remaining = $maxCount - $counters['processed'];
                    if ($remaining > 0) {
                        $currentBatch = array_slice($currentBatch, 0, $remaining);
                        $this->processBatch($currentBatch, $storeId, $output, $counters);
                    }
                } else {
                    $this->processBatch($currentBatch, $storeId, $output, $counters);
                }
            }
        } finally {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @return string
     */
    private function detectDelimiter($handle): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $position = ftell($handle);
        if ($position === false) {
            $position = 0;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        while (($line = fgets($handle)) !== false) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '') {
                continue;
            }

            $commaCount = substr_count($trimmedLine, ',');
            $semicolonCount = substr_count($trimmedLine, ';');

            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            fseek($handle, $position);
            return $semicolonCount > $commaCount ? ';' : ',';
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        fseek($handle, $position);
        return ',';
    }

    /**
     * @param resource $handle
     * @param string $delimiter
     * @return void
     */
    private function validateHeaders($handle, string $delimiter): void
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $headerRow = fgetcsv($handle, 0, $delimiter);
        if ($headerRow === false || empty($headerRow)) {
            throw new InvalidArgumentException('CSV file is empty or has no header row.');
        }

        $headerRow[0] = $this->removeBom($headerRow[0]);

        if (count($headerRow) < count(self::EXPECTED_HEADERS)) {
            throw new InvalidArgumentException(sprintf(
                'CSV header has %d columns, expected at least %d.',
                count($headerRow),
                count(self::EXPECTED_HEADERS)
            ));
        }

        foreach (self::EXPECTED_HEADERS as $index => $expectedHeader) {
            if (!isset($headerRow[$index]) || trim($headerRow[$index]) !== $expectedHeader) {
                throw new InvalidArgumentException(sprintf(
                    'CSV header mismatch at column %d: expected "%s", got "%s".',
                    $index + 1,
                    $expectedHeader,
                    $headerRow[$index] ?? ''
                ));
            }
        }
    }

    /**
     * Process a batch of review rows
     *
     * @param array<int, array{row: array<int, string>, line: int}> $batch
     * @param int $storeId
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param array<string, int> $counters
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    // phpcs:ignore Magento2.Commenting.FunctionComment.MissingParamCommentReference
    private function processBatch(array $batch, int $storeId, OutputInterface $output, array &$counters): void
    {
        foreach ($batch as $item) {
            $row = $item['row'];
            $lineNumber = $item['line'];

            try {
                $reviewData = $this->validateAndMapRow($row, $lineNumber);
            } catch (InvalidArgumentException $exception) {
                $counters['invalid']++;
                $counters['processed']++;
                $output->writeln(sprintf(
                    '<error>Line %d: %s</error>',
                    $lineNumber,
                    $exception->getMessage()
                ));
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $output->writeln($exception->getTraceAsString());
                }
                continue;
            }

            try {
                $feraId = $this->reviewsClient->create($reviewData, $storeId);
                $counters['imported']++;
                $counters['processed']++;
                $output->writeln(sprintf(
                    'Imported review (line %d) for product %s, Fera ID: %s. Total imported: %d',
                    $lineNumber,
                    $reviewData['product_id'],
                    $feraId,
                    $counters['imported']
                ));
            } catch (HttpRequestException $exception) {
                if ($exception->getStatusCode() === 422) {
                    $counters['duplicates']++;
                    $counters['processed']++;
                    $output->writeln(sprintf(
                        '<comment>Line %d: Duplicate review for customer %s and product %s, skipping</comment>',
                        $lineNumber,
                        $reviewData['external_customer_id'],
                        $reviewData['product_id']
                    ));
                } else {
                    $counters['errors']++;
                    $counters['processed']++;
                    $message = sprintf('Line %d: API error: %s', $lineNumber, $exception->getMessage());
                    $output->writeln('<error>' . $message . '</error>');
                    $this->feraHelper->log($message);
                    if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                        $output->writeln($exception->getTraceAsString());
                    }
                }
            } catch (Throwable $exception) {
                $counters['errors']++;
                $counters['processed']++;
                $message = sprintf('Line %d: Unexpected error: %s', $lineNumber, $exception->getMessage());
                $output->writeln('<error>' . $message . '</error>');
                $this->feraHelper->log($message);
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $output->writeln($exception->getTraceAsString());
                }
            }
        }
    }

    /**
     * Validate and map a CSV row to review data
     *
     * @param array<int, string> $row
     * @param int $lineNumber
     * @return array
     * @phpstan-return FeraReviewData
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    // phpcs:ignore Magento2.Commenting.FunctionComment.MissingParamCommentReference
    private function validateAndMapRow(array $row, int $lineNumber): array
    {
        $externalOrderId = trim($row[self::COL_EXTERNAL_ORDER_ID] ?? '');
        $externalCustomerId = trim($row[self::COL_EXTERNAL_CUSTOMER_ID] ?? '');
        $productId = trim($row[self::COL_PRODUCT_ID] ?? '');
        $heading = trim($row[self::COL_HEADING] ?? '');
        $body = trim($row[self::COL_BODY] ?? '');
        $ratingRaw = trim($row[self::COL_RATING] ?? '');
        $state = trim($row[self::COL_STATE] ?? '');
        $isVerifiedRaw = trim($row[self::COL_IS_VERIFIED] ?? '');
        $createdAt = trim($row[self::COL_CREATED_AT] ?? '');
        $storeReply = trim($row[self::COL_STORE_REPLY] ?? '');
        $storeRepliedAt = trim($row[self::COL_STORE_REPLIED_AT] ?? '');
        $customerMedia1 = trim($row[self::COL_CUSTOMER_MEDIA_1] ?? '');
        $customerMedia2 = trim($row[self::COL_CUSTOMER_MEDIA_2] ?? '');

        if ($externalOrderId === '') {
            throw new InvalidArgumentException('External Order ID is required');
        }

        if ($externalCustomerId === '') {
            throw new InvalidArgumentException('External Customer ID is required');
        }

        if ($productId === '') {
            throw new InvalidArgumentException('Product ID is required');
        }

        if ($body === '') {
            throw new InvalidArgumentException('Body is required');
        }

        if ($ratingRaw === '') {
            throw new InvalidArgumentException('Rating is required');
        }

        if (!is_numeric($ratingRaw)) {
            throw new InvalidArgumentException(sprintf('Rating must be numeric, got: %s', $ratingRaw));
        }

        $rating = (int) $ratingRaw;
        if ($rating < 1 || $rating > 5) {
            throw new InvalidArgumentException(sprintf('Rating must be between 1 and 5, got: %d', $rating));
        }

        if ($state === '') {
            throw new InvalidArgumentException('State is required');
        }

        if (!in_array($state, self::VALID_STATES, true)) {
            throw new InvalidArgumentException(sprintf(
                'State must be one of %s, got: %s',
                implode(', ', self::VALID_STATES),
                $state
            ));
        }

        if ($isVerifiedRaw === '') {
            throw new InvalidArgumentException('Is Verified is required');
        }

        $isVerified = $this->parseBool($isVerifiedRaw);
        if ($isVerified === null) {
            throw new InvalidArgumentException(sprintf('Is Verified has invalid value: %s', $isVerifiedRaw));
        }

        if ($createdAt === '') {
            throw new InvalidArgumentException('Created At is required');
        }

        try {
            $createdAtIso = $this->feraHelper->formatDate($createdAt);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(sprintf('Invalid Created At date: %s', $createdAt), 0, $exception);
        }

        $reviewData = [
            'body' => $body,
            'rating' => $rating,
            'state' => $state,
            'created_at' => $createdAtIso,
            'is_verified' => $isVerified,
            'product_id' => $productId,
            'external_order_id' => $externalOrderId,
            'external_customer_id' => $externalCustomerId,
        ];

        if ($heading !== '') {
            $reviewData['heading'] = $heading;
        }

        $media = [];
        if ($customerMedia1 !== '') {
            $media[] = $customerMedia1;
        }
        if ($customerMedia2 !== '') {
            $media[] = $customerMedia2;
        }
        if (!empty($media)) {
            $reviewData['media'] = $media;
        }

        if ($storeReply !== '') {
            $storeReplyData = ['body' => $storeReply];
            if ($storeRepliedAt !== '') {
                try {
                    $storeReplyData['created_at'] = $this->feraHelper->formatDate($storeRepliedAt);
                } catch (Throwable $exception) {
                    throw new InvalidArgumentException(
                        sprintf('Invalid Store Replied At date: %s', $storeRepliedAt),
                        0,
                        $exception
                    );
                }
            }
            $reviewData['store_reply'] = $storeReplyData;
        }

        return $reviewData;
    }

    private function parseBool(string $value): ?bool
    {
        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['true', '1', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['false', '0', 'no', 'n', 'off'], true)) {
            return false;
        }

        return null;
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
