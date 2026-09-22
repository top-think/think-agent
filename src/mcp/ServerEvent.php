<?php

namespace think\agent\mcp;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Utils;
use Psr\Http\Message\StreamInterface;
use stdClass;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use think\helper\Arr;
use think\helper\Str;

class ServerEvent extends Transport
{

    public function __construct(
        protected Client          $httpClient,
        protected string          $endpoint,
        protected Channel         $channel,
        protected StreamInterface $stream
    )
    {

    }

    protected function initialize()
    {
        $this->sendRequest('initialize', [
            "protocolVersion" => '2024-11-05',
            "capabilities"    => new stdClass(),
            "clientInfo"      => [
                "name"    => "ThinkAI",
                "version" => "1.0.0",
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
        $this->stream->close();
    }

    protected function sendRequest($method, $params = null, $id = true, $timeout = 3)
    {
        $data = [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params ?? new stdClass(),
        ];

        if ($id) {
            $data['id'] = $this->getNextId();
        }

        $response = $this->httpClient->post($this->endpoint, [
            'json'    => $data,
            'timeout' => 3,
        ]);

        $statusCode = $response->getStatusCode();
        $isOk       = $statusCode >= 200 && $statusCode < 300;

        if (!$isOk) {
            throw new Exception('Failed to send request to MCP');
        }

        if ($id) {
            return $this->channel->pop($timeout);
        }
    }

    public static function connect($url, $headers = null): self
    {
        $handler = new HandlerStack(Utils::chooseHandler());

        $httpClient = new Client([
            'base_uri' => $url,
            'handler'  => $handler,
            'verify'   => false,
        ]);

        $response = $httpClient->get('', [
            'stream' => true,
        ]);

        $statusCode  = $response->getStatusCode();
        $isOk        = $statusCode == 200;
        $contentType = $response->getHeaderLine('Content-Type');

        if ($isOk && Str::startsWith($contentType, 'text/event-stream')) {
            $body = $response->getBody();
            if ($body instanceof StreamInterface) {
                $channel = new Channel(1);

                Coroutine::create(function () use ($httpClient, $channel, $body) {
                    foreach (static::getMessages($body) as $message) {
                        switch ($message['event']) {
                            case 'endpoint':
                                $session = new self($httpClient, $message['data'], $channel, $body);
                                $channel->push($session);
                                break;
                            case 'message':
                                $data = json_decode($message['data'], true);
                                $channel->push($data['result']);
                                break;
                        }
                    }
                });

                $session = $channel->pop(3);
                if ($session instanceof ServerEvent) {
                    $session->initialize();
                    return $session;
                }
            }
        }

        throw new Exception('Failed to connect to MCP');
    }

}
