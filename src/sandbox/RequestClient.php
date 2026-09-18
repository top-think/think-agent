<?php

namespace think\agent\sandbox;

use GuzzleHttp\Exception\RequestException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use think\Exception;

/**
 * Internal HTTP transport for endpoints that do not require a sandbox ID.
 */
class RequestClient
{
    protected \GuzzleHttp\Client $http;
    protected string $baseUrl;

    public function __construct(protected string $apiUrl, array $options = [])
    {
        $baseUrl = rtrim($apiUrl, '/');
        $this->baseUrl = preg_match('#/api/v1$#i', $baseUrl) ? $baseUrl . '/' : $baseUrl . '/api/v1/';
        $this->http = new \GuzzleHttp\Client(array_replace([
            'timeout'     => 300,
            'verify'      => false,
            'http_errors' => true,
            'headers'     => ['Accept' => 'application/json'],
        ], $options));
    }

    public function get(string $uri, array $query = []): array
    {
        return $this->request('GET', $uri, $query ? ['query' => $query] : []);
    }

    public function post(string $uri, array $data = [], bool $multipart = false, array $query = []): array
    {
        return $this->request('POST', $uri, array_merge(
            $multipart ? ['multipart' => $data] : ['json' => $data],
            $query ? ['query' => $query] : [],
        ));
    }

    public function delete(string $uri, array $query = []): array
    {
        return $this->request('DELETE', $uri, $query ? ['query' => $query] : []);
    }

    public function request(string $method, string $uri, array $options = []): array
    {
        try {
            $response = $this->http->request($method, $this->url($uri), $options);
            $data = $this->decode($response);
        } catch (RequestException $e) {
            throw $this->requestException($e);
        }

        $code = $data['code'] ?? $response->getStatusCode();
        if (!is_numeric($code) || (int) $code < 200 || (int) $code >= 300) {
            throw new Exception($data['message'] ?? ('Sandbox API request failed with status ' . $code));
        }

        return $data;
    }

    public function rawRequest(string $method, string $uri, array $options): ResponseInterface
    {
        try {
            return $this->http->request($method, $this->url($uri), $options);
        } catch (RequestException $e) {
            throw $this->requestException($e);
        }
    }

    protected function url(string $uri): string
    {
        return preg_match('#^https?://#i', $uri) ? $uri : $this->baseUrl . ltrim($uri, '/');
    }

    protected function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($body === '') throw new Exception('Sandbox API returned an empty response');

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new Exception('Invalid sandbox API response: ' . $e->getMessage());
        }

        if (!is_array($data)) throw new Exception('Invalid sandbox API response');
        return $data;
    }

    protected function requestException(RequestException $exception): Exception
    {
        $response = $exception->getResponse();
        if (!$response) return new Exception('Sandbox API request failed: ' . $exception->getMessage());

        try {
            $message = $this->decode($response)['message'] ?? null;
        } catch (Exception) {
            $message = null;
        }

        return new Exception($message ?: ('Sandbox API request failed with status ' . $response->getStatusCode()));
    }
}
