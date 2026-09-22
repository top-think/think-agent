<?php

namespace think\agent\harness;

use JsonSerializable;
use think\event\HttpEnd;
use think\facade\Event;

class DeferredStreamResult implements JsonSerializable
{
    protected bool $consumed = false;

    /**
     * @param mixed       $result  流式结果（生成器）
     * @param StreamStore $store   流存储
     * @param mixed       $payload 响应载荷，支持闭包延迟求值
     */
    public function __construct(
        protected $result,
        protected StreamStore $store,
        protected mixed $payload = null
    ) {
    }

    public function defer(): static
    {
        Event::listen(HttpEnd::class, function () {
            $this->consume();
        });

        return $this;
    }

    public function consume(): static
    {
        if ($this->consumed) {
            return $this;
        }

        $this->consumed = true;
        $this->store->pump($this->result);

        return $this;
    }

    public function jsonSerialize(): mixed
    {
        $this->defer();

        return is_callable($this->payload) ? ($this->payload)() : $this->payload;
    }
}
