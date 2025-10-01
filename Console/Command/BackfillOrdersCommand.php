<?php

declare(strict_types=1);

namespace Fera\Ai\Console\Command;

use DateTimeImmutable;
use Exception;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\ResourceModel\FeraOrder as FeraOrderResource;
use Fera\Ai\Services\OrderExporter;
use Fera\Ai\Services\StoreGroupService;
use InvalidArgumentException;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Zend_Db_Expr;

class BackfillOrdersCommand extends Command
{
    private const NAME = 'fera:orders:backfill';
    private const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private OrderCollectionFactory $orderCollectionFactory,
        private OrderRepositoryInterface $orderRepository,
        private CustomerRepositoryInterface $customerRepository,
        private OrderExporter $orderExporter,
        private FeraHelper $feraHelper,
        private StoreGroupService $storeGroupService,
        private AppState $appState,
        private StoreManagerInterface $storeManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Export historical fulfilled orders to Fera.ai for one-time review requests')
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Lower bound for order fulfillment date (inclusive). '
                . 'Fulfillment date is the latest shipment date or order creation date if no shipments.'
            )
            ->addOption(
                'to',
                null,
                InputOption::VALUE_OPTIONAL,
                'Upper bound for order fulfillment date (inclusive). '
                . 'Fulfillment date is the latest shipment date or order creation date if no shipments.'
            )
            ->addOption('store-id', 's', InputOption::VALUE_OPTIONAL, 'Store ID to process')
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Orders to process per batch',
                (string) self::DEFAULT_BATCH_SIZE
            )
            ->addOption('max', 'm', InputOption::VALUE_OPTIONAL, 'Maximum number of orders to process during this run')
            ->addOption(
                'exclude-emails-csv',
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to CSV file containing emails to exclude from export'
            )
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

            $from = $this->requireDateOption($input, 'from');
            $to = $this->optionalDateOption($input, 'to');

            if ($to !== null && $to < $from) {
                throw new InvalidArgumentException('--to date must not be earlier than --from date');
            }

            $storeId = $this->optionalIntOption($input, 'store-id');
            $batchSize = $this->optionalIntOption($input, 'batch-size', self::DEFAULT_BATCH_SIZE);
            if ($batchSize === null || $batchSize <= 0) {
                $batchSize = self::DEFAULT_BATCH_SIZE;
            }

            $maxCount = $this->optionalIntOption($input, 'max');
            if ($maxCount !== null && $maxCount <= 0) {
                $maxCount = null;
            }

            $isDryRun = (bool) $input->getOption('dry-run');
            
            $excludeEmailsCsvPath = $input->getOption('exclude-emails-csv');
            $excludedEmails = [];
            if (is_string($excludeEmailsCsvPath) && trim($excludeEmailsCsvPath) !== '') {
                $excludedEmails = $this->loadExcludedEmails(trim($excludeEmailsCsvPath), $output);
            }

            $storeIds = $this->resolveStoreIds($storeId);
            if (empty($storeIds)) {
                $output->writeln('<error>No stores are configured and enabled for Fera.ai.</error>');
                return Cli::RETURN_FAILURE;
            }

            $globalExported = 0;
            $globalSkipped = 0;
            $globalExcluded = 0;
            $globalErrors = 0;
            $globalProcessed = 0;
            $maxReached = false;

            foreach ($storeIds as $currentStoreId) {
                if ($maxCount !== null && $globalProcessed >= $maxCount) {
                    $maxReached = true;
                    break;
                }

                try {
                    $store = $this->storeManager->getStore($currentStoreId);
                } catch (NoSuchEntityException $e) {
                    $output->writeln(sprintf('<error>Store ID %d not found: %s</error>', $currentStoreId, $e->getMessage()));
                    continue;
                }

                $storeName = (string) $store->getName();
                $storeCode = (string) $store->getCode();

                $output->writeln(sprintf('<info>Processing store: %s (%s, ID %d)</info>', $storeName, $storeCode, $currentStoreId));

                $candidatesCollection = $this->createCandidateCollection($currentStoreId, $from, $to);
                $storeTotal = (int) $candidatesCollection->getSize();

                if ($storeTotal === 0) {
                    $output->writeln('<comment>No matching orders found in this store.</comment>');
                    continue;
                }

                $effectiveTotal = $storeTotal;
                $remainingAllowance = $maxCount !== null ? $maxCount - $globalProcessed : null;
                if ($remainingAllowance !== null) {
                    $effectiveTotal = min($storeTotal, $remainingAllowance);
                }

                $output->writeln(sprintf('Found %d candidate orders (processing up to %d).', $storeTotal, $effectiveTotal));

                if ($isDryRun) {
                    $sampleData = $this->fetchSampleOrdersWithDates($currentStoreId, $from, $to, $batchSize);
                    if (!empty($sampleData)) {
                        $output->writeln('Sample orders:');
                        foreach ($sampleData as $orderData) {
                            $output->writeln(sprintf(
                                '  Order ID: %d, Fulfillment Date: %s',
                                $orderData['id'],
                                $orderData['fulfillment_date']
                            ));
                        }
                    }
                    continue;
                }

                $storeExported = 0;
                $storeSkipped = 0;
                $storeExcluded = 0;
                $storeErrors = 0;
                $lastProcessedId = 0;

                while (true) {
                    if ($maxCount !== null && $globalProcessed >= $maxCount) {
                        $maxReached = true;
                        break;
                    }

                    $batchIds = $this->fetchBatchIds($currentStoreId, $from, $to, $lastProcessedId, $batchSize);
                    if (empty($batchIds)) {
                        break;
                    }

                    foreach ($batchIds as $orderId) {
                        if ($maxCount !== null && $globalProcessed >= $maxCount) {
                            $maxReached = true;
                            break 2;
                        }

                        if ($orderId > $lastProcessedId) {
                            $lastProcessedId = $orderId;
                        }

                        try {
                            $order = $this->orderRepository->get($orderId);
                        } catch (Throwable $exception) {
                            $storeErrors++;
                            $globalErrors++;
                            $globalProcessed++;
                            $message = sprintf('Failed to load order %d: %s', $orderId, $exception->getMessage());
                            $output->writeln('<error>' . $message . '</error>');
                            $this->feraHelper->log($message);
                            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                                $output->writeln($exception->getTraceAsString());
                            }
                            continue;
                        }

                        if ($excludedEmails) {
                            try {
                                $customer = $order->getCustomerId() ? $this->customerRepository->getById((int) $order->getCustomerId()) : null;
                            } catch (NoSuchEntityException $e) {
                                $customer = null;
                            }

                            $customerEmail = null;
                            if ($customer && $customer->getEmail()) {
                                $customerEmail = strtolower(trim((string) $customer->getEmail()));
                            }

                            $orderEmail = strtolower(trim((string) $order->getCustomerEmail()));
                            if (($orderEmail && isset($excludedEmails[$orderEmail])) ||
                                ($customerEmail && isset($excludedEmails[$customerEmail]))
                            ) {
                                $storeExcluded++;
                                $globalExcluded++;
                                $globalProcessed++;

                                $output->writeln(sprintf(
                                    'Processed order %d with email %s (customer email %s) excluded from export',
                                    $orderId,
                                    $orderEmail,
                                    $customerEmail
                                ));

                                continue;
                            }
                        }

                        try {
                            $feraId = $this->orderExporter->pushOrder($order);

                            $globalProcessed++;

                            if ($feraId === null) {
                                $storeSkipped++;
                                $globalSkipped++;

                                $output->writeln(sprintf(
                                    'Processed order %d don\'t return a correct FeraAI ID',
                                    $orderId,
                                ));
                            } else {
                                $storeExported++;
                                $globalExported++;

                                $output->writeln(sprintf(
                                    'Exported order %d, FeraAI ID %s. Already processed %d and exported %d orders.',
                                    $orderId,
                                    $feraId,
                                    $globalProcessed,
                                    $globalExported
                                ));
                            }
                        } catch (Throwable $exception) {
                            $storeErrors++;
                            $globalErrors++;
                            $message = sprintf('Order %d export failed: %s', $orderId, $exception->getMessage());
                            $output->writeln('<error>' . $message . '</error>');
                            $this->feraHelper->log($message);
                            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                                $output->writeln($exception->getTraceAsString());
                            }
                        }
                    }

                    $this->orderExporter->_resetState();
                }

                $output->writeln(sprintf(
                    'Store summary — exported: %d, skipped: %d, excluded: %d, errors: %d',
                    $storeExported,
                    $storeSkipped,
                    $storeExcluded,
                    $storeErrors
                ));

                if ($maxReached) {
                    break;
                }
            }

            if ($isDryRun) {
                $output->writeln('<info>Dry run completed.</info>');
                return Cli::RETURN_SUCCESS;
            }

            if ($maxReached) {
                $output->writeln('<comment>Maximum order processing limit reached, stopping early.</comment>');
            }

            $output->writeln('');
            $output->writeln(sprintf(
                'Export summary — exported: %d, skipped: %d, excluded: %d, errors: %d',
                $globalExported,
                $globalSkipped,
                $globalExcluded,
                $globalErrors
            ));

            return $globalErrors > 0 ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        } finally {
            $this->orderExporter->_resetState();
        }
    }

    /**
     * @return list<int>
     */
    private function resolveStoreIds(?int $storeId): array
    {
        $storesToMainStores = $this->storeGroupService->getStoresToMainStoresMap();

        if ($storeId === null) {
            return array_keys($storesToMainStores);
        }

        if (!isset($storesToMainStores[$storeId])) {
            throw new InvalidArgumentException(
                sprintf('Store ID %d is not configured or not enabled for Fera.ai.', $storeId)
            );
        }

        return [$storeId];
    }

    private function requireDateOption(InputInterface $input, string $optionName): string
    {
        $raw = $input->getOption($optionName);
        if (!is_string($raw) || trim($raw) === '') {
            throw new InvalidArgumentException(sprintf('Option --%s is required.', $optionName));
        }

        return $this->parseDate($raw, '--' . $optionName)->format('Y-m-d H:i:s');
    }

    private function optionalDateOption(InputInterface $input, string $optionName): ?string
    {
        $rawValue = $input->getOption($optionName);
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return null;
        }

        $date = $this->parseDate($rawValue, '--' . $optionName);
        
        if ($optionName === 'to') {
            $trimmedValue = trim($rawValue);
            $userProvidedTime = preg_match('/\d{2}:\d{2}/', $trimmedValue) === 1;
            
            if (!$userProvidedTime) {
                $date = $date->setTime(23, 59, 59);
            }
        }
        
        return $date->format('Y-m-d H:i:s');
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

    private function parseDate(string $value, string $optionLabel): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception $exception) {
            throw new InvalidArgumentException(sprintf('Invalid %s value "%s": %s', $optionLabel, $value, $exception->getMessage()));
        }
    }

    /**
     * @return list<int>
     */
    private function fetchBatchIds(int $storeId, string $from, ?string $to, int $lastProcessedId, int $limit): array
    {
        $collection = $this->createCandidateCollection($storeId, $from, $to);
        if ($lastProcessedId > 0) {
            $collection->addFieldToFilter('entity_id', ['gt' => $lastProcessedId]);
        }
        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        /** @var list<int|string> $ids */
        $ids = $collection->getAllIds();

        return array_map(static fn($value) => (int) $value, $ids);
    }

    /**
     * @return list<int>
     */
    private function fetchSampleIds(int $storeId, string $from, ?string $to, int $limit): array
    {
        $collection = $this->createCandidateCollection($storeId, $from, $to);
        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        /** @var list<int|string> $ids */
        $ids = $collection->getAllIds();

        return array_map(static fn($value) => (int) $value, $ids);
    }

    /**
     * @return array<int, array{id: int, fulfillment_date: string}>
     */
    private function fetchSampleOrdersWithDates(int $storeId, string $from, ?string $to, int $limit): array
    {
        $collection = $this->createCandidateCollection($storeId, $from, $to);
        
        $select = $collection->getSelect();
        $fulfillmentDateExpr = new Zend_Db_Expr(
            'COALESCE(shipment_dates.latest_shipment_date, main_table.created_at)'
        );
        $select->columns(['fulfillment_date' => $fulfillmentDateExpr]);
        
        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        $result = [];
        foreach ($collection as $order) {
            $result[] = [
                'id' => (int) $order->getEntityId(),
                'fulfillment_date' => (string) $order->getData('fulfillment_date'),
            ];
        }

        return $result;
    }

    private function createCandidateCollection(int $storeId, string $from, ?string $to): OrderCollection
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToSelect('entity_id');
        $collection->addFieldToFilter('store_id', $storeId);
        $collection->addFieldToFilter('state', Order::STATE_COMPLETE);

        $select = $collection->getSelect();
        
        $shipmentTable = $collection->getTable('sales_shipment');
        $latestShipmentSubquery = $collection->getConnection()->select()
            ->from(
                ['shipment' => $shipmentTable],
                [
                    'order_id' => 'shipment.order_id',
                    'latest_shipment_date' => new Zend_Db_Expr('MAX(shipment.created_at)')
                ]
            )
            ->group('shipment.order_id');
        
        $select->joinLeft(
            ['shipment_dates' => $latestShipmentSubquery],
            'main_table.entity_id = shipment_dates.order_id',
            []
        );
        
        $fulfillmentDateExpr = new Zend_Db_Expr(
            'COALESCE(shipment_dates.latest_shipment_date, main_table.created_at)'
        );
        
        $select->where($fulfillmentDateExpr . ' >= ?', $from);
        if ($to !== null) {
            $select->where($fulfillmentDateExpr . ' <= ?', $to);
        }

        $feraOrderTable = $collection->getTable(FeraOrderResource::TABLE_NAME);
        $select->joinLeft(
            ['fera_order' => $feraOrderTable],
            'main_table.entity_id = fera_order.order_id',
            []
        );
        $select->where('fera_order.order_id IS NULL');

        return $collection;
    }

    /**
     * @return array<string, true> Associative array with email as key and true as value
     */
    private function loadExcludedEmails(string $filePath, OutputInterface $output): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException(sprintf('CSV file not found: %s', $filePath));
        }

        if (!is_readable($filePath)) {
            throw new InvalidArgumentException(sprintf('CSV file is not readable: %s', $filePath));
        }

        $excludedEmails = [];
        $totalLines = 0;
        $validEmails = 0;
        $invalidSkipped = 0;
        $duplicatesSkipped = 0;

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException(sprintf('Failed to open CSV file: %s', $filePath));
        }

        try {
            $isFirstLine = true;
            while (($row = fgetcsv($handle)) !== false) {
                $totalLines++;

                if (empty($row) || (count($row) === 1 && trim($row[0]) === '')) {
                    continue;
                }

                $email = $isFirstLine ? $this->removeBom($row[0]) : $row[0];

                $email = trim(strtolower($email), "; \n\r\t\v\0");

                if ($isFirstLine && $email === 'email') {
                    $isFirstLine = false;
                    continue;
                }
                $isFirstLine = false;

                if ($email === '') {
                    continue;
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $invalidSkipped++;
                    continue;
                }

                if (isset($excludedEmails[$email])) {
                    $duplicatesSkipped++;
                    continue;
                }

                $excludedEmails[$email] = true;
                $validEmails++;
            }
        } finally {
            fclose($handle);
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE || $validEmails === 0) {
            $output->writeln(sprintf(
                'Loaded %d exclusion emails from CSV (lines: %d, invalid: %d, duplicates: %d)',
                $validEmails,
                $totalLines,
                $invalidSkipped,
                $duplicatesSkipped
            ));
        }

        return $excludedEmails;
    }

    private function removeBom(string $text): string
    {
        // UTF-32 BE BOM
        if (str_starts_with($text, "\x00\x00\xFE\xFF")) {
            return substr($text, 4);
        }
        // UTF-32 LE BOM
        if (str_starts_with($text, "\xFF\xFE\x00\x00")) {
            return substr($text, 4);
        }
        // UTF-8 BOM
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            return substr($text, 3);
        }
        // UTF-16 BE BOM
        if (str_starts_with($text, "\xFE\xFF")) {
            return substr($text, 2);
        }
        // UTF-16 LE BOM
        if (str_starts_with($text, "\xFF\xFE")) {
            return substr($text, 2);
        }

        return $text;
    }
}
