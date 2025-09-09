<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Fera\Ai\Services\ProductExporter;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Api\Data\ProductExportMessageDataInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class ExportProductConsumer
{
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var ProductExporter
     */
    private $productExporter;

    /**
     * @var FeraHelper
     */
    private $helper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * ExportProductConsumer constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param ProductExporter $productExporter
     * @param FeraHelper $helper
     * @param LoggerInterface $logger
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductExporter $productExporter,
        FeraHelper $helper,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection
    ) {
        $this->productRepository = $productRepository;
        $this->productExporter = $productExporter;
        $this->helper = $helper;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Process product export message
     *
     * @param ProductExportMessageDataInterface $message
     */
    public function process(ProductExportMessageDataInterface $message): void
    {
        $productId = $message->getProductId();
        $storeId = $message->getStoreId();
        
        if ($productId === null || $storeId === null) {
            $this->logger->warning("Invalid product export message: productId={$productId}, storeId={$storeId}");
            return;
        }

        $dbConnection = $this->resourceConnection->getConnection();
        $dbConnection->beginTransaction();

        try {
            if (!$this->helper->isEnabled($storeId)) {
                $dbConnection->rollBack();
                return;
            }

            $product = $this->productRepository->getById($productId, false, $storeId);

            $this->productExporter->pushProduct($product, $storeId);

            $dbConnection->commit();
            $this->logger->info("Successfully exported product: {$productId} for store: {$storeId}");
        } catch (\Throwable $exception) {
            $dbConnection->rollBack();

            $this->logger->error(
                $exception->getMessage(),
                ['exception' => $exception, 'trace' => $exception->getTrace()]
            );
            
            throw new \RuntimeException(
                'Unable to process the queue message: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }
    }
}
