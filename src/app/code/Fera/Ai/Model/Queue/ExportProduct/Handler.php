<?php

namespace Fera\Ai\Model\Queue\ExportProduct;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductRepository;
use Fera\Ai\Services\ProductExporter;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Api\Data\Queue\ExportProduct\MessageInterface;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use RuntimeException;

class Handler
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
     * @param MessageInterface $message
     */
    public function process(MessageInterface $message): void
    {
        $productId = $message->getProductId();
        $storeId = $message->getStoreId();

        try {
            if ($productId === null || $storeId === null) {
                throw new InvalidArgumentException("Invalid product export message: productId={$productId}, storeId={$storeId}");
            }

            if (!$this->helper->isEnabled($storeId)) {
                return;
            }

            $product = $this->productRepository->getById($productId, false, $storeId);

            $this->productExporter->pushProduct($product, $storeId);
        } catch (\Throwable $exception) {
            $this->logger->error(
                $exception->getMessage(),
                ['exception' => $exception, 'trace' => $exception->getTrace()]
            );
            
            throw new \RuntimeException(
                'Unable to process the queue message: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        } finally {
            if (!$this->productRepository instanceof ProductRepository) {
                $type = is_object($this->productRepository) ? get_class($this->productRepository) : gettype($this->productRepository);
                throw new RuntimeException(
                    'Incorrect type for ProductRepository, expected ' . ProductRepositoryInterface::class . ', got ' . $type
                );
            }
            
            // Reset the repository state to avoid stale data issues
            $this->productRepository->_resetState();
        }
    }
}
