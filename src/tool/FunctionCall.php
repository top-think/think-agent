<?php

namespace think\agent\tool;

use JsonSerializable;
use think\agent\Credentials;
use think\agent\tool\result\Plain;
use think\ai\Exception;
use think\helper\Arr;
use think\helper\Str;

abstract class FunctionCall implements JsonSerializable
{
    protected $name;
    protected $title;
    protected $description;
    protected $parameters;

    protected $extra;

    /** @var Credentials */
    private $credentials;

    /**
     * @param mixed $args
     *
     * @return Result
     */
    public function __invoke($args)
    {
        $res = $this->run(new Args($args));

        if (!$res instanceof Result) {
            $res = new Plain($res);
        }

        return $res;
    }

    public function setCredentials(Credentials $credentials)
    {
        $this->credentials = $credentials;

        return $this;
    }

    public function getCredential($name, $default = null)
    {
        if (!$this->credentials) {
            if ($default) {
                return $default;
            }

            throw new Exception('插件尚未授权');
        }

        return $this->credentials->get($name) ?: $default;
    }

    public function getExtra()
    {
        return $this->extra;
    }

    public function getLlmDescription()
    {
        $extra       = $this->getExtra();
        $description = $this->getDescription();

        if (!empty($extra)) {
            $description .= PHP_EOL . $extra;
        }

        return $description;
    }

    public function getLlmParameters()
    {
        $properties = [];
        $required   = [];

        $parameters = $this->getParameters();
        if (!empty($parameters)) {
            foreach ($parameters as $name => $parameter) {
                if (($parameter['provider'] ?? 'llm') != 'llm') {
                    continue;
                }

                if ($parameter['required'] ?? false) {
                    $required[] = $name;
                }

                $properties[$name] = Arr::only($parameter, ['type', 'description', 'enum', 'default', 'items']);
            }
        }

        if (empty($properties)) {
            return null;
        }

        return [
            'type'       => 'object',
            'properties' => $properties,
            'required'   => $required,
        ];
    }

    public function toLlm($name)
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => $name,
                'description' => $this->getLlmDescription(),
                'parameters'  => $this->getLlmParameters(),
            ],
        ];
    }

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

    public function getFee()
    {
        return -1;
    }

    public function getParameters()
    {
        return $this->parameters;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'name'        => $this->getName(),
            'title'       => $this->getTitle(),
            'description' => $this->getDescription(),
            'parameters'  => $this->getParameters(),
            'fee'         => $this->getFee(),
        ];
    }

    abstract protected function run(Args $args);
}
