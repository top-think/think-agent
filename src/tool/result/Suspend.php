<?php

namespace think\agent\tool\result;

use think\agent\tool\Result;

class Suspend extends Result
{
    public function __construct(protected $content) {}

    public function getResponse()
    {
        return '';
    }

    public function getContent()
    {
        return [
            'type'    => 'suspend',
            'suspend' => $this->content,
        ];
    }
}
