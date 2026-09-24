<?php

namespace think\agent\sandbox;

use Generator;
use RuntimeException;
use Swoole\WebSocket\Frame;

class WebSocketClient extends Client
{
    protected ?\Swoole\Coroutine\Http\Client $connection = null;
    protected \Swoole\Coroutine\Channel $sendLock;
    protected bool $connected = false;
    protected bool $readerStarted = false;
    /** @var array<string, \Swoole\Coroutine\Channel> */
    protected array $requests = [];

    public function __construct(
        protected string $apiUrl,
        protected string $sandboxId,
        protected array  $options = [],
    )
    {
        if ($this->sandboxId === '') throw new \InvalidArgumentException('Sandbox ID is required');

        $this->sendLock = new \Swoole\Coroutine\Channel(1);
        $this->sendLock->push(true);
    }

    public function listFiles(?string $path = null): array
    {
        return $this->request('list', ['path' => $path])['data'] ?? [];
    }

    public function readFile(string $path, int $offset = 0, int $limit = -1): string
    {
        return $this->request('read', compact('path', 'offset', 'limit'))['data']['content'] ?? '';
    }

    public function writeFile(string $path, string $content): void
    {
        $this->request('write', compact('path', 'content'));
    }

    public function editFile(string $path, string $oldStr, string $newStr): void
    {
        $this->request('edit', [
            'path' => $path, 'old_str' => $oldStr, 'new_str' => $newStr,
        ]);
    }

    public function deleteFile(string $path): void
    {
        $this->request('delete', ['path' => $path]);
    }

    public function executeCommand(string $command, ?string $workdir = null, array $env = []): array
    {
        $result = ['exitCode' => 0, 'stdout' => '', 'stderr' => ''];
        foreach ($this->executeCommandStream($command, $workdir, $env) as $event) {
            if (($event['type'] ?? null) === 'stdout') $result['stdout'] .= $event['content'] ?? '';
            if (($event['type'] ?? null) === 'stderr') $result['stderr'] .= $event['content'] ?? '';
            if (($event['type'] ?? null) === 'exit') $result['exitCode'] = $event['exit_code'] ?? 0;
        }
        return $result;
    }

    public function executeCommandStream(string $command, ?string $workdir = null, array $env = []): Generator
    {
        $id      = $this->id();
        $channel = $this->register($id);
        $message = ['id' => $id, 'type' => 'exec', 'command' => $command];
        if ($workdir !== null) $message['workdir'] = $workdir;
        if ($env) $message['env'] = $this->normalizeEnv($env);

        try {
            $this->send($message);
            foreach ($this->messagesFor($id, false, $channel) as $event) {
                yield $event['data'] ?? $event;
            }
        } finally {
            $this->unregister($id, $channel);
        }
    }

    public function watchFiles(?string $path = null, ?string $id = null): Generator
    {
        $id            ??= $this->id();
        $reconnects    = 0;
        $maxReconnects = 5;

        while (true) {
            $channel = $this->register($id);
            $retry   = false;

            try {
                $this->send(['id' => $id, 'type' => 'watch', 'path' => $path]);
                yield from $this->messagesFor($id, false, $channel);
            } catch (RuntimeException $e) {
                // A connection failure is marked by failPending() setting
                // connected to false. Application-level errors are not retried.
                if ($this->connected || $reconnects >= $maxReconnects) {
                    throw $e;
                }

                $reconnects++;
                $retry = true;
            } finally {
                $this->unregister($id, $channel);
            }

            if (!$retry) {
                return;
            }

            $this->reconnect($reconnects);
        }
    }

    public function browserExecute(array $data): array
    {
        return $this->request('browser', ['request' => $data])['data'] ?? [];
    }

    public function connect(): static
    {
        if ($this->connected) return $this;

        $url = parse_url($this->apiUrl);
        if (!$url || empty($url['host'])) throw new RuntimeException('Invalid sandbox API URL');

        $ssl      = ($url['scheme'] ?? 'http') === 'https';
        $port     = $url['port'] ?? ($ssl ? 443 : 80);
        $basePath = rtrim($url['path'] ?? '', '/');
        $prefix   = preg_match('#/api/v1$#i', $basePath) ? $basePath : $basePath . '/api/v1';
        $path     = $prefix . '/sandboxes/' . $this->sandboxId . '/ws';

        $this->connection = new \Swoole\Coroutine\Http\Client($url['host'], $port, $ssl);
        $this->connection->set(array_merge([
            'timeout'         => -1,
            'ssl_verify_peer' => false,
            'ssl_verify_host' => false,
        ], $this->options));

        if (!$this->connection->upgrade($path)) {
            $status = $this->connection->statusCode;
            $this->connection->close();
            $this->connection = null;
            throw new RuntimeException('Failed to connect to sandbox WebSocket' . ($status ? " (HTTP {$status})" : ''));
        }

        $this->connected = true;
        return $this;
    }

    public function close(): void
    {
        $this->connected = false;
        $this->connection?->close();
        $this->connection = null;

        foreach ($this->requests as $channel) {
            $channel->close();
        }
        $this->requests = [];
    }

    protected function send(array $message): void
    {
        $this->sendLock->pop();

        try {
            $this->connect();
            $this->startReader();
            if (!$this->connection->push(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1)) {
                throw new RuntimeException('Failed to send sandbox WebSocket message');
            }
        } finally {
            $this->sendLock->push(true);
        }
    }

    /**
     * Receive one WebSocket frame from the Swoole client.
     */
    protected function receiveFrame(): ?Frame
    {
        if ($this->connection === null) {
            return null;
        }

        // Swoole 6.1 起：服务端关闭连接时 recv() 返回空字符串，false 表示失败/超时；
        // ping/pong/close 控制帧也由 Swoole 内部自动处理，不会返回给调用方
        $frame = $this->connection->recv(-1);
        if ($frame === false || $frame === null || $frame === '') {
            return null;
        }

        if (!$frame instanceof Frame) {
            throw new RuntimeException('Invalid sandbox WebSocket frame');
        }

        return $frame;
    }

    protected function receive(): ?array
    {
        while (($frame = $this->receiveFrame()) !== null) {
            // 旧版 Swoole（< 6.1）会把控制帧交给业务层，需手动应答 PING 维持服务端心跳
            if ($frame->opcode === SWOOLE_WEBSOCKET_OPCODE_PING) {
                $this->sendPong($frame->data);
                continue;
            }

            // 协议消息均为 JSON 文本帧，跳过其它类型帧和空帧，不视为连接断开
            if ($frame->opcode !== SWOOLE_WEBSOCKET_OPCODE_TEXT || !$frame->data) {
                continue;
            }

            $message = json_decode($frame->data, true);
            if (!is_array($message)) throw new RuntimeException('Invalid sandbox WebSocket response');
            return $message;
        }

        return null;
    }

    /**
     * 应答服务端 PING（仅旧版 Swoole 需要，6.1 起控制帧由扩展自动处理）
     *
     * 沿用发送锁，避免与请求消息并发写坏 WebSocket 流
     */
    protected function sendPong(string $payload): void
    {
        $this->sendLock->pop();

        try {
            $this->connection?->push($payload, SWOOLE_WEBSOCKET_OPCODE_PONG);
        } finally {
            $this->sendLock->push(true);
        }
    }

    protected function request(string $type, array $data = []): array
    {
        $id      = $this->id();
        $channel = $this->register($id);

        try {
            $this->send(array_merge(['id' => $id, 'type' => $type], $data));
            foreach ($this->messagesFor($id, true, $channel) as $message) return $message;
            return [];
        } finally {
            $this->unregister($id, $channel);
        }
    }

    protected function messagesFor(string $id, bool $once = false, ?\Swoole\Coroutine\Channel $channel = null): Generator
    {
        $channel ??= $this->requests[$id] ?? null;
        if ($channel === null) {
            throw new RuntimeException('WebSocket request is not registered');
        }

        while (($message = $channel->pop()) !== false) {
            if (($message['type'] ?? null) === 'error') {
                throw new RuntimeException($message['error'] ?? 'Sandbox WebSocket request failed');
            }
            yield $message;
            if ($once || in_array($message['type'] ?? null, ['exit', 'result'], true)) return;
        }
    }

    protected function register(string $id): \Swoole\Coroutine\Channel
    {
        if (isset($this->requests[$id])) {
            throw new RuntimeException('WebSocket request ID is already in use: ' . $id);
        }

        $channel             = new \Swoole\Coroutine\Channel(100);
        $this->requests[$id] = $channel;
        return $channel;
    }

    protected function unregister(string $id, \Swoole\Coroutine\Channel $channel): void
    {
        if (($this->requests[$id] ?? null) !== $channel) {
            return;
        }

        unset($this->requests[$id]);
        $channel->close();
    }

    protected function startReader(): void
    {
        if ($this->readerStarted) {
            return;
        }

        $this->readerStarted = true;
        \Swoole\Coroutine::create(function (): void {
            try {
                while ($this->connected) {
                    $message = $this->receive();
                    if ($message === null) {
                        if ($this->connected) {
                            $this->failPending(new RuntimeException('Sandbox WebSocket connection closed'));
                        }
                        break;
                    }

                    $id = $message['id'] ?? null;
                    if ($id === null || !isset($this->requests[$id])) {
                        continue;
                    }

                    $this->requests[$id]->push($message);
                }
            } catch (\Throwable $e) {
                $this->failPending($e);
            } finally {
                $this->readerStarted = false;
            }
        });
    }

    protected function failPending(\Throwable $exception): void
    {
        $this->connected = false;
        $message         = [
            'type'  => 'error',
            'error' => $exception->getMessage() ?: 'Sandbox WebSocket connection failed',
        ];

        foreach ($this->requests as $channel) {
            $channel->push($message);
        }
    }

    protected function reconnect(int $attempt): void
    {
        // Let the previous reader leave its recv() loop before replacing the
        // connection, otherwise it could consume frames from the new socket.
        while ($this->readerStarted) {
            \Swoole\Coroutine::sleep(0.001);
        }

        $this->connection?->close();
        $this->connection = null;
        $this->connected  = false;

        \Swoole\Coroutine::sleep(min(1 << ($attempt - 1), 5));
    }

    protected function id(): string
    {
        return 'request-' . bin2hex(random_bytes(8));
    }
}
