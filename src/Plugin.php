<?php

namespace think\agent;

use JsonSerializable;
use think\agent\tool\FunctionCall;
use think\helper\Arr;
use think\helper\Str;

abstract class Plugin implements JsonSerializable
{
    protected $name;
    protected $title       = '';
    protected $description = '';
    protected $icon;
    protected $credentials;

    public function getName()
    {
        if ($this->name) {
            return $this->name;
        }

        return Str::snake(class_basename(static::class));
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getIcon()
    {
        return $this->icon;
    }

    public function getCredentials()
    {
        return $this->credentials;
    }

    /**
     * @return null|FunctionCall
     */
    public function getTool($name)
    {
        $tools = $this->getTools();

        $tool = Arr::first($tools, function ($tool) use ($name) {
            return $tool->getName() == $name;
        });

        if ($tool) {
            return $tool;
        }
    }

    abstract public function getTools();

    public function jsonSerialize(): mixed
    {
        return [
            'name'        => $this->getName(),
            'title'       => $this->getTitle(),
            'description' => $this->getDescription(),
            'icon'        => $this->getIcon(),
            'tools'       => $this->getTools(),
            'credentials' => $this->getCredentials(),
        ];
    }
}
