<?php

namespace think\agent\sse;

use Generator;
use think\swoole\response\Iterator;

class ServerSentEvents extends Iterator
{
    public function __construct(Generator $generator)
    {
        $generator->rewind();

        $iterator = value(function () use ($generator) {
            try {
                while ($generator->valid()) {
                    $data = $generator->current();
                    if ($data instanceof ServerSentEvent) {
                        $message = $data->toString();
                    } else {
                        if (!is_string($data)) {
                            $data = json_encode($data);
                        }
                        if (!str_starts_with($data, ':')) {
                            $data = "data: {$data}";
                        }
                        $message = "{$data}\n\n";
                    }

                    $connected = yield $message;
                    $generator->send($connected);
                }
                yield "data: [DONE]\n\n";
            } catch (\Throwable) {
            }
        });

        parent::__construct($iterator);

        $this->header([
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
