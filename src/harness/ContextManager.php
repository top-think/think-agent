<?php

namespace think\agent\harness;

use think\facade\Log;
use think\helper\Arr;

/**
 * 上下文管理 Trait
 *
 * 负责消息裁剪（prune）和上下文压缩（compact）
 *
 * 使用此 Trait 的类需提供以下属性和方法：
 * - getMessageContent($message): mixed
 * - getClient(): object
 * - $config['model']['name']: string
 * - $usage: int
 * - $round: int
 */
trait ContextManager
{
    /**
     * 预处理消息：注入压缩摘要 + 裁剪已过期的工具响应
     */
    protected function preprocessMessages($messages)
    {
        return yield from $this->compactMessages($messages);
    }

    /**
     * 裁剪消息中重复和已过期的工具响应数据
     *
     * $messages 为发送给模型接口的扁平消息数组，其中：
     * - assistant 消息携带 tool_calls（含调用参数）
     * - tool 消息（role=tool）携带工具返回值（content），通过 tool_call_id 关联调用
     */
    protected function pruneMessages($messages)
    {
        $seen = [];

        // 建立 tool_call_id 到调用参数的映射（参数保存在 assistant 消息的 tool_calls 中）
        $arguments = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'assistant' || empty($message['tool_calls'])) {
                continue;
            }
            foreach ($message['tool_calls'] as $call) {
                if (!empty($call['id'])) {
                    $arguments[$call['id']] = $call['function']['arguments'] ?? '{}';
                }
            }
        }

        // 从后向前遍历，优先保留最近的工具返回值
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') !== 'tool') {
                continue;
            }

            $name = $messages[$i]['name'] ?? '';
            $tool = [
                'arguments' => $arguments[$messages[$i]['tool_call_id'] ?? ''] ?? '{}',
                'response'  => $messages[$i]['content'] ?? '',
            ];

            $result = $this->shouldPruneToolResponse($name, $tool, $seen);

            if ($result === true) {
                $messages[$i]['content'] = '(Data expired)';
            } elseif ($result !== null) {
                $seen[$name][$result] = true;
            }
        }

        return $messages;
    }

    /**
     * 判断是否应该裁剪工具返回值
     *
     * @param string $toolName 工具名称
     * @param array $tool 工具数据
     * @param array $seen 已见过的工具调用记录
     * @return string|true|null true=裁剪, string=保留并用此值作为key记录, null=跳过不处理
     */
    protected function shouldPruneToolResponse(string $toolName, array $tool, array $seen)
    {
        // read_file: 所有参数相同就裁剪
        if ($toolName === 'read_file') {
            $argsKey = md5($tool['arguments'] ?? '{}');
            return isset($seen[$toolName][$argsKey]) ? true : $argsKey;
        }

        // browser: 如果返回值包含 snapshot 数据就裁剪
        if ($toolName === 'browser') {
            $response = json_decode($tool['response'] ?? '', true);

            if (isset($response['snapshot'])) {
                return isset($seen[$toolName]['snapshot']) ? true : 'snapshot';
            }
        }

        return null;
    }

    /**
     * 上下文压缩
     *
     * 从最新消息向前追溯，保留指定 token 数量的消息，对更早的消息进行压缩。
     * 压缩结果保存到被压缩的最新一条消息的 summary 字段。
     * 支持迭代式压缩：基于上次摘要合并新信息。
     *
     * @param \think\Collection $messages
     * @return \Generator
     */
    public function compactMessages($messages)
    {
        if (count($messages) > 0) {
            $message = $messages[0];
            $context = $this->config['model']['params']['context_tokens'] ?? 0;

            if ($context > 0 && $message->context > 0 && ($context - $message->context < 16384 || $message->context > $context * 0.8)) {
                //压缩：从当前消息向前追溯，保留指定 token 的消息，压缩更早的
                $keepRecentTokens  = 20000;
                $keepFromIndex     = count($messages);
                $compressCount     = null;
                $previousSummary   = null;
                $tokenReached      = false;
                $accumulatedTokens = 0;

                foreach ($messages as $i => $msg) {
                    if (!empty($msg->summary)) {
                        if ($tokenReached) {
                            $compressCount   = $i - $keepFromIndex + 1;
                            $previousSummary = $msg->summary;
                        }
                        break;
                    }

                    if (!$tokenReached) {
                        $accumulatedTokens += $this->estimateCompactTokens($msg);
                        if ($accumulatedTokens >= $keepRecentTokens) {
                            $keepFromIndex = $i + 1;
                            $tokenReached  = true;
                        }
                    }
                }

                $toCompress = $messages->slice($keepFromIndex, $compressCount);

                if (!$toCompress->isEmpty()) {
                    $chunkIndex = $this->round;
                    ++$this->round;

                    yield [
                        'chunks' => [
                            'index'   => $chunkIndex,
                            'content' => ''
                        ],
                    ];

                    yield [
                        'chunks' => [
                            'index' => $chunkIndex,
                            'tools' => [
                                'index'     => 0,
                                'id'        => uniqid(),
                                'name'      => 'context_compact',
                                'title'     => '上下文压缩',
                                'arguments' => '{}',
                            ],
                        ],
                    ];

                    $conversationText = $this->buildCompactConversationText($toCompress);

                    $summary = $this->generateCompactSummary($conversationText, $previousSummary);
                    if (!empty($summary)) {
                        // 保存到保留区最后一条消息（被压缩消息的前一条）
                        $messages[$keepFromIndex - 1]->save(['summary' => $summary]);
                    }
                    yield [
                        'chunks' => [
                            'index' => $chunkIndex,
                            'tools' => [
                                'index'    => 0,
                                'response' => 'success',
                                'error'    => false,
                                'content'  => '',
                            ],
                        ],
                    ];
                }
            }

            // 剔除已被压缩的消息（保留到第一条带 summary 的消息）
            foreach ($messages as $i => $msg) {
                if (!empty($msg->summary)) {
                    $messages = $messages->slice(0, $i + 1);
                    break;
                }
            }
        }

        return $messages;
    }

    // ========== 上下文压缩辅助方法 ==========

    /**
     * 序列化待压缩消息为对话文本（从旧到新）
     */
    protected function buildCompactConversationText($messages): string
    {
        $text = '';
        // messages 是倒序的（新→旧），反转为正序（旧→新）以符合对话流
        foreach ($messages->reverse() as $msg) {
            $userContent   = $this->getMessageContent($msg);
            $userText      = is_string($userContent) ? $userContent : json_encode($userContent, JSON_UNESCAPED_UNICODE);
            $assistantText = $msg->getAssistantContent();

            $text .= "[User]: {$userText}\n";
            if (!empty($assistantText)) {
                $text .= "[Assistant]: {$assistantText}\n";
            }
            $text .= "\n";
        }
        return $text;
    }

    /**
     * 估算单条消息的 token 数（字符数 / 4）
     */
    protected function estimateCompactTokens($message): int
    {
        $userContent   = $this->getMessageContent($message);
        $userText      = is_string($userContent) ? $userContent : json_encode($userContent, JSON_UNESCAPED_UNICODE);
        $assistantText = $message->getAssistantContent();
        return (int)ceil((mb_strlen($userText) + mb_strlen($assistantText)) / 4);
    }

    /**
     * 调用 LLM 生成压缩摘要
     */
    protected function generateCompactSummary(string $conversationText, ?string $previousSummary): string
    {
        $basePrompt = $previousSummary ? self::COMPACT_UPDATE_PROMPT : self::COMPACT_SUMMARIZATION_PROMPT;

        $promptText = "<conversation>\n{$conversationText}</conversation>\n\n";

        if ($previousSummary) {
            $promptText .= "<previous-summary>\n{$previousSummary}\n</previous-summary>\n\n";
        }

        $promptText .= $basePrompt;

        try {
            $result = $this->getClient()->chat()->completions([
                'model'    => $this->config['model']['name'],
                'messages' => [
                    ['role' => 'system', 'content' => self::COMPACT_SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $promptText],
                ],
                'thinking' => 'disabled',
                'stream'   => false
            ]);

            $this->usage += Arr::get($result, 'usage.total_tokens', 0);

            return Arr::get($result, 'message.content', '');
        } catch (\Throwable $e) {
            Log::error('上下文压缩失败: ' . $e->getMessage());
            return '';
        }
    }

    protected const COMPACT_SYSTEM_PROMPT = '你是一个上下文摘要助手。你的任务是阅读用户与AI助手之间的对话，然后按照指定格式生成结构化的摘要。

不要继续对话。不要回答对话中的任何问题。只输出结构化的摘要。';

    protected const COMPACT_SUMMARIZATION_PROMPT = '以上是需要压缩的对话记录。请创建一个结构化的上下文检查点摘要，供另一个AI助手用来继续工作。

请严格按照以下格式输出：

## 目标
[用户想要完成什么任务？可以是多个目标。]

## 约束与偏好
- [用户提到的约束、偏好或要求]
- [如果没有则写"（无）"]

## 进展
### 已完成
- [x] [已完成的任务/变更]

### 进行中
- [ ] [当前正在进行的工作]

### 受阻
- [阻碍进展的问题，如有]

## 关键决策
- **[决策]**: [简要原因]

## 后续步骤
1. [按顺序列出接下来应该做的事情]

## 关键上下文
- [继续工作所需的数据、示例或引用]
- [如果不适用则写"（无）"]

保持每个部分简洁。保留精确的文件路径、函数名和错误信息。';

    protected const COMPACT_UPDATE_PROMPT = '以上是新的对话消息，需要将其合并到 <previous-summary> 标签中提供的现有摘要中。

请根据新信息更新结构化摘要。规则：
- 保留之前摘要中的所有信息
- 从新消息中添加新的进展、决策和上下文
- 更新"进展"部分：将已完成的项目从"进行中"移到"已完成"
- 根据已完成的内容更新"后续步骤"
- 保留精确的文件路径、函数名和错误信息
- 如果某些内容不再相关，可以删除

请严格按照以下格式输出：

## 目标
[保留现有目标，如任务扩展则添加新目标]

## 约束与偏好
- [保留现有内容，添加新发现的约束]

## 进展
### 已完成
- [x] [包含之前已完成的项目和新完成的项目]

### 进行中
- [ ] [当前工作 - 根据进展更新]

### 受阻
- [当前阻碍 - 已解决的删除]

## 关键决策
- **[决策]**: [简要原因]（保留之前的，添加新的）

## 后续步骤
1. [根据当前状态更新]

## 关键上下文
- [保留重要上下文，按需添加新的]

保持每个部分简洁。保留精确的文件路径、函数名和错误信息。';
}
