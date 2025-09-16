<?php

declare(strict_types=1);

namespace Fera\Ai\Console\Command;

use Fera\Ai\Api\ApiClient\ProductsClientInterface;
use Fera\Ai\Model\ProductExportManager;
use Fera\Ai\Services\StoreGroupService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @phpstan-import-type ProductsListResponse from ProductsClientInterface
 */
class BackfillProductMappingsCommand extends Command
{
    private const NAME = 'fera:products:backfill-mappings';

    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private ProductExportManager $exportManager,
        private State $appState,
        private ProductsClientInterface $productsClient,
        private StoreGroupService $storeGroupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Backfill local Fera product mappings by querying Fera API')
            ->addOption('store-id', null, InputOption::VALUE_OPTIONAL, 'Store ID', null)
            ->addOption('page-size', null, InputOption::VALUE_OPTIONAL, 'Items per page', '100')
            ->addOption('max-pages', null, InputOption::VALUE_OPTIONAL, 'Maximum pages to fetch (0 = no limit)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            try {
                $this->appState->setAreaCode('adminhtml');
            } catch (\Exception $e) {
                // Area code may be already set
            }

            $storeIdOpt = $input->getOption('store-id');
            $storeId = is_numeric($storeIdOpt) ? (int) $storeIdOpt : null;
            $pageSizeOpt = $input->getOption('page-size');
            $pageSize = is_numeric($pageSizeOpt) ? (int) $pageSizeOpt : 100;
            $maxPagesOpt = $input->getOption('max-pages');
            $maxPages = is_numeric($maxPagesOpt) ? (int) $maxPagesOpt : 0;
            
            if ($pageSize < 1) {
                $pageSize = 100;
            }

            $storesGroups = $this->storeGroupService->getByFeraAccount();
            $storesToMainStores = $this->storeGroupService->getStoresToMainStoresMap();
            if ($storeId !== null) {
                if (!isset($storesToMainStores[$storeId])) {
                    $output->writeln("<error>Fera is not configured for Store {$storeId}.</error>");
                    return Cli::RETURN_FAILURE;
                }
                $mainStoreId = $storesToMainStores[$storeId];

                $storesGroups = array_filter($storesGroups, function ($key) use ($mainStoreId) {
                    return $key === $mainStoreId;
                }, ARRAY_FILTER_USE_KEY);
            }

            $storeIds = array_keys($storesGroups);

            $inserted = 0;
            $updated = 0;
            $deleted = 0;
            $skipped = 0;
            $missingLocal = 0;

            foreach ($storeIds as $currentStoreId) {
                $output->writeln(sprintf('Processing store ID: %d', $currentStoreId));

                $output->writeln(sprintf('[Store %d] Fetching all Fera products...', $currentStoreId));
                $allFeraProducts = $this->fetchAllFeraProducts($currentStoreId, $pageSize, $maxPages);
                $output->writeln(sprintf('[Store %d] Fetched %d products from Fera.', $currentStoreId, count($allFeraProducts)));

                if (empty($allFeraProducts)) {
                    $output->writeln(sprintf('[Store %d] No products found in Fera. Skipping.', $currentStoreId));
                    continue;
                }

                $localIdToRemoteFeraIdMap = [];
                foreach ($allFeraProducts as $row) {
                    $localIdToRemoteFeraIdMap[(int) $row['external_id']] = (string) $row['id'];
                }

                $productIds = array_keys($localIdToRemoteFeraIdMap);

                $builder = $this->searchCriteriaBuilderFactory->create();
                $searchCriteria = $builder->addFilter('entity_id', $productIds, 'in')->create();
                $result = $this->productRepository->getList($searchCriteria);

                $localProducts = [];
                foreach ($result->getItems() as $product) {
                    $localProducts[(int) $product->getId()] = $product;
                }

                $existingMap = $this->exportManager->getFeraIdsByProductIds(
                    array_keys($localProducts),
                    $currentStoreId
                );

                $storeInserted = 0;
                $storeUpdated = 0;
                $storeSkipped = 0;
                $storeMissingLocal = 0;

                foreach ($localIdToRemoteFeraIdMap as $productId => $feraId) {
                    if (!isset($localProducts[$productId])) {
                        $storeMissingLocal++;
                        continue;
                    }
                    if (isset($existingMap[$productId])) {
                        if ($existingMap[$productId] !== $feraId) {
                            $this->exportManager->updateFeraId(
                                $productId,
                                $feraId,
                                $currentStoreId
                            );
                            $storeUpdated++;
                        } else {
                            $storeSkipped++;
                        }
                        continue;
                    }
                    $this->exportManager->saveSuccessfulExport(
                        $localProducts[$productId],
                        $feraId,
                        $currentStoreId
                    );
                    $storeInserted++;
                }

                $output->writeln(sprintf(
                    '[Store %d] Processed: inserted=%d, updated=%d, skipped=%d, missing_local=%d',
                    $currentStoreId,
                    $storeInserted,
                    $storeUpdated,
                    $storeSkipped,
                    $storeMissingLocal
                ));

                $allLocalMappings = $this->exportManager->getAllMappingsByStore($currentStoreId);
                $remoteFeraIds = array_values($localIdToRemoteFeraIdMap);
                $staleMappings = array_diff(array_values($allLocalMappings), $remoteFeraIds);

                if (!empty($staleMappings)) {
                    $output->writeln(sprintf('[Store %d] Deleting %d stale mappings...', $currentStoreId, count($staleMappings)));
                    $deletedInStore = $this->exportManager->deleteMappingsByFeraIds($staleMappings, $currentStoreId);
                    $deleted += $deletedInStore;
                    $output->writeln(sprintf('[Store %d] Deleted %d stale mappings.', $currentStoreId, $deletedInStore));
                }


                $inserted += $storeInserted;
                $updated += $storeUpdated;
                $skipped += $storeSkipped;
                $missingLocal += $storeMissingLocal;
            }

            $output->writeln(sprintf(
                'Done. Total: inserted=%d, updated=%d, deleted=%d, skipped=%d, missing_local=%d',
                $inserted,
                $updated,
                $deleted,
                $skipped,
                $missingLocal
            ));
            return Cli::RETURN_SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('Error: %s', $e->getMessage()));
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * @param int $storeId
     * @param int $pageSize
     * @param int $maxPages
     * @return array
     * @throws \Fera\Ai\Exception\FeraApiException
     * @phpstan-return ProductsListResponse['data']
     */
    private function fetchAllFeraProducts(int $storeId, int $pageSize, int $maxPages): array
    {
        $allProducts = [];
        $page = 1;
        $stop = false;

        while (!$stop) {
            if ($maxPages > 0 && $page > $maxPages) {
                break;
            }

            $decoded = $this->productsClient->list($page, $pageSize, $storeId);
            $items = $decoded['data'];

            if (empty($items)) {
                break;
            }

            $allProducts = array_merge($allProducts, $items);

            $meta = $decoded['meta'] ?? [];
            $pageCount = isset($meta['page_count']) ? (int) $meta['page_count'] : 0;
            $curPage = isset($meta['page']) ? (int) $meta['page'] : $page;

            if ($pageCount > 0 && $curPage >= $pageCount) {
                $stop = true;
            } else {
                $page++;
            }
        }

        return $allProducts;
    }
}
