<?php

namespace think\agent\tool\result;

use think\agent\tool\Result;

class Json extends Result
{
    public function __construct(protected $data)
    {

    }

    public function getResponse()
    {
        return json_encode($this->data, JSON_UNESCAPED_UNICODE);
    }
}
