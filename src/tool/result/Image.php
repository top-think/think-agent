<?php

namespace think\agent\tool\result;

use think\agent\tool\Result;

class Image extends Result
{
    public function __construct(protected $url)
    {
    }

    public function getContent()
    {
        return [
            'type'  => 'image',
            'image' => $this->url,
        ];
    }

    public function getResponse()
    {
        return "image has been created and sent to user already, you should tell user to check it now.";
    }
}
