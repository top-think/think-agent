<?php

namespace think\agent\mcp;

use think\agent\tool\Args;
use think\agent\tool\FunctionCall;
use think\agent\tool\result\Raw;
use think\helper\Arr;

class Tool extends FunctionCall
{
    public function __construct(protected Client $client, $tool)
    {
        $this->name        = $tool['name'];
        $this->title       = $tool['title'] ?? $tool['name'];
        $this->description = $tool['description'];
        $this->parameters  = $tool['inputSchema'];
    }

    public function getParameters()
    {
        $required   = Arr::get($this->parameters, 'required', []);
        $parameters = Arr::get($this->parameters, 'properties', []);
        foreach ($required as $key) {
            if (isset($parameters[$key])) {
                $parameters[$key]['required'] = true;
            }
        }
        return $parameters;
    }

    public function getLlmDescription()
    {
        return $this->description;
    }

    public function getLlmParameters()
    {
        return $this->parameters;
    }

    protected function run(Args $args)
    {
        $result = $this->client->callTool($this->name, [...$args]);

        return new Raw([
            'response' => json_encode(Arr::get($result, 'content'), JSON_UNESCAPED_UNICODE),
            'error'    => Arr::get($result, 'isError', false),
        ]);
    }
}
