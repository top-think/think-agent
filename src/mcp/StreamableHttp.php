<?php

namespace think\agent\mcp;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Utils;
use Psr\Http\Message\StreamInterface;
use stdClass;
use think\helper\Arr;
use think\helper\Str;
use Throwable;

class StreamableHttp extends Transport
{
    public function __construct(
        protected Client $httpClient
    )
    {
    }

    protected function initialize()
    {
        $this->sendRequest('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => new stdClass(),
            'clientInfo'      => [
                'name'    => 'ThinkAI',
                'version' => '1.0.0',
            ],
        ]);

        $this->sendRequest('notifications/initialized', id: false);
    }

    public function listTools()
    {
        $result = $this->sendRequest('tools/list');
        return Arr::get($result, 'tools');
    }

    public function callTool(string $name, ?array $arguments = null)
    {
        return $this->sendRequest('tools/call', [
            'name'      => $name,
            'arguments' => $arguments ?? new stdClass(),
        ], timeout: 60);
    }

    public function close()
    {
        // StreamableHttp 不需要显式关闭连接
        // HTTP 连接会在请求完成后自动关闭
    }

    protected function sendRequest($method, $params = null, $id = true, $timeout = 10)
    {
        $data = [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params ?? new stdClass(),
        ];

        if ($id) {
            $data['id'] = $this->getNextId();
        }

        $headers = [
            'Accept'               => 'application/json, text/event-stream',
            'Content-Type'         => 'application/json',
            'MCP-Protocol-Version' => '2025-06-18',
        ];

        // 如果有 session ID，添加到请求头
        if ($this->getSessionId()) {
            $headers['Mcp-Session-Id'] = $this->getSessionId();
        }

        $response = $this->httpClient->post('', [
            'json'    => $data,
            'headers' => $headers,
            'timeout' => $timeout,
        ]);

        $statusCode = $response->getStatusCode();

        // 处理不同的状态码
        if ($statusCode == 202) {
            // 202 Accepted - 用于 notifications 和 responses
            return null;
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new Exception("HTTP Error {$statusCode}: Failed to send request to MCP");
        }

        // 检查响应中是否有 session ID
        if ($response->hasHeader('Mcp-Session-Id')) {
            $this->setSessionId($response->getHeaderLine('Mcp-Session-Id'));
        }

        $contentType = $response->getHeaderLine('Content-Type');

        // 如果是 SSE 流
        if (Str::startsWith($contentType, 'text/event-stream')) {
            return $this->handleSseResponse($response->getBody(), $id, $timeout);
        } else {
            // 直接返回 JSON 响应
            return $this->handleJsonResponse($response->getBody());
        }
    }

    protected function handleJsonResponse(StreamInterface $body)
    {
        $responseData = json_decode($body->getContents(), true);

        if (isset($responseData['result'])) {
            return $responseData['result'];
        } elseif (isset($responseData['error'])) {
            throw new Exception('MCP Error: ' . $responseData['error']['message']);
        }

        return $responseData;
    }

    protected function handleSseResponse(StreamInterface $body, $expectResponse = true, $timeout = 3)
    {
        if (!$expectResponse) {
            return null;
        }

        // 对于 SSE 响应，我们需要读取流直到找到对应的响应
        foreach (static::getMessages($body) as $message) {
            if (isset($message['data'])) {
                $data = json_decode($message['data'], true);
                if ($data && isset($data['id'])) {
                    // 找到对应的响应
                    if (isset($data['result'])) {
                        return $data['result'];
                    } elseif (isset($data['error'])) {
                        throw new Exception($data['error']['message']);
                    }
                    return $data;
                }
            }
        }

        throw new Exception('No response received from SSE stream');
    }

    public static function connect($url, $headers = null): self
    {
        $handler = new HandlerStack(Utils::chooseHandler());

        $httpClient = new Client([
            'base_uri' => $url,
            'handler'  => $handler,
            'headers'  => $headers,
            'verify'   => false,
        ]);

        // 创建实例
        $instance = new self($httpClient);

        // 尝试初始化连接
        try {
            $instance->initialize();
            return $instance;
        } catch (Throwable $e) {
            throw new Exception('Failed to connect to MCP: ' . $e->getMessage());
        }
    }
}
