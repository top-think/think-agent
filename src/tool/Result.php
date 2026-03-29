<?php

namespace think\agent\tool;

abstract class Result
{
    protected $usage = 0;
    protected $error = false;

    public function isError()
    {
        return $this->error;
    }

    public function setUsage($usage)
    {
        $this->usage = $usage;

        return $this;
    }

    public function getUsage()
    {
        return $this->usage;
    }

    public function getContent()
    {
        return '';
    }

    public function getMetadata()
    {
        return null;
    }

    abstract public function getResponse();
}
