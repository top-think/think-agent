<?php

namespace think\agent\tool\result;

use think\agent\tool\Result;
use Throwable;

class Error extends Result
{
    protected $message = 'Unknown error';

    public function __construct(Throwable|string $exception)
    {
        $message = $exception instanceof Throwable ? $exception->getMessage() : $exception;

        $this->message = $message;
        $this->error   = true;
    }

    public function getResponse()
    {
        return 'error: ' . $this->message;
    }
}
