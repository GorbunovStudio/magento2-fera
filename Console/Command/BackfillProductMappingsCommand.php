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
            $skippedExisting = 0;
            $missingLocal = 0;
            $seen = 0;

            foreach ($storeIds as $currentStoreId) {
                $output->writeln(sprintf('Processing store ID: %d', $currentStoreId));
                
                $page = 1;
                $storeInserted = 0;
                $storeSkippedExisting = 0;
                $storeMissingLocal = 0;
                $storeSeen = 0;
                $stop = false;

                while (!$stop) {
                    if ($maxPages > 0 && $page > $maxPages) {
                        break;
                    }

                    $decoded = $this->productsClient->list($page, $pageSize, $currentStoreId);

                    $items = $decoded['data'];
                    if (empty($items)) {
                        break;
                    }

                    $idMap = [];
                    foreach ($items as $row) {
                        $idMap[(int) $row['external_id']] = (string) $row['id'];
                    }
                    $storeSeen += count($idMap);

                    $productIds = array_keys($idMap);
                    $builder = $this->searchCriteriaBuilderFactory->create();
                    $searchCriteria = $builder->addFilter('entity_id', $productIds, 'in')->create();
                    $result = $this->productRepository->getList($searchCriteria);
                    $localProducts = [];
                    foreach ($result->getItems() as $product) {
                        $localProducts[(int) $product->getId()] = $product;
                    }

                    $existingMap = $this->exportManager->getFeraIdsByProductIds(array_keys($localProducts));

                    foreach ($idMap as $productId => $feraId) {
                        if (!isset($localProducts[$productId])) {
                            $storeMissingLocal++;
                            continue;
                        }
                        if (isset($existingMap[$productId])) {
                            $storeSkippedExisting++;
                            continue;
                        }
                        $this->exportManager->saveSuccessfulExport($localProducts[$productId], $feraId);
                        $storeInserted++;
                    }

                    $meta = $decoded['meta'] ?? [];
                    $pageCount = isset($meta['page_count']) ? (int) $meta['page_count'] : 0;
                    $curPage = isset($meta['page']) ? (int) $meta['page'] : $page;
                    $output->writeln(sprintf(
                        '[Store %d] Processed page %d%s: seen=%d, inserted=%d, skipped=%d, missing=%d',
                        $currentStoreId,
                        $curPage,
                        $pageCount > 0 ? sprintf('/%d', $pageCount) : '',
                        $storeSeen,
                        $storeInserted,
                        $storeSkippedExisting,
                        $storeMissingLocal
                    ));

                    if ($pageCount > 0 && $curPage >= $pageCount) {
                        $stop = true;
                    } else {
                        $page++;
                    }
                }

                $output->writeln(sprintf(
                    '[Store %d] Completed: seen=%d, inserted=%d, skipped=%d, missing=%d',
                    $currentStoreId,
                    $storeSeen,
                    $storeInserted,
                    $storeSkippedExisting,
                    $storeMissingLocal
                ));

                $seen += $storeSeen;
                $inserted += $storeInserted;
                $skippedExisting += $storeSkippedExisting;
                $missingLocal += $storeMissingLocal;
            }

            $output->writeln(sprintf(
                'Done. Total: seen=%d, inserted=%d, skipped=%d, missing=%d',
                $seen,
                $inserted,
                $skippedExisting,
                $missingLocal
            ));
            return Cli::RETURN_SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('Error: %s', $e->getMessage()));
            return Cli::RETURN_FAILURE;
        }
    }
}
