<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ApiClient;

use Fera\Ai\Api\ApiClient\ProductsClientInterface;
use Fera\Ai\Exception\FeraApiException;
use Fera\Ai\Exception\HttpRequestException;
use Fera\Ai\Exception\ProductNotFoundException;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Services\ApiClient;

/**
 * @phpstan-import-type ProductData from ProductsClientInterface
 * @phpstan-import-type ProductsListResponse from ProductsClientInterface
 */
class ProductsClient implements ProductsClientInterface
{
    protected const BASE_ENDPOINT = 'v3/private/products';

    public function __construct(
        private ApiClient $apiClient,
        private FeraHelper $helper
    ) {
    }

    public function create(array $product, ?int $storeId = null): string
    {
        $response = $this->apiClient->post(static::BASE_ENDPOINT, $product, $storeId);

        $createdId = $response['id'] ?? null;
        if (!is_string($createdId) || $createdId === '') {
            throw new FeraApiException(sprintf(
                'Fera API create response missing id for product %s: %s',
                (string) ($product['external_id']),
                $this->helper->jsonEncode($response)
            ));
        }

        return $createdId;
    }

    public function update(string $feraId, array $product, ?int $storeId = null): void
    {
        $endpoint = static::BASE_ENDPOINT . '/' . $feraId;
        
        try {
            $this->apiClient->put($endpoint, $product, $storeId);
        } catch (HttpRequestException $e) {
            if ($e->getStatusCode() === 404) {
                $responseData = $e->getResponseData();
                if (is_array($responseData) && isset($responseData['code']) && $responseData['code'] === 'not_found') {
                    throw new ProductNotFoundException($feraId, '', 0, $e);
                }
            }
            
            throw $e;
        } catch (FeraApiException $e) {
            throw $e;
        }
    }

    public function list(int $page, int $pageSize, ?int $storeId = null): array
    {
        $endpoint = sprintf('%s?page=%d&page_size=%d', static::BASE_ENDPOINT, $page, $pageSize);
        $decoded = $this->apiClient->get($endpoint, $storeId);

        /** @var ProductsListResponse $result */
        $result = [
            'data' => isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [],
            'meta' => isset($decoded['meta']) && is_array($decoded['meta']) ? $decoded['meta'] : [],
        ];

        return $result;
    }
}
