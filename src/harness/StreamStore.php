<?php

namespace think\agent\harness;

use Redis;
use Smf\ConnectionPool\Connectors\PhpRedisConnector;
use Swoole\Coroutine;
use think\agent\model\Message;
use think\agent\sse\ServerSentEvent;
use think\agent\sse\ServerSentEvents;
use think\swoole\coroutine\Context;

/**
 * Redis Stream 流式存储：绑定到具体消息，负责流的写入、续传与停止信号
 *
 * 断线重试的策略由业务层通过 StreamResumer 注入
 */
class StreamStore
{
    /**
     * @param Message            $message 消息模型，流的标识与重试上下文
     * @param null|StreamResumer $resumer 重试策略，为空时发现生产者中断直接返回 204
     * @param string             $prefix  Redis key 前缀
     */
    public function __construct(
        protected Message $message,
        protected ?StreamResumer $resumer = null,
        protected string $prefix = 'chat'
    ) {
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

    protected function retryLockKey(): string
    {
        return "{$this->prefix}-retry-{$this->message->id}";
    }

    public function toSseResponse(string $sequence = '0'): ServerSentEvents
    {
        $redis        = $this->getRedisClient();
        $key          = $this->key();
        $heartbeatKey = $this->heartbeatKey();

        // 心跳存在即生产者存活，stream key未创建时xread也会阻塞等待
        if (!$redis->exists($heartbeatKey)) {
            $this->retry();
        }


        $generator = value(function () use ($redis, $key, $sequence, $heartbeatKey) {
            while (true) {
                $messages = $redis->xread([$key => $sequence], 50, 30 * 1000);

                if (false === $messages || empty($messages[$key])) {
                    // 检查心跳key，判断生产者是否还存活
                    if (!$redis->exists($heartbeatKey)) {
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
        $redis        = $this->getRedisClient();
        $key          = $this->key();
        $heartbeatKey = $this->heartbeatKey();

        $redis->del($key);

        $heartbeatActive = true;

        // 独立协程维护心跳
        Coroutine::create(function () use ($heartbeatKey, &$heartbeatActive) {
            $redis = $this->getRedisClient();
            while ($heartbeatActive) {
                $redis->setex($heartbeatKey, 10, 1);
                Coroutine::sleep(5);
            }
        });

        try {
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
        } finally {
            $heartbeatActive = false;
        }

        if (!$this->shouldStop($redis)) {
            $redis->xadd($key, '*', ['data' => '[DONE]']);
            $redis->expire($key, 5 * 60);
        }

        $redis->del($this->stopKey());
        $redis->del($this->stopNotifyKey());
        $redis->del($heartbeatKey);
    }

    public function stop(): void
    {
        $redis = $this->getRedisClient();

        $redis->setex($this->stopKey(), 30 * 60, 1);
        $redis->lPush($this->stopNotifyKey(), '1');
        $redis->expire($this->stopNotifyKey(), 30 * 60);
        $redis->xadd($this->key(), '*', ['data' => '[DONE]']);
        $redis->expire($this->key(), 5 * 60);
    }

    public function waitStop(int $timeout = 1): bool
    {
        $redis = $this->getRedisClient();

        if ((bool)$redis->exists($this->stopKey())) {
            return true;
        }

        $result = $redis->brPop([$this->stopNotifyKey()], $timeout);

        return false !== $result && !empty($result[1]);
    }

    protected function shouldStop(Redis $redis): bool
    {
        return (bool)$redis->exists($this->stopKey());
    }

    public function retry(): void
    {
        if (!$this->resumer) {
            abort(204);
        }

        // 重试保护：防并发重复拉起，并限制每条消息的最短重试间隔，自然过期
        if (!$this->getRedisClient()->set($this->retryLockKey(), 1, ['nx', 'ex' => 60])) {
            abort(449);
        }

        // 已重新拉起则让客户端重连，否则直接结束
        abort($this->resumer->resume($this->message->id) ? 449 : 204);
    }

    /**
     * @return Redis
     */
    protected function getRedisClient()
    {
        return Context::rememberData('chat-redis-client', function () {
            $connector = new PhpRedisConnector();

            return $connector->connect([
                'host'     => env('REDIS_HOST', 'redis'),
                'port'     => 6379,
                'database' => env('REDIS_DB', 0),
            ]);
        });
    }
}
