<?php

namespace think\agent\harness;

/**
 * 流重试策略
 *
 * 由业务层实现：SSE 侧发现生产者中断（心跳消失）时，负责重新拉起流。
 */
interface StreamResumer
{
    /**
     * 尝试重新拉起中断的流
     *
     * @param int|string $id stream 标识
     *
     * @return bool 是否已重新拉起（true 表示客户端应重连 SSE）
     */
    public function resume(int|string $id): bool;
}
