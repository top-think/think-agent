<?php

namespace think\agent\sse;

class ServerSentEvent
{
    public function __construct(public $id, public $data, public $event = null)
    {
        if (!is_string($data)) {
            $this->data = json_encode($data);
        }
    }

    public function toString(): string
    {
        $lines = '';
        foreach (get_object_vars($this) as $field => $value) {
            if ($value !== null) {
                $lines .= "{$field}: {$value}\n";
            }
        }
        return "{$lines}\n";
    }
}