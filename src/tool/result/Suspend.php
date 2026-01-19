<?php

namespace think\agent\tool\result;

class Suspend
{
    public function __construct(protected $content) {}

    public function getContent()
    {
        return [
            'type'    => 'suspend',
            'suspend' => $this->content,
        ];
    }
}
