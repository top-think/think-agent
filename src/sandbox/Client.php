<?php

namespace think\agent\sandbox;

use Generator;

/**
 * Common sandbox operation client.
 *
 * HTTP and WebSocket clients implement the same sandbox operations. Resource
 * classes can therefore use either transport without knowing its protocol.
 */
abstract class Client
{
    abstract public function listFiles(?string $path = null): array;
    abstract public function readTextFile(string $path, int $offset = 0, int $limit = -1): string;
    abstract public function writeTextFile(string $path, string $content): void;
    abstract public function editFile(string $path, string $oldStr, string $newStr): void;
    abstract public function deleteFile(string $path): void;
    abstract public function executeCommand(string $command, ?string $workdir = null, array $env = []): array;
    abstract public function executeCommandStream(string $command, ?string $workdir = null, array $env = []): Generator;
    abstract public function browserExecute(array $data): array;
    abstract public function watchFiles(?string $path = null, ?string $id = null): Generator;
}
