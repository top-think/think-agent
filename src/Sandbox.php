<?php

namespace think\agent;

use Closure;
use Psr\Http\Message\StreamInterface;
use think\agent\sandbox\Client;
use think\agent\sandbox\HttpClient;
use think\agent\sandbox\RequestClient;
use think\agent\sandbox\WebSocketClient;
use SplFileInfo;

class Sandbox
{
    protected RequestClient $request;
    protected ?Client $client = null;
    protected ?string $id = null;
    protected $lock = null;

    protected $workDir = '/workspace';
    protected $env = [];
    protected ?Closure $onCreated = null;

    //沙箱创建参数
    protected $params = [];
    protected string $mode = 'http';
    protected string $apiUrl;

    public function __construct(protected string $name, $options = [])
    {
        $this->mode = $options['mode'] ?? 'http';
        if (!in_array($this->mode, ['http', 'ws'], true)) {
            throw new \InvalidArgumentException('Sandbox mode must be http or ws');
        }

        $this->apiUrl = $options['apiUrl'] ?? env('SANDBOX_HOST', 'http://sandbox:3000');
        $this->request = new RequestClient($this->apiUrl);

        $this->workDir   = $options['workDir'] ?? $this->workDir;
        $this->env       = $options['env'] ?? $this->env;
        $this->params    = $options['params'] ?? $this->params;
        $this->onCreated = $options['onCreated'] ?? $this->onCreated;

        // 初始化协程锁（Channel），容量为1实现互斥
        $this->lock = new \Swoole\Coroutine\Channel(1);
        $this->lock->push(true); // 初始状态：锁可用
    }


    public function listFile($path = null)
    {
        return $this->client()->listFiles($this->resolvePath($path));
    }

    /**
     * 读取文本文件
     *
     * @param string $path 文件路径，相对路径将基于 workDir 解析
     * @param int $offset 从第几行开始（0-based）
     * @param int $limit 返回行数，-1 表示使用默认限制（2000 行 / 50KB）
     */
    public function readFile($path, $offset = 0, $limit = -1)
    {
        return $this->client()->readTextFile($this->resolvePath($path), $offset, $limit);
    }

    public function writeFile($path, $content)
    {
        $this->client()->writeTextFile($this->resolvePath($path), $content);
    }

    public function editFile($path, $oldStr, $newStr)
    {
        $this->client()->editFile($this->resolvePath($path), $oldStr, $newStr);
    }

    public function uploadFile($path, $file)
    {
        $sandboxId = $this->getId();
        $path = $this->resolvePath($path);

        if ($file instanceof SplFileInfo) {
            $file = fopen($file->getRealPath(), 'r');
        } elseif (!is_resource($file)) {
            $file = fopen($file, 'r');
        }

        try {
            $this->request->post("sandboxes/{$sandboxId}/files/upload", [[
                'name' => 'file', 'contents' => $file, 'filename' => basename($path),
            ]], true, ['path' => $path]);
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }
    }

    public function deleteFile($path): void
    {
        $this->client()->deleteFile($this->resolvePath($path));
    }

    public function downloadFile($path): StreamInterface
    {
        $sandboxId = $this->getId();
        $path = $this->resolvePath($path);

        return $this->request->rawRequest('GET', "sandboxes/{$sandboxId}/files/download", [
            'query' => ['path' => $path], 'stream' => true,
        ])->getBody();
    }

    public function runCommand($command, $stream = false, $workDir = null, $env = null)
    {
        $workDir ??= $this->workDir;
        $env       = array_merge($this->env, $env ?? []);

        return $stream
            ? $this->client()->executeCommandStream($command, $workDir, $env)
            : $this->client()->executeCommand($command, $workDir, $env);
    }

    public function browserExecute(array $data): array
    {
        return $this->client()->browserExecute($data);
    }

    public function watchFiles($path = null, ?string $id = null): \Generator
    {
        yield from $this->client()->watchFiles($this->resolvePath($path), $id);
    }

    public function getId(): string
    {
        $this->ensureCreated();

        if ($this->id === null) {
            throw new \LogicException('Sandbox ID is not initialized');
        }

        return $this->id;
    }

    protected function ensureCreated(): void
    {
        if ($this->id === null) {
            // 加锁：确保只有一个协程执行创建逻辑
            if ($this->lock) {
                $this->lock->pop(); // 获取锁（阻塞等待）
            }

            try {
                // 双重检查：可能其他协程已创建完成
                if ($this->id === null) {
                    $params = [
                        'name'                 => $this->name,
                        'auto_stop_interval'   => 30,
                        'auto_delete_interval' => 1440,
                        ...$this->params
                    ];

                    $sandbox = $this->request->post('sandboxes', $params)['data'] ?? [];
                    $id = (string)($sandbox['id'] ?? '');

                    if ($id === '') {
                        throw new \RuntimeException('Failed to create sandbox');
                    }

                    $client = $this->mode === 'ws'
                        ? new WebSocketClient($this->apiUrl, $id)
                        : new HttpClient($this->request, $id);

                    $this->id = $id;
                    $this->client = $client;

                    if ($this->onCreated) {
                        call_user_func($this->onCreated, $this);
                    }
                }
            } finally {
                // 释放锁
                if ($this->lock) {
                    $this->lock->push(true);
                }
            }
        }
    }

    protected function resolvePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($this->workDir, '/') . '/' . ltrim($path, '/');
    }

    protected function client(): Client
    {
        $this->ensureCreated();

        if ($this->client === null) {
            throw new \LogicException('Sandbox client is not initialized');
        }

        return $this->client;
    }

}
