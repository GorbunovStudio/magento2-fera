<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Exception\FeraApiException;
use Fera\Ai\Helper\Data as FeraHelper;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\Serialize\Serializer\Json;

class ApiClient
{
    private ClientFactory $clientFactory;
    private FeraHelper $helper;
    private Json $json;

    public function __construct(
        ClientFactory $clientFactory,
        FeraHelper $helper,
        Json $json
    ) {
        $this->clientFactory = $clientFactory;
        $this->helper = $helper;
        $this->json = $json;
    }

    /**
     * @param string $endpoint
     * @param int|null $storeId
     * @return array<mixed>
     * @throws FeraApiException
     */
    public function get(string $endpoint, ?int $storeId = null): array
    {
        return $this->request('GET', $endpoint, [], $storeId);
    }

    /**
     * @param string $endpoint
     * @param array<mixed> $data
     * @param int|null $storeId
     * @return array<mixed>
     * @throws FeraApiException
     */
    public function post(string $endpoint, array $data, ?int $storeId = null): array
    {
        return $this->request('POST', $endpoint, $data, $storeId);
    }

    /**
     * @param string $endpoint
     * @param array<mixed> $data
     * @param int|null $storeId
     * @return array<mixed>
     * @throws FeraApiException
     */
    public function put(string $endpoint, array $data, ?int $storeId = null): array
    {
        return $this->request('PUT', $endpoint, $data, $storeId);
    }

    /**
     * @param string $method
     * @param string $endpoint
     * @param array<mixed> $data
     * @param int|null $storeId
     * @return array<mixed>
     * @throws FeraApiException
     */
    private function request(string $method, string $endpoint, array $data, ?int $storeId = null): array
    {
        $client = $this->clientFactory->create(['config' => [
            'base_uri' => $this->helper->getApiUrl($storeId),
            'headers' => [
                'Content-Type' => 'application/json',
                'SECRET-KEY' => $this->helper->getSecretKey($storeId),
            ],
        ]]);

        $requestOptions = [];
        $methodsWithBody = ['POST', 'PUT', 'PATCH'];
        if (in_array(strtoupper($method), $methodsWithBody, true)) {
            $requestOptions['json'] = $data;
        }

        try {
            $response = $client->request($method, $endpoint, $requestOptions);
        } catch (GuzzleException $e) {
            throw new FeraApiException(sprintf(
                'Fera API request failed for endpoint %s: %s',
                $endpoint,
                $e->getMessage()
            ), 0, $e);
        }

        $responseBody = $response->getBody()->getContents();
        if ($response->getStatusCode() === 204 || $responseBody === '') {
            return [];
        }

        $decoded = $this->json->unserialize($responseBody);
        if (!is_array($decoded)) {
            throw new FeraApiException(sprintf(
                'Invalid JSON response from Fera API for endpoint %s: %s',
                $endpoint,
                $responseBody
            ));
        }

        return $decoded;
    }
}
