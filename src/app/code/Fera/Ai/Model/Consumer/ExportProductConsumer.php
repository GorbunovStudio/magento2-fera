<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Fera\Ai\Services\ProductExporter;
use Fera\Ai\Helper\Data as FeraHelper;
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
     * ExportProductConsumer constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param ProductExporter $productExporter
     * @param FeraHelper $helper
     * @param LoggerInterface $logger
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductExporter $productExporter,
        FeraHelper $helper,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->productExporter = $productExporter;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    /**
     * Process product export message
     *
     * @param int $productId
     */
    public function process(int $productId): void
    {
        try {
            if (!$this->helper->isEnabled()) {
                return;
            }

            $product = $this->productRepository->getById($productId);

            $this->productExporter->pushProduct($product);

            $this->logger->info('Fera AI: Product exported successfully', ['product_id' => $productId]);

        } catch (NoSuchEntityException $e) {
            $this->logger->error('Fera AI: Product not found for export', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
        } catch (LocalizedException $e) {
            $this->logger->error('Fera AI: Error exporting product', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Fera AI: Unexpected error exporting product', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
