<?php

namespace think\agent\mcp;

use Exception;
use think\facade\Cache;

class Client
{
    /** @var Transport */
    protected $handler;

    public function __construct(protected $url, protected $transport = 'sse', protected $headers = null)
    {

    }

    public function getHandler()
    {
        if (empty($this->handler)) {
            $this->handler = match ($this->transport) {
                'sse' => ServerEvent::connect($this->url, $this->headers),
                'http', 'streamable-http', 'streamable_http' => StreamableHttp::connect($this->url, $this->headers),
                default => throw new Exception("Unsupported transport: {$this->transport}")
            };
        }
        return $this->handler;
    }

    public function listTools()
    {
        return Cache::remember("mcp-tools-{$this->url}", function () {
            return $this->getHandler()->listTools();
        }, 60 * 60 * 24);
    }

    public function callTool(string $name, ?array $arguments = null)
    {
        return $this->getHandler()->callTool($name, $arguments);
    }

    public function close()
    {
        if (!empty($this->handler)) {
            $this->handler->close();
            $this->handler = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
