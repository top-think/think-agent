<?php

namespace think\agent;

use Exception;
use Generator;
use think\agent\harness\ContextManager;
use think\agent\harness\DeferredStreamResult;
use think\agent\harness\StreamStore;
use think\agent\model\Conversation;
use think\agent\model\Message;
use think\facade\Log;
use think\helper\Arr;
use Throwable;
use function Swoole\Coroutine\go;

abstract class Harness extends Agent
{
    use ContextManager;

    public const STATUS_COMPLETED = 1;
    public const STATUS_RUNNING   = 2;
    public const STATUS_CANCELED  = 3;
    public const STATUS_SUSPEND   = 4;

    protected $extraParams = [
        'stream_options' => [
            'splice_arguments' => false,
        ],
    ];

    protected $canUseTool = true;

    /** @var Conversation */
    protected $conversation;

    /** @var Message */
    protected $message;

    protected ?StreamStore $streamStore = null;

    protected bool $stopWatcherRunning = false;


    public function run($params = [], $resume = false)
    {
        $result = parent::run($params, $resume);
        $result = $this->wrapResult($result);

        return $this->transformResult($result);
    }

    protected function wrapResult(Generator $result): Generator
    {
        return value(function () use ($result) {
            $result->rewind();

            while ($result->valid()) {
                $connected = yield $result->current();

                if ($connected === false) {
                    $this->stop();

                    // 继续消费到底，确保底层run的finally/complete仍会执行（计费、落库等）
                    while ($result->valid()) {
                        $result->next();
                    }

                    break;
                }

                $result->next();
            }
        });
    }

    protected function transformResult(Generator $result)
    {
        $result->rewind();
        $this->startStopWatcher();

        return new DeferredStreamResult(
            $result,
            $this->getStreamStore(),
            fn() => $this->getStreamPayload()
        );
    }

    protected function startStopWatcher(): void
    {
        $this->stopWatcherRunning = true;

        go(function () {
            $store = $this->getStreamStore();

            while ($this->stopWatcherRunning) {
                if ($store->waitStop(1)) {
                    $this->stop();
                    break;
                }
            }
        });
    }

    /**
     * 获取流式响应的 JSON 载荷（默认为会话与消息，可覆写）
     */
    protected function getStreamPayload()
    {
        return [
            'conversation' => $this->conversation,
            'message'      => $this->message,
        ];
    }

    protected function getStreamStore(): StreamStore
    {
        return $this->streamStore ??= $this->message->getStreamStore();
    }

    abstract protected function checkConfig($params);

    protected function checkFiles($files)
    {
        return array_filter($files, function ($file) {
            return !empty($file['name']) && !empty($file['path']) && !empty($file['size']);
        });
    }

    protected function prepareTools()
    {

    }

    abstract protected function getSystemPrompt();

    abstract protected function getHistoryMessages($round);

    protected function buildPromptMessages()
    {
        $promptMessages = [];

        $systemPrompt = $this->getSystemPrompt();

        if (!empty($systemPrompt)) {
            $promptMessages[] = [
                'role'    => 'system',
                'content' => $this->replaceVars($systemPrompt),
            ];
        }

        $historyRound = Arr::get($this->config, 'model.params.history_round', 5);

        if ($historyRound > 0 || $historyRound == -1) {
            $messages        = $this->getHistoryMessages($historyRound);
            $messages        = yield from $this->delegate($this->preprocessMessages($messages));
            $historyMessages = $this->buildHistoryMessages($messages);
            $promptMessages  = array_merge($promptMessages, $historyMessages);
        }

        if ($this->isResume && !empty($this->chunks)) {
            $promptMessages = array_merge($promptMessages, $this->buildHistoryMessages([$this->message]));
        } else {
            // 非resume或chunks为空（首轮崩溃后重试）时，需要带上用户消息
            $promptMessages[] = [
                'role'    => 'user',
                'content' => $this->getMessageContent($this->message),
            ];
        }

        return $promptMessages;
    }

    public function getMessage()
    {
        return $this->message;
    }

    protected function getMessageContent($message)
    {
        $content = [];

        if (!empty($message->summary)) {
            $content[] = [
                'type' => 'text',
                'text' => "<previous-context>{$message->summary}</previous-context>",
            ];
        }

        if (!empty($message->query)) {
            $content[] = [
                'type' => 'text',
                'text' => $message->query,
            ];
        }
        if (!empty($message->quote)) {
            $content[] = [
                'type' => 'text',
                'text' => "<quote>{$message->quote}</quote>",
            ];
        }

        if (!empty($message->files)) {
            $files = json_encode(array_map(function ($file) {
                return [
                    'name' => $file['name'],
                    'path' => "{$this->getWorkDir()}/{$file['name']}",
                ];
            }, $message->files), JSON_UNESCAPED_UNICODE);

            $content[] = [
                'type' => 'text',
                'text' => "<files>{$files}</files>",
            ];
        }

        return $content;
    }

    protected function init($params)
    {
        $this->checkConfig($params);

        $conversationId = Arr::get($params, 'conversation');

        $this->conversation = $this->resolveConversation($conversationId);

        if ($this->isResume) {
            if (empty($this->conversation)) {
                throw new Exception('conversation not found');
            }
            $this->message = Arr::get($params, 'message');
            if (!($this->message instanceof Message)) {
                $this->message = $this->conversation->messages()->order('id desc')->findOrFail();
            }
            $this->chunks = $this->message->chunks;
            $this->round  = count($this->chunks);
            $this->message->save([
                'status' => self::STATUS_RUNNING,
            ]);
        } else {
            $query = Arr::get($params, 'query', '');
            $quote = Arr::get($params, 'quote', '');
            $files = $this->checkFiles(Arr::get($params, 'files', []));

            if (empty($this->conversation)) {
                $extra = Arr::get($params, 'extra', []);

                $this->conversation = $this->createConversation([
                    'extra' => $extra ?: null,
                ]);
            }

            $this->message = $this->createMessage([
                'query'  => $query,
                'quote'  => $quote,
                'files'  => $files,
                'status' => self::STATUS_RUNNING
            ]);
        }

        $this->prepareTools();
    }

    abstract protected function resolveConversation($id);

    abstract protected function createConversation($data);

    abstract protected function createMessage($data);

    protected function start($params)
    {
        if ($this->isResume) {
            if (isset($params['chunk'])) {
                if ($params['chunk'] !== $this->round - 1) {
                    throw new Exception('Invalid chunk index for resume');
                }
                $tool = $this->chunks[$params['chunk']]['tools'][$params['tool']] ?? [];
                if (Arr::get($tool, 'content.type') !== 'suspend') {
                    throw new Exception('Only suspended tool can be resumed');
                }
                yield from $this->sendToolData($params['chunk'], $params['tool'], ['response' => $params['payload']]);
                $this->message->chunks = $this->chunks;
            }
        } else {
            yield ['conversation' => $this->conversation->id];
            yield ['id' => $this->message->id];
            $this->trigger('start', $this->conversation, $this->message);
        }
    }

    protected function iteration($messages)
    {
        if (!empty($this->chunks)) {
            $this->message->save([
                'chunks' => array_values($this->chunks),
            ]);
        }
        yield [
            'chunks' => [
                'index' => $this->round,
                'init'  => true,
            ],
        ];
        $messages = $this->pruneMessages($messages);
        yield from parent::iteration($messages);
    }

    public function stop()
    {
        parent::stop();

        $this->message->save([
            'chunks' => array_values($this->chunks),
            'status' => self::STATUS_CANCELED,
        ]);
    }

    abstract protected function consumeTokens(): int;

    protected function complete()
    {
        try {
            $data = [
                'usage'   => $this->consumeTokens(),
                'context' => $this->occupied,
                'latency' => $this->getLatency(),
            ];

            yield [
                'stats' => $data,
            ];

            if (!$this->stopped) {
                $data['chunks'] = array_values($this->chunks);
                $data['status'] = self::STATUS_COMPLETED;
            }

            try {
                $this->message->save($data);
            } catch (Throwable $e) {
                Log::error($e->getMessage());
            }
        } finally {
            $this->stopWatcherRunning = false;
        }
    }
}