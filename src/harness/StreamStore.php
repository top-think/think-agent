<?php

namespace think\agent\harness;

use think\agent\model\Message;
use think\agent\sse\ServerSentEvent;
use think\agent\sse\ServerSentEvents;
use think\swoole\facade\Task;

/**
 * Redis Stream 流式存储：绑定到具体消息，负责流的写入、续传与停止信号
 *
 * 断线重试的策略由业务层通过 StreamResumer 注入
 */
class StreamStore
{
    protected $redis;

    /**
     * @param Message $message 消息模型，流的标识与重试上下文
     * @param null|StreamResumer $resumer 重试策略，为空时发现生产者中断直接返回 204
     * @param string $prefix Redis key 前缀
     */
    public function __construct(
        protected Message        $message,
        protected ?StreamResumer $resumer = null,
        protected string         $prefix = 'chat'
    )
    {
        $this->redis = \think\swoole\facade\Redis::connection();
    }

    protected function key(): string
    {
        return "{$this->prefix}-{$this->message->id}";
    }

    protected function stopKey(): string
    {
        return "{$this->prefix}-stop-{$this->message->id}";
    }

    protected function stopNotifyKey(): string
    {
        return "{$this->prefix}-stop-notify-{$this->message->id}";
    }

    protected function heartbeatKey(): string
    {
        return "{$this->prefix}-heartbeat-{$this->message->id}";
    }

    public function toSseResponse(string $sequence = '0'): ServerSentEvents
    {
        $heartbeatKey = $this->heartbeatKey();

        // 心跳存在即生产者存活，stream key未创建时xread也会阻塞等待
        if (!Task::isRunning($heartbeatKey)) {
            $this->retry();
        }

        $generator = $this->redis->stream(function ($redis) use ($sequence, $heartbeatKey) {
            $key = $this->key();
            while (true) {
                $messages = $redis->xread([$key => $sequence], 50, 30 * 1000);

                if (false === $messages || empty($messages[$key])) {
                    // 检查心跳key，判断生产者是否还存活
                    if (!Task::isRunning($heartbeatKey)) {
                        $this->retry();
                    }
                    $connected = yield ": heartbeat\n\n";
                    if (false === $connected) {
                        break;
                    }
                    continue;
                }

                $messages = $messages[$key];
                foreach ($messages as $sequence => $entry) {
                    $data = $entry['data'] ?? '';
                    if ('[DONE]' === $data || !$data) {
                        break 2;
                    }
                    $connected = yield new ServerSentEvent($sequence, $data);
                    if (false === $connected) {
                        break 2;
                    }
                }
            }
        });

        return new ServerSentEvents($generator);
    }

    public function pump($result): void
    {
        Task::run($this->heartbeatKey(), fn() => $this->redis->dedicated(function ($redis) use ($result) {
            $key = $this->key();
            $redis->del($key);

            $lastExpireTime = 0;
            while ($result->valid()) {
                $chunk = $result->current();

                $redis->xadd($key, '*', ['data' => json_encode($chunk)]);

                // 每30秒刷新一次过期时间，避免频繁调用expire
                $now = time();
                if ($now - $lastExpireTime >= 30) {
                    $redis->expire($key, 30 * 60);
                    $lastExpireTime = $now;
                }
                $result->next();
            }

            $stopKey = $this->stopKey();
            if (!$redis->exists($stopKey)) {
                $redis->xadd($key, '*', ['data' => '[DONE]']);
                $redis->expire($key, 5 * 60);
            }

            $redis->del($stopKey);
            $redis->del($this->stopNotifyKey());
        }));
    }

    public function stop(): void
    {
        $this->redis->run(function ($redis) {
            $redis->setex($this->stopKey(), 30 * 60, 1);
            $redis->lPush($this->stopNotifyKey(), '1');
            $redis->expire($this->stopNotifyKey(), 30 * 60);
            $redis->xadd($this->key(), '*', ['data' => '[DONE]']);
            $redis->expire($this->key(), 5 * 60);
        });
    }

    public function waitStop(): bool
    {
        return $this->redis->dedicated(function ($redis) {
            if ($redis->exists($this->stopKey())) {
                return true;
            }

            $result = $redis->brPop([$this->stopNotifyKey()], 5);

            return false !== $result && !empty($result[1]);
        });
    }

    public function retry(): void
    {
        if (!$this->resumer) {
            abort(204);
        }

        // 重试保护：防并发重复拉起，并限制每条消息的最短重试间隔，自然过期
        if (!$this->redis->set("{$this->prefix}-retry-{$this->message->id}", 1, ['nx', 'ex' => 60])) {
            abort(449);
        }

        // 已重新拉起则让客户端重连，否则直接结束
        abort($this->resumer->resume($this->message->id) ? 449 : 204);
    }

}
