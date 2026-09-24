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

    /**
     * @param string $name 沙箱名称
     * @param array $options 沙箱配置项
     *  - mode:      传输模式，http 或 ws，默认 http（仅 ws 支持文件变化监听）
     *  - apiUrl:    沙箱服务地址，默认取环境变量 SANDBOX_HOST
     *  - workDir:   沙箱工作目录，默认 /workspace
     *  - env:       执行命令时的默认环境变量
     *  - params:    创建沙箱的附加参数（如 cpu、memory、auto_stop_interval）
     *  - onCreated: 沙箱创建成功后的回调，参数为当前 Sandbox 实例
     */
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


    /**
     * 列出目录内容
     *
     * 文件信息包含：name（文件名）、path（完整路径）、size（字节数）、
     * is_dir（是否目录）、mode（权限字符串，如 -rw-r--r--）、mod_time（RFC3339 时间字符串）。
     *
     * @param string|null $path 目录路径，相对路径将基于 workDir 解析
     * @return array{path: string, files: list<array{name: string, path: string, size: int, is_dir: bool, mode: string, mod_time: string}>}
     */
    public function listFile($path = null)
    {
        return $this->client()->listFiles($this->resolvePath($path));
    }

    /**
     * 读取文本文件
     *
     * 默认最多返回 2000 行或 50KB，发生截断时内容末尾会附加提示，
     * 如 [Showing lines 1-2000 of 5000. Use offset=2001 to continue.]。
     *
     * @param string $path 文件路径，相对路径将基于 workDir 解析
     * @param int $offset 从第几行开始（0-based）
     * @param int $limit 返回行数，-1 表示使用默认限制（2000 行 / 50KB）
     * @return string 文本内容（含截断提示）
     */
    public function readFile($path, $offset = 0, $limit = -1)
    {
        return $this->client()->readFile($this->resolvePath($path), $offset, $limit);
    }

    /**
     * 写入文本文件（父目录不存在时自动创建）
     *
     * @param string $path 文件路径，相对路径将基于 workDir 解析
     * @param string $content 文件内容
     */
    public function writeFile($path, $content)
    {
        $this->client()->writeFile($this->resolvePath($path), $content);
    }

    /**
     * 编辑文本文件，将 oldStr 替换为 newStr
     *
     * oldStr 必须在文件中唯一出现，否则抛出异常且文件保持原样。
     *
     * @param string $path 文件路径，相对路径将基于 workDir 解析
     * @param string $oldStr 被替换的字符串（需唯一）
     * @param string $newStr 新字符串
     */
    public function editFile($path, $oldStr, $newStr)
    {
        $this->client()->editFile($this->resolvePath($path), $oldStr, $newStr);
    }

    /**
     * 上传文件
     *
     * $file 支持以下几种形式：
     * - SplFileInfo 对象（如 think\File）
     * - 资源句柄或 StreamInterface 对象
     * - 文件路径字符串（长度小于 PATH_MAX 且不含空字符，且文件真实存在）
     * - 直接传入的文件内容（文本或二进制数据）
     *
     * @param string $path 沙箱内的目标路径（含文件名），相对路径将基于 workDir 解析
     * @param mixed $file 文件来源，支持上列形式；字符串内容按文本/二进制原样上传
     */
    public function uploadFile($path, $file)
    {
        $sandboxId = $this->getId();
        $path = $this->resolvePath($path);

        if ($file instanceof SplFileInfo) {
            $file = fopen($file->getRealPath(), 'r');
        } elseif (is_string($file) && strlen($file) < 4096 && !str_contains($file, "\0") && is_file($file)) {
            // 只有长度小于 PATH_MAX 且不含空字符的字符串才可能是文件路径，
            // 前置判断可避免对大段文本内容做无谓的路径扫描
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

    /**
     * 删除文件或目录（递归删除）
     *
     * @param string $path 文件路径，相对路径将基于 workDir 解析
     */
    public function deleteFile($path): void
    {
        $this->client()->deleteFile($this->resolvePath($path));
    }

    /**
     * 下载文件
     *
     * @param string $path 文件路径，相对路径将基于 workDir 解析
     * @return StreamInterface 文件内容流（二进制安全，可边读边消费）
     */
    public function downloadFile($path): StreamInterface
    {
        $sandboxId = $this->getId();
        $path = $this->resolvePath($path);

        return $this->request->rawRequest('GET', "sandboxes/{$sandboxId}/files/download", [
            'query' => ['path' => $path], 'stream' => true,
        ])->getBody();
    }

    /**
     * 在沙箱中执行命令
     *
     * @param string $command 命令字符串（由 bash -c 执行）
     * @param bool $stream 是否流式返回输出
     * @param string|null $workDir 工作目录，null 表示使用沙箱 workDir
     * @param array|null $env 附加环境变量，与沙箱默认 env 合并
     * @return array{exitCode: int, stdout: string, stderr: string}|\Generator<int, array{type: string, content?: string, exit_code?: int, message?: string}>
     *         $stream 为 false 时返回执行结果（退出码与完整输出）；
     *         为 true 时返回事件流：{type: 'stdout'|'stderr', content} 逐行输出，
     *         {type: 'exit', exit_code} 表示执行结束
     */
    public function runCommand($command, $stream = false, $workDir = null, $env = null)
    {
        $workDir ??= $this->workDir;
        $env       = array_merge($this->env, $env ?? []);

        return $stream
            ? $this->client()->executeCommandStream($command, $workDir, $env)
            : $this->client()->executeCommand($command, $workDir, $env);
    }

    /**
     * 执行浏览器操作（基于 playwright-cli）
     *
     * @param array $data 浏览器操作参数，至少包含 action：
     *  - open、goto、click、dblclick、fill、type、press、hover、select、check、uncheck
     *  - snapshot、screenshot、eval、drag、drop、upload、resize、dialog-accept
     *  - tab-new、tab-close、tab-select、mousemove、wheel、mousewheel 等
     *  其余参数与 action 相关（url、ref、text、key、value 等）
     * @return array<string, mixed> playwright-cli 的 JSON 结果，常见字段：
     *  - snapshot: string 页面快照文本（操作未返回时自动补充一次）
     *  - screenshot: string 快照对应的截图绝对路径
     *  其余字段随 action 不同而变化
     */
    public function browserExecute(array $data): array
    {
        return $this->client()->browserExecute($data);
    }

    /**
     * 监听文件变化（需要 ws 模式，http 模式下抛出 LogicException）
     *
     * 产出底层消息（未解包 data）：
     * - 订阅建立：{type: 'watching', data: {path, files}}，data 为当前目录文件列表
     * - 文件变化：{type: 'file_change', data: {path: string, op: string}}
     *   op 取值：create、write、remove、rename、chmod
     *
     * @param string|null $path 监听路径，相对路径将基于 workDir 解析
     * @param string|null $id 订阅 ID，同一连接内唯一，默认自动生成
     * @return \Generator<int, array{id: string, type: string, data: mixed}>
     */
    public function watchFiles($path = null, ?string $id = null): \Generator
    {
        yield from $this->client()->watchFiles($this->resolvePath($path), $id);
    }

    /**
     * 获取沙箱 ID，首次调用时自动创建沙箱
     */
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
