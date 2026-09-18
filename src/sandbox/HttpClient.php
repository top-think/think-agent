<?php

namespace think\agent\sandbox;

use Generator;

class HttpClient extends Client
{
    public function __construct(
        protected RequestClient $request,
        protected string $sandboxId,
    )
    {
        if ($this->sandboxId === '') throw new \InvalidArgumentException('Sandbox ID is required');
    }

    public function listFiles(?string $path = null): array
    {
        $response = $this->request->get("sandboxes/{$this->sandboxId}/files");
        return $response['data'] ?? [];
    }

    public function readTextFile(string $path, int $offset = 0, int $limit = -1): string
    {
        $response = $this->request->get("sandboxes/{$this->sandboxId}/files/read", compact('path', 'offset', 'limit'));
        return $response['data']['content'] ?? '';
    }

    public function writeTextFile(string $path, string $content): void
    {
        $this->request->post("sandboxes/{$this->sandboxId}/files/write", compact('path', 'content'));
    }

    public function editFile(string $path, string $oldStr, string $newStr): void
    {
        $this->request->post("sandboxes/{$this->sandboxId}/files/edit", [
            'path' => $path, 'old_str' => $oldStr, 'new_str' => $newStr,
        ]);
    }

    public function deleteFile(string $path): void
    {
        $this->request->delete("sandboxes/{$this->sandboxId}/files", ['path' => $path]);
    }

    public function executeCommand(string $command, ?string $workdir = null, array $env = []): array
    {
        $data = ['command' => $command];
        if ($workdir !== null) $data['workdir'] = $workdir;
        if ($env) $data['env'] = $env;
        $response = $this->request->post("sandboxes/{$this->sandboxId}/process/execute", $data);

        return [
            'exitCode' => $response['data']['exit_code'] ?? 0,
            'stdout' => $response['data']['stdout'] ?? '',
            'stderr' => $response['data']['stderr'] ?? '',
        ];
    }

    public function executeCommandStream(string $command, ?string $workdir = null, array $env = []): Generator
    {
        $data = ['command' => $command, 'stream' => true];
        if ($workdir !== null) $data['workdir'] = $workdir;
        if ($env) $data['env'] = $env;
        $stream = $this->request->rawRequest('POST', "sandboxes/{$this->sandboxId}/process/execute", [
            'json' => $data, 'stream' => true,
        ])->getBody();

        $buffer = '';
        while (!$stream->eof()) {
            $buffer .= $stream->read(8192);
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 2);
                if (!str_starts_with($event, 'data:')) continue;
                $data = json_decode(ltrim(substr($event, 5)), true);
                if (!is_array($data)) continue;
                yield $data;
                if (in_array($data['type'] ?? null, ['exit', 'error'], true)) return;
            }
        }
    }

    public function browserExecute(array $data): array
    {
        return $this->request->post("sandboxes/{$this->sandboxId}/browser/execute", $data)['data'] ?? [];
    }

    public function watchFiles(?string $path = null, ?string $id = null): Generator
    {
        throw new \LogicException('File watching requires ws mode');
    }

}
