<?php

namespace think\agent\model;

use think\agent\harness\StreamResumer;
use think\agent\harness\StreamStore;
use think\Model;

/**
 * @property int $id
 * @property array $chunks
 */
abstract class Message extends Model
{
    /**
     * 创建当前消息对应的流存储（控制器可通过消息对象直接获取）
     */
    public function getStreamStore(): StreamStore
    {
        return new StreamStore($this, $this->getStreamResumer(), $this->getStreamPrefix());
    }

    /**
     * 断线重试策略（默认为空，需要重试时由子类注入）
     */
    protected function getStreamResumer(): ?StreamResumer
    {
        return null;
    }

    /**
     * Redis key 前缀（多项目共用 Redis 时可由子类区分命名空间）
     */
    protected function getStreamPrefix(): string
    {
        return 'chat';
    }
}