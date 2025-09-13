<?php

declare(strict_types=1);

namespace Fera\Ai\Service;

use Fera\Ai\Exception\FeraApiException;
use Fera\Ai\Helper\Data as FeraHelper;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use function is_array;

class ApiClient
{
    private CurlFactory $curlFactory;
    private FeraHelper $helper;
    private Json $json;

    public function __construct(
        CurlFactory $curlFactory,
        FeraHelper $helper,
        Json $json
    ) {
        $this->curlFactory = $curlFactory;
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
        $url = $this->helper->getApiUrl($storeId) . $endpoint;
        $curl = $this->curlFactory->create();

        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('SECRET-KEY', $this->helper->getSecretKey($storeId));

        $payload = $this->json->serialize($data);
        if (!is_string($payload)) {
            throw new \InvalidArgumentException('Unable to serialize value.');
        }

        switch ($method) {
            case 'POST':
                $curl->post($url, $payload);
                break;
            case 'PUT':
                $curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
                $curl->post($url, $payload);
                break;
            case 'GET':
                $curl->get($url);
                break;
        }

        $response = $curl->getBody();
        $httpCode = (int) $curl->getStatus();

        $successCodes = $method === 'POST' ? [200, 201] : [200, 202, 204];

        if (!in_array($httpCode, $successCodes, true)) {
            throw new FeraApiException(sprintf(
                'Fera API request failed for endpoint %s. HTTP Status: %s, Response: %s',
                $endpoint,
                $httpCode,
                $response
            ));
        }

        if ($httpCode === 204) {
            return [];
        }

        $decoded = $this->json->unserialize($response);
        if (!is_array($decoded)) {
            throw new FeraApiException(sprintf(
                'Invalid JSON response from Fera API for endpoint %s: %s',
                $endpoint,
                $response
            ));
        }

        return $decoded;
    }
}
