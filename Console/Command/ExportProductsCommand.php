<?php

declare(strict_types=1);

namespace Fera\Ai\Console\Command;

use Exception;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Services\ProductExporter;
use Fera\Ai\Services\StoreGroupService;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportProductsCommand extends Command
{
    public function __construct(
        private CollectionFactory $productCollectionFactory,
        private ProductExporter $productExporter,
        private FeraHelper $feraHelper,
        private AppState $appState,
        private Emulation $emulation,
        private StoreManagerInterface $storeManager,
        private StoreGroupService $storeGroupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('fera:products:export')
            ->setDescription('Export current products to Fera.ai')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Limit number of products to export',
                null
            )
            ->addOption(
                'store-id',
                's',
                InputOption::VALUE_OPTIONAL,
                'Store ID to export products from',
                0
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeId = $input->getOption('store-id');
        $storeId = is_numeric($storeId) ? (int) $storeId : null;
        $limit = $input->getOption('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;

        try {
            try {
                $this->appState->setAreaCode(Area::AREA_FRONTEND);
            } catch (LocalizedException $e) {
                $this->feraHelper->debug('Area code set attempt ignored: ' . $e->getMessage());
            }

            $storesGroups = $this->storeGroupService->getByFeraAccount();
            $storesToMainStores = $this->storeGroupService->getStoresToMainStoresMap();

            if ($storeId !== null) {
                $mainStoreId = $storesToMainStores[$storeId] ?? null;
                if ($mainStoreId === null) {
                    $output->writeln("<error>Fera is not configured for Store {$storeId}.</error>");
                    return Cli::RETURN_FAILURE;
                }

                $storesGroups = [$mainStoreId => $storesGroups[$mainStoreId]];
            }

            $storeIds = array_keys($storesGroups);

            $totalExported = 0;
            $totalErrors = 0;
            $processedStores = 0;

            foreach ($storeIds as $storeId) {
                $store = $this->storeManager->getStore($storeId);
                $storeName = (string) $store->getName();
                $storeCode = (string) $store->getCode();
                $output->writeln(sprintf('<info>Processing store: %s (%s)</info>', $storeName, $storeCode));

                $processedStores++;

                $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
                try {
                    $baseCollection = $this->getProductCollection($storeId, null);
                    $totalProducts = $baseCollection->getSize();

                    if ($totalProducts === 0) {
                        $output->writeln('<comment>No products found to export in this store.</comment>');
                        continue;
                    }

                    $output->writeln("Total products found in store: {$totalProducts}");
                    
                    $productsToExport = $limit !== null ? min($totalProducts, $limit) : $totalProducts;
                    $output->writeln("Exporting {$productsToExport} products...");

                    $exported = 0;
                    $errors = 0;
                    $currentPage = 1;
                    $processedCount = 0;
                    $pageSize = 50;

                    while ($processedCount < $productsToExport) {
                        $remainingProducts = $productsToExport - $processedCount;
                        $currentPageSize = min($pageSize, $remainingProducts);
                        
                        $pageCollection = $this->getProductCollection($storeId, null);
                        $pageCollection->setPageSize($currentPageSize);
                        $pageCollection->setCurPage($currentPage);

                        /** @var \Magento\Catalog\Api\Data\ProductInterface[] $pageProducts */
                        $pageProducts = $pageCollection->getItems();
                        
                        if (empty($pageProducts)) {
                            // No more products to process
                            break;
                        }
                        
                        $output->writeln(sprintf(
                            'Processing page %d: %d products (processed %d/%d)',
                            $currentPage,
                            count($pageProducts),
                            $processedCount,
                            $productsToExport
                        ));
                        
                        $result = $this->processBatch($pageProducts, $output, $storeId);
                        $exported += $result['exported'];
                        $errors += $result['errors'];
                        
                        $processedCount += count($pageProducts);
                        $currentPage++;
                        
                        // Clear the collection to free memory
                        $pageCollection->clear();
                        unset($pageCollection, $pageProducts);
                    }

                    $output->writeln(
                        "Store '{$storeName}' export completed. Successfully exported: {$exported} products"
                    );
                    if ($errors > 0) {
                        $output->writeln("Errors encountered in store '{$storeName}': {$errors} products");
                    }

                    $totalExported += $exported;
                    $totalErrors += $errors;
                } finally {
                    try {
                        $this->emulation->stopEnvironmentEmulation();
                    } catch (Exception $e) {
                        $this->feraHelper->debug('Failed to stop environment emulation: ' . $e->getMessage());
                    }
                }
            }

            if ($processedStores === 0) {
                $output->writeln(
                    '<error>No stores processed. Fera.ai module is not enabled for any of the selected stores.</error>'
                );
                return Cli::RETURN_FAILURE;
            }

            $output->writeln("Export completed across stores. Total exported: {$totalExported} products");
            if ($totalErrors > 0) {
                $output->writeln("Total errors encountered: {$totalErrors} products");
            }

            return $totalErrors > 0 ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
        } catch (Exception $e) {
            $output->writeln("<error>Error during export: {$e->getMessage()}</error>");
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $output->writeln($e->getTraceAsString());
            }
            $this->feraHelper->log('Console export error: ' . $e->getMessage());
            return Cli::RETURN_FAILURE;
        }
    }

    private function getProductCollection(int $storeId, ?int $limit): Collection
    {
        $collection = $this->productCollectionFactory->create();

        if ($storeId > 0) {
            $collection->setStoreId($storeId);
            
            $store = $this->storeManager->getStore($storeId);
            $websiteId = (int) $store->getWebsiteId();
            $collection->addWebsiteFilter($websiteId);
        }

        $collection->addAttributeToSelect([
            'name', 'sku', 'price', 'status', 'visibility', 'type_id', 'created_at', 'updated_at',
        ]);

        // @phpstan-ignore argument.type
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->setOrder('entity_id', 'ASC');

        $this->excludeSimpleProductsInBundles($collection);

        if ($limit) {
            $collection->setPageSize($limit);
        }

        return $collection;
    }

    private function excludeSimpleProductsInBundles(Collection $collection): void
    {
        $bundleCollection = $this->productCollectionFactory->create();
        // @phpstan-ignore argument.type
        $bundleCollection->addAttributeToFilter('type_id', 'bundle');
        // @phpstan-ignore argument.type
        $bundleCollection->addAttributeToFilter('status', 1);

        $bundleProductIds = $bundleCollection->getAllIds();

        if (empty($bundleProductIds)) {
            return;
        }

        $connection = $collection->getConnection();
        $catalogProductBundleSelectionTable = $collection->getTable('catalog_product_bundle_selection');

        $select = $connection->select()
            ->from($catalogProductBundleSelectionTable, 'product_id')
            ->where('parent_product_id IN (?)', $bundleProductIds);

        $childProductIds = $connection->fetchCol($select);

        if (!empty($childProductIds)) {
            $collection->addAttributeToFilter('entity_id', ['nin' => $childProductIds]);
        }
    }

    /**
     * Process a batch of products and return export results
     *
     * @param array<\Magento\Catalog\Api\Data\ProductInterface> $productBatch
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return int[]
     * @phpstan-return array{exported: int, errors: int}
     */
    private function processBatch(array $productBatch, OutputInterface $output, int $storeId): array
    {
        $exported = 0;
        $errors = 0;

        try {
            $this->productExporter->pushProducts($productBatch, $storeId);
            $exported = count($productBatch);
        } catch (Exception $e) {
            $errors = count($productBatch);
            $output->writeln(sprintf(
                '<error>Batch export failed for %d products: %s</error>',
                count($productBatch),
                $e->getMessage()
            ));
            $this->feraHelper->log('Batch export failed: ' . $e->getMessage());
        }

        return ['exported' => $exported, 'errors' => $errors];
    }
}
