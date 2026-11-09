<?php

$autoload = getenv('THINK_AGENT_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
require $autoload;
require_once dirname(__DIR__) . '/src/sandbox/Client.php';
require_once dirname(__DIR__) . '/src/sandbox/RequestClient.php';
require_once dirname(__DIR__) . '/src/sandbox/HttpClient.php';

use think\agent\sandbox\HttpClient;
use think\agent\sandbox\RequestClient;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class RecordingRequestClient extends RequestClient
{
    public array $requests = [];

    public function __construct()
    {
    }

    public function get(string $uri, array $query = []): array
    {
        $this->requests[] = compact('uri', 'query');

        return [
            'data' => [
                'path' => $query['path'] ?? '/workspace',
                'files' => [],
            ],
        ];
    }
}

$request = new RecordingRequestClient();
$client = new HttpClient($request, 'sandbox-id');

$client->listFiles('/workspace/shared');
check(
    ($request->requests[0]['query']['path'] ?? null) === '/workspace/shared',
    'listFiles 应传递共享文件夹路径'
);

$client->listFiles('/workspace/shared/projects');
check(
    ($request->requests[1]['query']['path'] ?? null) === '/workspace/shared/projects',
    'listFiles 应传递嵌套目录路径'
);

$client->listFiles();
check(
    $request->requests[2]['query'] === [],
    'listFiles 未指定路径时不应传递 path 参数'
);

echo "http client tests passed\n";
