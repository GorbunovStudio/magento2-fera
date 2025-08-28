<?php

namespace Fera\Ai\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Fera\Ai\Services\ProductExporter;
use Fera\Ai\Helper\Data as FeraHelper;

class ExportProductsCommand extends Command
{
    const COMMAND_NAME = 'fera:products:export';

    private $productCollectionFactory;
    private $productExporter;
    private $feraHelper;

    public function __construct(
        CollectionFactory $productCollectionFactory,
        ProductExporter $productExporter,
        FeraHelper $feraHelper
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productExporter = $productExporter;
        $this->feraHelper = $feraHelper;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName(self::COMMAND_NAME)
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->feraHelper->isEnabled()) {
            $output->writeln('<error>Fera.ai module is not enabled or not properly configured.</error>');
            return 1;
        }

        $storeId = (int) $input->getOption('store-id');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;

        try {
            $productCollection = $this->getProductCollection($storeId, $limit);
            $totalProducts = $productCollection->getSize();

            if ($totalProducts === 0) {
                $output->writeln('<comment>No products found to export.</comment>');
                return 0;
            }

            $output->writeln("Exporting {$totalProducts} products...");

            $exported = 0;
            $errors = 0;

            foreach ($productCollection as $product) {
                try {
                    $this->productExporter->pushProduct($product);
                    $exported++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->feraHelper->log("Error exporting product {$product->getId()}: " . $e->getMessage());
                }
            }

            $output->writeln("Export completed. Successfully exported: {$exported} products");
            
            if ($errors > 0) {
                $output->writeln("Errors encountered: {$errors} products (check logs for details)");
            }

            return 0;

        } catch (\Exception $e) {
            $output->writeln("<error>Error during export: {$e->getMessage()}</error>");
            $this->feraHelper->log("Console export error: " . $e->getMessage());
            return 1;
        }
    }

    private function getProductCollection($storeId, $limit)
    {
        $collection = $this->productCollectionFactory->create();
        
        if ($storeId > 0) {
            $collection->setStoreId($storeId);
        }

        $collection->addAttributeToSelect([
            'name', 'sku', 'price', 'status', 'visibility', 'type_id', 'created_at', 'updated_at'
        ]);

        $collection->addAttributeToFilter('status', 1);
        $collection->addAttributeToFilter('visibility', ['in' => [2, 3, 4]]);

        $this->excludeSimpleProductsInBundles($collection);

        if ($limit) {
            $collection->setPageSize($limit);
        }

        return $collection;
    }

    private function excludeSimpleProductsInBundles($collection)
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
}
