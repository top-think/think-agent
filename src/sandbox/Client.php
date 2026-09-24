<?php

namespace think\agent\sandbox;

use Generator;

/**
 * 沙箱操作客户端
 *
 * HttpClient 与 WebSocketClient 实现同一套沙箱操作，资源类无需关心底层传输协议。
 * 除特别说明外，路径参数均支持相对路径（相对沙箱工作目录 /workspace）。
 */
abstract class Client
{
    /**
     * 列出目录内容
     *
     * 文件信息包含：name（文件名）、path（完整路径）、size（字节数）、
     * is_dir（是否目录）、mode（权限字符串，如 -rw-r--r--）、mod_time（RFC3339 时间字符串）。
     *
     * @param string|null $path 目录路径，null 表示沙箱工作目录
     * @return array{path: string, files: list<array{name: string, path: string, size: int, is_dir: bool, mode: string, mod_time: string}>}
     */
    abstract public function listFiles(?string $path = null): array;

    /**
     * 读取文本文件
     *
     * 默认最多返回 2000 行或 50KB，发生截断时内容末尾会附加提示，
     * 如 [Showing lines 1-2000 of 5000. Use offset=2001 to continue.]。
     *
     * @param string $path 文件路径
     * @param int $offset 起始行号（0-based）
     * @param int $limit 返回行数，-1 表示使用默认限制（2000 行 / 50KB）
     * @return string 文本内容（含截断提示）
     */
    abstract public function readFile(string $path, int $offset = 0, int $limit = -1): string;

    /**
     * 写入文本内容到文件，父目录不存在时自动创建
     *
     * @param string $path 文件路径
     * @param string $content 文件内容
     */
    abstract public function writeFile(string $path, string $content): void;

    /**
     * 编辑文本文件，将 oldStr 替换为 newStr
     *
     * oldStr 必须在文件中唯一出现，否则抛出异常且文件保持原样。
     *
     * @param string $path 文件路径
     * @param string $oldStr 被替换的字符串（需唯一）
     * @param string $newStr 新字符串
     */
    abstract public function editFile(string $path, string $oldStr, string $newStr): void;

    /**
     * 删除文件或目录（递归删除）
     *
     * @param string $path 文件路径
     */
    abstract public function deleteFile(string $path): void;

    /**
     * 执行命令并等待执行结束（非流式）
     *
     * @param string $command 命令字符串（由 bash -c 执行）
     * @param string|null $workdir 工作目录，null 表示使用默认工作目录
     * @param array $env 环境变量，键值对形式
     * @return array{exitCode: int, stdout: string, stderr: string} 退出码与标准输出/错误
     */
    abstract public function executeCommand(string $command, ?string $workdir = null, array $env = []): array;

    /**
     * 执行命令并流式返回输出
     *
     * 逐条产出事件：
     * - 输出：{type: 'stdout'|'stderr', content: string}（按行输出，含换行符）
     * - 结束：{type: 'exit', exit_code: int}
     *
     * 失败时 HTTP 传输出 {type: 'error', message: string} 事件并结束流，
     * WebSocket 传输直接抛出 RuntimeException。
     *
     * @param string $command 命令字符串（由 bash -c 执行）
     * @param string|null $workdir 工作目录，null 表示使用默认工作目录
     * @param array $env 环境变量，键值对形式
     * @return Generator<int, array{type: string, content?: string, exit_code?: int, message?: string}>
     */
    abstract public function executeCommandStream(string $command, ?string $workdir = null, array $env = []): Generator;

    /**
     * 执行浏览器操作（基于 playwright-cli）
     *
     * $data 至少包含 action（open、goto、click、fill、snapshot、screenshot、eval 等），
     * 其余参数与 action 相关（url、ref、text、key、value 等）。
     *
     * @param array $data 浏览器操作请求
     * @return array<string, mixed> playwright-cli 的 JSON 结果，常见字段：
     *  - snapshot: string   页面快照文本（操作未返回时自动补充一次）
     *  - screenshot: string 快照对应的截图绝对路径
     *  其余字段随 action 不同而变化
     */
    abstract public function browserExecute(array $data): array;

    /**
     * 监听文件变化（仅 WebSocket 传输可用）
     *
     * 产出底层消息（未解包 data）：
     * - 订阅建立：{type: 'watching', data: {path, files}}，data 为当前目录文件列表
     * - 文件变化：{type: 'file_change', data: {path: string, op: string}}
     *   op 取值：create、write、remove、rename、chmod
     *
     * @param string|null $path 监听路径，null 表示工作目录
     * @param string|null $id 订阅 ID，同一连接内唯一，默认自动生成
     * @return Generator<int, array{id: string, type: string, data: mixed}>
     */
    abstract public function watchFiles(?string $path = null, ?string $id = null): Generator;

    /**
     * 环境变量值统一转为字符串，保证符合接口要求
     */
    protected function normalizeEnv(array $env): array
    {
        return array_map('strval', $env);
    }
}
