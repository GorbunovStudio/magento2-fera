<?php

namespace Fera\Ai\Console\Command;

use Exception;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Services\ProductExporter;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportProductsCommand extends Command
{
    private CollectionFactory $productCollectionFactory;
    private ProductExporter $productExporter;
    private FeraHelper $feraHelper;
    private AppState $appState;
    private Emulation $emulation;
    private StoreManagerInterface $storeManager;

    public function __construct(
        CollectionFactory $productCollectionFactory,
        ProductExporter $productExporter,
        FeraHelper $feraHelper,
        AppState $appState,
        Emulation $emulation,
        StoreManagerInterface $storeManager
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productExporter = $productExporter;
        $this->feraHelper = $feraHelper;
        $this->appState = $appState;
        $this->emulation = $emulation;
        $this->storeManager = $storeManager;
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
        if (!$this->feraHelper->isEnabled()) {
            $output->writeln('<error>Fera.ai module is not enabled or not properly configured.</error>');
            return 1;
        }

        $storeId = (int) $input->getOption('store-id');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;

        try {
            try {
                $this->appState->setAreaCode(Area::AREA_FRONTEND);
            } catch (LocalizedException $e) {
                $this->feraHelper->debug('Area code set attempt ignored: ' . $e->getMessage());
            }

            $stores = [];
            if ($storeId > 0) {
                $stores[] = $this->storeManager->getStore($storeId);
            } else {
                $stores = array_values($this->storeManager->getStores(false));
            }

            $totalExported = 0;
            $totalErrors = 0;

            foreach ($stores as $store) {
                $storeId = (int) $store->getId();
                $storeName = (string) $store->getName();
                $storeCode = (string) $store->getCode();
                $output->writeln(sprintf('<info>Processing store: %s (%s)</info>', $storeName, $storeCode));

                $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
                try {
                    $productCollection = $this->getProductCollection($storeId, $limit);
                    $totalProducts = $productCollection->getSize();

                    if ($totalProducts === 0) {
                        $output->writeln('<comment>No products found to export in this store.</comment>');
                        continue;
                    }

                    $output->writeln("Total products found in store: {$totalProducts}");
                    
                    $productsToExport = $limit !== null ? min($totalProducts, $limit) : $totalProducts;
                    $output->writeln("Exporting {$productsToExport} products...");

                    $exported = 0;
                    $errors = 0;
                    $batchSize = 50;
                    $productBatch = [];

                    foreach ($productCollection as $product) {
                        $productBatch[] = $product;
                        
                        if (count($productBatch) >= $batchSize) {
                            $result = $this->processBatch($productBatch, $output);
                            $exported += $result['exported'];
                            $errors += $result['errors'];
                            $productBatch = [];
                        }
                    }
                    
                    if (!empty($productBatch)) {
                        $result = $this->processBatch($productBatch, $output);
                        $exported += $result['exported'];
                        $errors += $result['errors'];
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

            $output->writeln("Export completed across stores. Total exported: {$totalExported} products");
            if ($totalErrors > 0) {
                $output->writeln("Total errors encountered: {$totalErrors} products");
            }

            return $totalErrors > 0 ? 1 : 0;
        } catch (Exception $e) {
            $output->writeln("<error>Error during export: {$e->getMessage()}</error>");
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $output->writeln($e->getTraceAsString());
            }
            $this->feraHelper->log('Console export error: ' . $e->getMessage());
            return 1;
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

        $collection->addAttributeToFilter('status', 1);

        $this->excludeSimpleProductsInBundles($collection);

        if ($limit) {
            $collection->setPageSize($limit);
        }

        return $collection;
    }

    private function excludeSimpleProductsInBundles(Collection $collection): void
    {
        $bundleCollection = $this->productCollectionFactory->create();
        $bundleCollection->addAttributeToFilter('type_id', 'bundle');
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
     * @param array $productBatch
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return array
     */
    private function processBatch(array $productBatch, OutputInterface $output): array
    {
        $exported = 0;
        $errors = 0;

        try {
            $this->productExporter->pushProducts($productBatch);
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
