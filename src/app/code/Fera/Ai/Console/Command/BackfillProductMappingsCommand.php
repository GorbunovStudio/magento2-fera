<?php

declare(strict_types=1);

namespace Fera\Ai\Console\Command;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\ProductExportManager;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\App\State;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackfillProductMappingsCommand extends Command
{
    private const NAME = 'fera:products:backfill-mappings';

    private FeraHelper $helper;
    private CurlFactory $curlFactory;
    private ProductRepositoryInterface $productRepository;
    private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory;
    private ProductExportManager $exportManager;
    private State $appState;
    public function __construct(
        FeraHelper $helper,
        CurlFactory $curlFactory,
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        ProductExportManager $exportManager,
        State $appState
    ) {
        parent::__construct();
        $this->helper = $helper;
        $this->curlFactory = $curlFactory;
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilderFactory;
        $this->exportManager = $exportManager;
        $this->appState = $appState;
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
            $storeId = $storeIdOpt !== null ? (int) $storeIdOpt : null;
            $pageSize = (int) $input->getOption('page-size');
            $maxPages = (int) $input->getOption('max-pages');
            if ($pageSize < 1) {
                $pageSize = 100;
            }

            $page = 1;
            $inserted = 0;
            $skippedExisting = 0;
            $missingLocal = 0;
            $seen = 0;
            $stop = false;

            $baseUrl = rtrim($this->helper->getApiUrl($storeId), '/');
            $endpoint = $baseUrl . '/v3/private/products';

            while (!$stop) {
                if ($maxPages > 0 && $page > $maxPages) {
                    break;
                }

                $url = sprintf('%s?page=%d&page_size=%d', $endpoint, $page, $pageSize);
                $curl = $this->curlFactory->create();
                $curl->addHeader('Accept', 'application/json');
                $curl->addHeader('SECRET-KEY', $this->helper->getSecretKey($storeId));
                $curl->get($url);

                $status = $curl->getStatus();
                $body = $curl->getBody();
                if ($status !== 200) {
                    $output->writeln(sprintf('Request failed. HTTP %d on page %d. Body: %s', $status, $page, $body));
                    return Cli::RETURN_FAILURE;
                }

                /** @var array{data?: array<int, array{id?: string, external_id?: string}>, meta?: array{page?: int, page_count?: int}}|null $decoded */
                $decoded = json_decode($body, true);
                if (!is_array($decoded)) {
                    $output->writeln('Invalid JSON response');
                    return Cli::RETURN_FAILURE;
                }
                $items = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
                if (empty($items)) {
                    break;
                }

                $idMap = [];
                foreach ($items as $row) {
                    $idMap[(int) $row['external_id']] = (string) $row['id'];
                }
                if (empty($idMap)) {
                    break;
                }
                $seen += count($idMap);

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
                        $missingLocal++;
                        continue;
                    }
                    if (isset($existingMap[$productId])) {
                        $skippedExisting++;
                        continue;
                    }
                    $this->exportManager->saveSuccessfulExport($localProducts[$productId], $feraId);
                    $inserted++;
                }

                $meta = isset($decoded['meta']) && is_array($decoded['meta']) ? $decoded['meta'] : [];
                $pageCount = isset($meta['page_count']) ? (int) $meta['page_count'] : 0;
                $curPage = isset($meta['page']) ? (int) $meta['page'] : $page;
                $output->writeln(sprintf('Processed page %d%s: seen=%d, inserted=%d, skipped=%d, missing=%d',
                    $curPage,
                    $pageCount > 0 ? sprintf('/%d', $pageCount) : '',
                    $seen,
                    $inserted,
                    $skippedExisting,
                    $missingLocal
                ));

                if ($pageCount > 0 && $curPage >= $pageCount) {
                    $stop = true;
                } else {
                    $page++;
                }
            }

            $output->writeln(sprintf('Done. Total: seen=%d, inserted=%d, skipped=%d, missing=%d',
                $seen, $inserted, $skippedExisting, $missingLocal));
            return Cli::RETURN_SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('Error: %s', $e->getMessage()));
            return Cli::RETURN_FAILURE;
        }
    }
}
