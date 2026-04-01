<?php

namespace think\agent;

use Generator;
use Swoole\Coroutine\Channel;
use think\agent\tool\FunctionCall;
use think\agent\tool\result\Error;
use think\agent\tool\result\Plain;
use think\agent\tool\result\Raw;
use think\agent\tool\result\Suspend;
use think\ai\Client;
use think\ai\Exception;
use think\facade\Log;
use think\helper\Arr;
use Throwable;
use function Swoole\Coroutine\go;

abstract class Agent
{
    protected $config = [];

    protected $usage = 0;
    protected $round = 0;
    protected $chunks = [];
    protected $functions = [];
    protected $plugins = [];
    protected $mcpServers = [];
    protected $tools = [];

    protected $functionHooks = [];

    protected $canUseTool = false;
    protected $iterable = true;
    protected $stopped = false;

    protected $isResume = false;

    protected $extraParams = [];

    public function run($params, $resume = false)
    {
        try {
            $this->isResume = $resume;
            $this->init($params);
            $this->buildTools();

            yield from $this->handleCallback($this->start($params));

            $messages = $this->buildPromptMessages();
            yield from $this->iteration($messages);
        } catch (Throwable $e) {
            yield from $this->sendChunkData($this->round, 'error', $e->getMessage());
            Log::error("{$e->getMessage()}\n{$e->getTraceAsString()}");
        } finally {
            yield from $this->handleCallback($this->complete());

            $this->round     = 0;
            $this->usage     = 0;
            $this->chunks    = [];
            $this->functions = [];
            $this->plugins   = [];
        }
    }

    public function stop()
    {
        $this->iterable = false;
        $this->stopped  = true;
    }

    /**
     * 统一处理回调结果，如果是迭代器则 yield from.
     *
     * @param mixed $result
     *
     * @return Generator
     */
    protected function handleCallback($result)
    {
        if ($result instanceof Generator) {
            yield from $result;
        }
    }

    protected function start($params)
    {
    }

    protected function addFunction($key, FunctionCall $func, $args = [])
    {
        $this->functions[$key] = [$func, $args];

        return $this;
    }

    /**
     * @param mixed $key
     *
     * @return array{FunctionCall, array}
     */
    protected function getFunction($key)
    {
        if (!isset($this->functions[$key])) {
            return [null, []];
        }

        return $this->functions[$key];
    }

    protected function onFunctionCall($key, callable $func)
    {
        $this->functionHooks[$key] = $func;
    }

    protected function addMcpServer($name, $url, $options = [])
    {
        $this->mcpServers[] = [
            'name' => $name,
            'url'  => $url,
            ...$options,
        ];

        return $this;
    }

    protected function addPlugin($name, $tool, $args = [], $others = [])
    {
        $this->plugins[] = [
            'name' => $name,
            'tool' => $tool,
            'args' => $args,
            ...$others,
        ];

        return $this;
    }

    protected function buildTools()
    {
        if (!$this->canUseTool) {
            return;
        }

        $tools = [];

        foreach ($this->plugins as $plugin) {
            $tools[] = [
                'type'   => 'plugin',
                'plugin' => $plugin,
            ];
        }

        foreach ($this->mcpServers as $server) {
            $tools[] = [
                'type' => 'mcp',
                'mcp'  => $server,
            ];
        }

        foreach ($this->functions as $name => $function) {
            /** @var FunctionCall $object */
            [$object] = $function;
            $tools[] = $object->toLlm($name);
        }

        $this->tools = $tools;
    }

    protected function getSystemVars()
    {
        return [];
    }

    protected function replaceVars($prompt, $vars = [])
    {
        $vars = [
            ...$this->getSystemVars(),
            ...$vars,
        ];

        foreach ($vars as $key => $value) {
            $prompt = str_replace("{{{$key}}}", $value, $prompt);
        }

        return $prompt;
    }

    abstract protected function buildPromptMessages();

    protected function getMessageContent($message)
    {
        return $message->content;
    }

    protected function getMessageChunks($message)
    {
        $assistantMessages = [];

        foreach ($message->chunks as $chunk) {
            if (!empty($chunk['error'])) {
                continue;
            }
            if (!empty($chunk['tools'])) {
                if (!$this->canUseTool) {
                    break;
                }

                $calls     = [];
                $responses = [];

                foreach ($chunk['tools'] as $tool) {
                    $calls[]     = [
                        'id'       => $tool['id'],
                        'type'     => 'function',
                        'function' => [
                            'name'      => $tool['name'],
                            'arguments' => $tool['arguments'],
                        ],
                    ];
                    $content     = empty($tool['response']) ? '(Canceled)' : (is_string($tool['response']) ? $tool['response'] : json_encode($tool['response']));
                    $responses[] = [
                        'tool_call_id' => $tool['id'],
                        'role'         => 'tool',
                        'name'         => $tool['name'],
                        'content'      => $content,
                    ];
                }

                if (empty($calls)) {
                    continue;
                }

                $assistantMessage = [
                    'role'       => 'assistant',
                    'tool_calls' => $calls,
                ];

                $this->updateMessageText($assistantMessage, $chunk);

                $assistantMessages[] = $assistantMessage;

                $assistantMessages = array_merge($assistantMessages, $responses);
            } else {
                $assistantMessages[] = [
                    'role'    => 'assistant',
                    'content' => empty($chunk['content']) ? 'None' : $chunk['content'],
                ];
            }
        }

        return $assistantMessages;
    }

    protected function buildHistoryMessages($messages, $maxTokens = 0)
    {
        $historyMessages = [];

        foreach ($messages as $message) {
            $assistantMessages = $this->getMessageChunks($message);

            if (empty($assistantMessages)) {
                continue;
            }

            $userMessage = [
                'role'    => 'user',
                'content' => $this->getMessageContent($message),
            ];

            $tempHistoryMessages = array_merge([$userMessage, ...$assistantMessages], $historyMessages);
            if ($maxTokens > 0) {
                $tokens = Util::tikToken($tempHistoryMessages);
                if ($tokens > $maxTokens * .6) {
                    break;
                }
            }
            $historyMessages = $tempHistoryMessages;
        }

        return $historyMessages;
    }

    abstract protected function init($params);

    abstract protected function complete();

    protected function iteration($messages)
    {
        $model       = Arr::get($this->config['model'], 'name');
        $thinking    = Arr::get($this->config['model'], 'thinking', 'enabled');
        $temperature = Arr::get($this->config['model'], 'params.temperature', 0.8);
        $user        = $this->config['user'] ?? null;

        $params = [
            'model'       => $model,
            'messages'    => $messages,
            'thinking'    => $thinking,
            'temperature' => $temperature,
            'user'        => $user,
            ...$this->extraParams,
        ];

        if (!empty($this->tools)) {
            $params['tools'] = $this->tools;
        }

        $chunkIndex = $this->round;
        $calls      = [];

        try {
            $result = $this->getClient()->chat()->completions($params);
            ++$this->round;

            foreach ($result as $event) {
                if (!empty($event['delta']['tool_calls'])) {
                    $call      = $event['delta']['tool_calls'][0];
                    $callIndex = $call['index'] ?? 0;
                    unset($call['index']);

                    if (!isset($calls[$callIndex])) {
                        $calls[$callIndex] = $call;
                        // 下发调用工具的状态
                        $callType = $call['type'];

                        switch ($callType) {
                            case 'plugin':
                            case 'mcp':
                                $data = [
                                    'id'        => $call['id'],
                                    'name'      => $call[$callType]['function'],
                                    'title'     => $call[$callType]['title'],
                                    'arguments' => $call[$callType]['arguments'] ?? '',
                                ];

                                yield from $this->sendToolData($chunkIndex, $callIndex, $data);

                                break;
                            case 'function':
                                $name = $call['function']['name'];
                                [$function] = $this->getFunction($name);
                                if ($function) {
                                    $data = [
                                        'id'        => $call['id'],
                                        'name'      => $name,
                                        'title'     => $function->getTitle(),
                                        'arguments' => $call['function']['arguments'] ?? '',
                                    ];

                                    yield from $this->sendToolData($chunkIndex, $callIndex, $data);
                                }

                                break;
                        }
                    } else {
                        $callType = $calls[$callIndex]['type'];
                        if (in_array($callType, ['plugin', 'mcp', 'function']) && isset($call[$callType]['arguments'])) {
                            yield from $this->sendToolArguments($chunkIndex, $callIndex, $call[$callType]['arguments']);
                            $calls[$callIndex][$callType]['arguments'] .= $call[$callType]['arguments'];
                        } else {
                            $calls[$callIndex] = Arr::mergeDeep($calls[$callIndex], $call);
                        }
                    }
                } else {
                    yield from $this->sendTextChunkData($chunkIndex, $event, 'reasoning');

                    yield from $this->sendTextChunkData($chunkIndex, $event, 'signature');

                    yield from $this->sendTextChunkData($chunkIndex, $event, 'content');
                }

                if (!empty($event['usage'])) {
                    $this->usage += $event['usage']['total_tokens'];

                    yield from $this->sendChunkData($chunkIndex, 'content', '', true);
                }
            }
        } catch (Throwable $e) {
            yield from $this->sendChunkData($chunkIndex, 'error', $e->getMessage());
            Log::error("{$e->getMessage()}\n{$e->getTraceAsString()}");
        }

        if (!empty($calls) && $this->iterable) {
            $message = [
                'role'       => 'assistant',
                'tool_calls' => $calls,
            ];

            $this->updateMessageText($message, $this->chunks[$chunkIndex] ?? []);

            $messages[] = $message;

            yield from $this->executeToolsInCoroutine($calls, $messages, $chunkIndex);

            if ($this->iterable) {
                yield from $this->iteration($messages);
            }
        }
    }

    /**
     * 在协程环境中并发执行工具调用.
     *
     * @param array $calls
     * @param array $messages
     * @param int $chunkIndex
     *
     * @return Generator
     */
    protected function executeToolsInCoroutine($calls, &$messages, $chunkIndex)
    {
        $callsSize = count($calls);
        $chan      = new Channel(max(1, $callsSize * 4));

        foreach ($calls as $index => $call) {
            go(function () use ($index, $call, $chan) {
                $result = $this->executeSingleTool($call, $index, function ($progress) use ($index, $chan) {
                    $chan->push([
                        'type'  => 'progress',
                        'index' => $index,
                        'data'  => $progress,
                    ]);
                });
                $chan->push([
                    'type'   => 'result',
                    'result' => $result,
                ]);
            });
        }

        $suspend = false;
        $done    = 0;
        while ($done < $callsSize) {
            $item = $chan->pop();
            if (empty($item)) {
                continue;
            }

            if (($item['type'] ?? null) === 'progress') {
                yield from $this->sendToolProgress($chunkIndex, $item['index'], $item['data']);
                continue;
            }

            ++$done;

            $result = $item['result'] ?? null;
            if (!empty($result)) {
                if (!empty($result['suspend'])) {
                    $suspend = true;
                }
                yield from $this->processToolResult($result, $messages, $chunkIndex);
            }
        }

        $chan->close();

        if ($suspend) {
            $this->iterable = false;
            yield ['suspend' => true];
        }
    }

    /**
     * 处理工具执行结果.
     *
     * @param array $result
     * @param array $messages
     * @param int $chunkIndex
     *
     * @return Generator
     */
    protected function processToolResult($result, &$messages, $chunkIndex)
    {
        // 添加 tool message 到 messages
        if (!empty($result['message'])) {
            $messages[] = $result['message'];
        }

        // 处理 hook Generator
        if (!empty($result['event'])) {
            yield from $result['event'];
        }

        // 下发调用工具完成的状态
        yield from $this->sendToolData($chunkIndex, $result['index'], $result['data']);
    }

    /**
     * 执行单个工具调用.
     *
     * @param array $call
     * @param int $index
     *
     * @return null|array
     */
    protected function executeSingleTool($call, $index, $progressEmitter)
    {
        $type = $call['type'];

        switch ($type) {
            case 'plugin':
            case 'mcp':
                $result = new Raw([
                    'response' => $call[$type]['response'],
                    'content'  => $call[$type]['content'],
                    'error'    => $call[$type]['error'],
                    'usage'    => $call[$type]['usage'],
                ]);
                break;
            case 'function':
                try {
                    $name = $call['function']['name'];

                    [$function, $args] = $this->getFunction($name);

                    if (empty($function)) {
                        throw new Exception("tool [{$name}] not exist, please check the tool name.");
                    }

                    $argumentsJson = trim($call['function']['arguments']);
                    $arguments     = json_decode($argumentsJson, true);

                    if (!is_array($arguments)) {
                        if (!empty($argumentsJson)) {
                            throw new Exception("Invalid JSON format for tool [{$name}] arguments: " . json_last_error_msg());
                        }
                        $arguments = [];
                    }

                    $runtimeArgs = array_merge($arguments, $args);

                    $invoked = $function($runtimeArgs);
                    if ($invoked instanceof Generator) {
                        foreach ($invoked as $progress) {
                            $progressEmitter($progress);
                        }
                        $invoked = $invoked->getReturn();
                    }

                    $result = $this->normalizeToolResult($invoked);

                    if ($result instanceof Suspend) {
                        $suspend = true;
                    }

                    if (isset($this->functionHooks[$name])) {
                        $hookResult = call_user_func($this->functionHooks[$name], $result);
                        if ($hookResult instanceof Generator) {
                            $event = $hookResult;
                        }
                    }
                } catch (Throwable $e) {
                    $result = new Error($e);
                }

                $message = [
                    'tool_call_id' => $call['id'],
                    'role'         => 'tool',
                    'name'         => $name,
                    'content'      => $result->getResponse(),
                ];
                break;
        }

        if (!empty($result)) {
            // 调用工具产生的计费
            $this->usage += $result->getUsage();

            $content = $result->getContent();
            if (!empty($content) && is_array($content)) {
                switch ($content['type'] ?? null) {
                    case 'image':
                        // 图片本地化
                        $content['image'] = $this->saveImage($content['image']);
                        break;
                }
            }

            return [
                'index'   => $index,
                'data'    => [
                    'response' => $result->getResponse(),
                    'error'    => $result->isError(),
                    'content'  => $content,
                    'metadata' => $result->getMetadata()
                ],
                'message' => $message ?? null,
                'event'   => $event ?? null,
                'suspend' => $suspend ?? false,
            ];
        }

        return null;
    }

    protected function updateMessageText(&$message, $chunk)
    {
        if (isset($chunk['content'])) {
            $message['content'] = $chunk['content'];
        }

        if (isset($chunk['reasoning'])) {
            $message['reasoning'] = $chunk['reasoning'];
        }

        if (isset($chunk['signature'])) {
            $message['signature'] = $chunk['signature'];
        }
    }

    protected function sendTextChunkData($chunkIndex, $event, $key)
    {
        $text = $event['delta'][$key] ?? '';
        if ('' !== $text) {// 这里必须和''强比较，防止0等字符不能输出
            yield from $this->sendChunkData($chunkIndex, $key, $text, true);
        }
    }

    abstract protected function getClient(): Client;

    protected function saveImage($image)
    {
        return $image;
    }

    protected function normalizeToolResult($value)
    {
        if ($value instanceof \think\agent\tool\Result) {
            return $value;
        }

        return new Plain($value);
    }

    protected function sendToolArguments($chunkIndex, $toolIndex, $arguments)
    {
        if ($this->iterable) {
            $this->updateChunk($chunkIndex, "tools.{$toolIndex}.arguments", $arguments, true);

            yield [
                'chunks' => [
                    'index' => $chunkIndex,
                    'tools' => [
                        'index'     => $toolIndex,
                        'arguments' => $arguments,
                    ],
                ],
            ];
        }
    }

    protected function sendToolProgress($chunkIndex, $toolIndex, $progress)
    {
        if (!$this->iterable) {
            return;
        }

        if (!is_array($progress)) {
            $progress = [
                'message' => (string)$progress,
            ];
        }

        yield [
            'chunks' => [
                'index' => $chunkIndex,
                'tools' => [
                    'index' => $toolIndex,
                    ...$progress
                ],
            ],
        ];
    }

    protected function sendToolData($chunkIndex, $toolIndex, $data)
    {
        if ($this->iterable) {
            $this->updateChunk($chunkIndex, "tools.{$toolIndex}", $data);

            yield [
                'chunks' => [
                    'index' => $chunkIndex,
                    'tools' => [
                        'index' => $toolIndex,
                        ...$data,
                    ],
                ],
            ];
        }
    }

    protected function sendChunkData($chunkIndex, $key, $value, $append = false)
    {
        if ($this->iterable) {
            $this->updateChunk($chunkIndex, $key, $value, $append);

            yield [
                'chunks' => [
                    'index' => $chunkIndex,
                    $key    => $value,
                ],
            ];
        }
    }

    protected function updateChunk($chunkIndex, $key, $value, $append = false)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                Arr::set($this->chunks, "{$chunkIndex}.{$key}.{$k}", $v);
            }
        } else {
            if ($append) {
                $value = Arr::get($this->chunks, "{$chunkIndex}.{$key}", '') . $value;
            }
            Arr::set($this->chunks, "{$chunkIndex}.{$key}", $value);
        }
    }
}
