<?php

namespace think\agent\mcp;

use Psr\Http\Message\StreamInterface;
use Throwable;

abstract class Transport
{
    protected $id        = 0;
    protected $sessionId = null;

    abstract public function listTools();

    abstract public function callTool(string $name, ?array $arguments = null);

    abstract public function close();

    abstract public static function connect($url, $authorization = null): self;

    /**
     * 解析 Server-Sent Events 流
     *
     * @param StreamInterface $stream
     * @return \Generator
     */
    protected static function getMessages(StreamInterface $stream)
    {
        $buffer  = '';
        $message = [];
        while ($stream->isReadable() && !$stream->eof()) {
            try {
                $text = $stream->read(1);
                if ($text == "\r") {
                    continue;
                }
                $buffer .= $text;

                if ($text == "\n") {
                    if ($buffer == "\n") {
                        if (!empty($message)) {
                            yield $message;
                        }
                        $message = [];
                    } else {
                        if (preg_match('/^(\w+):(.*)$/', trim($buffer), $match)) {
                            $message[$match[1]] = trim($match[2]);
                        }
                    }
                    $buffer = '';
                }
            } catch (Throwable) {
                break;
            }
        }
    }

    /**
     * 获取下一个请求 ID
     *
     * @return int
     */
    protected function getNextId(): int
    {
        return $this->id++;
    }

    /**
     * 获取当前会话 ID
     *
     * @return string|null
     */
    protected function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * 设置会话 ID
     *
     * @param string|null $sessionId
     */
    protected function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }
}
