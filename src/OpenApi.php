<?php

namespace think\agent;

use cebe\openapi\Reader;
use cebe\openapi\spec\Operation;
use GuzzleHttp\Client;
use ReflectionClass;
use think\agent\tool\Args;
use think\agent\tool\FunctionCall;
use think\agent\tool\result\Plain;
use think\ai\Exception;
use think\facade\View;
use Throwable;

class OpenApi extends Plugin
{
    /** @var \cebe\openapi\spec\OpenApi */
    protected $openApi;

    protected $auth;

    public function __construct($schema = null, $auth = null)
    {
        try {
            if ($schema) {
                $this->openApi = Reader::readFromYaml($schema);
            } else {
                $this->openApi = $this->readFromYamlFile();
            }
        } catch (Throwable) {

        }
        if ($auth) {
            $this->auth = $auth;
        }
    }

    public function readFromYamlFile()
    {
        $c = new ReflectionClass($this);

        $filename = $c->getFileName();

        $schemaFile = dirname($filename) . '/openapi.yaml';

        if (!file_exists($schemaFile)) {
            return null;
        }

        return Reader::readFromYamlFile($schemaFile);
    }

    public function getCredentials()
    {
        try {
            if ($this->auth && $this->auth['provider'] == 'user') {
                switch ($this->auth['type']) {
                    case 'http':
                        switch ($this->auth['scheme']) {
                            case 'bearer':
                                return [
                                    'token' => [
                                        'encrypt'  => true,
                                        'required' => true,
                                        'type'     => 'string',
                                        ...$this->auth['token'],
                                    ],
                                ];
                            case 'basic':
                                return [
                                    'username' => [
                                        'required' => true,
                                        'type'     => 'string',
                                        ...$this->auth['username'],
                                    ],
                                    'password' => [
                                        'encrypt'  => true,
                                        'required' => true,
                                        'type'     => 'string',
                                        ...$this->auth['password'],
                                    ],
                                ];
                        }
                        break;
                    case 'apiKey':
                        return [
                            'key' => [
                                'encrypt'  => true,
                                'required' => true,
                                'type'     => 'string',
                                ...$this->auth['key'],
                            ],
                        ];
                }
            }
        } catch (Exception) {

        }
        return null;
    }

    public function getTools()
    {
        $tools = [];
        if (empty($this->openApi?->paths)) {
            return $tools;
        }

        foreach ($this->openApi->paths as $path => $definition) {
            foreach ($definition->getOperations() as $method => $operation) {

                if (empty($operation->operationId) || empty($operation->summary) || empty($operation->description)) {
                    continue;
                }

                $tools[] = new class($this->openApi, $path, $method, $operation, $this->auth) extends FunctionCall {

                    public function __construct(
                        protected \cebe\openapi\spec\OpenApi $openApi,
                        protected                            $path,
                        protected                            $method,
                        protected Operation                  $operation,
                        protected                            $auth
                    )
                    {
                    }

                    public function getTitle()
                    {
                        return $this->operation->summary;
                    }

                    public function getDescription()
                    {
                        return $this->operation->description;
                    }

                    public function getFee()
                    {
                        if (!empty($this->operation->fee)) {
                            return $this->operation->fee;
                        }

                        return 0;
                    }

                    public function getParameters()
                    {
                        $parameters = [];

                        foreach ($this->operation->parameters as $parameter) {
                            if (empty($parameter->name) || empty($parameter->description)) {
                                continue;
                            }
                            $param = [
                                'type'        => $this->transformType($parameter->schema->type ?? null),
                                'description' => $parameter->description,
                                'required'    => $parameter->required,
                            ];

                            if (!empty($parameter->provider)) {
                                $param['provider'] = $parameter->provider;
                            }
                            if (!empty($parameter->schema->default)) {
                                $param['default'] = $parameter->schema->default;
                            }
                            if (!empty($parameter->schema->enum)) {
                                $param['enum'] = $parameter->schema->enum;
                            }

                            $parameters[$parameter->name] = $param;
                        }

                        if (isset($this->operation->requestBody?->content)) {
                            foreach ($this->operation->requestBody->content as $content) {
                                if (isset($content->schema)) {
                                    $required   = $content->schema->required ?? [];
                                    $properties = $content->schema->properties ?? [];

                                    foreach ($properties as $name => $property) {
                                        if (empty($property->description)) {
                                            continue;
                                        }
                                        $param = [
                                            'type'        => $this->transformType($property->type ?? null),
                                            'description' => $property->description,
                                            'required'    => in_array($name, $required),
                                        ];
                                        if (!empty($property->provider)) {
                                            $param['provider'] = $property->provider;
                                        }
                                        if (!empty($property->default)) {
                                            $param['default'] = $property->default;
                                        }
                                        if (!empty($property->enum)) {
                                            $param['enum'] = $property->enum;
                                        }
                                        $parameters[$name] = $param;
                                    }
                                    break;
                                }
                            }
                        }

                        if (empty($parameters)) {
                            return null;
                        }
                        return $parameters;
                    }

                    public function getName()
                    {
                        return $this->operation->operationId;
                    }

                    protected function getAuthValue($name)
                    {
                        switch ($this->auth['provider']) {
                            case 'user':
                                return $this->getCredential($name);
                            case 'system':
                                return $this->auth[$name] ?? null;
                            default:
                                return null;
                        }
                    }

                    protected function run(Args $args)
                    {
                        $serverUrl = $this->openApi->servers[0]->url;

                        $pathParams = [];
                        $query      = [];
                        $headers    = [];

                        if (!empty($this->auth)) {
                            switch ($this->auth['type']) {
                                case 'http':
                                    switch ($this->auth['scheme']) {
                                        case 'bearer':
                                            $format = $this->auth['bearerFormat'] ?? 'Bearer';
                                            $token  = $this->getAuthValue('token');

                                            $headers['Authorization'] = "{$format} {$token}";
                                            break;
                                        case 'basic':
                                            $username = $this->getAuthValue('username');
                                            $password = $this->getAuthValue('password');

                                            $headers['Authorization'] = 'Basic ' . base64_encode("{$username}:{$password}");
                                            break;
                                    }
                                    break;
                                case 'apiKey':
                                    $key = $this->getAuthValue('key');
                                    switch ($this->auth['in']) {
                                        case 'header':
                                            $headers[$this->auth['name']] = $key;
                                            break;
                                        case 'query':
                                            $query[$this->auth['name']] = $key;
                                            break;
                                    }
                            }
                        }

                        foreach ($this->operation->parameters as $parameter) {
                            if ($parameter->required && !isset($args[$parameter->name])) {
                                throw new Exception('Missing required parameter: ' . $parameter->name);
                            }

                            $value = $args[$parameter->name] ?? $parameter->schema->default;

                            if (!empty($value)) {
                                switch ($parameter->in) {
                                    case 'path':
                                        $pathParams["{{$parameter->name}}"] = $value;
                                        break;
                                    case 'query':
                                        $query[$parameter->name] = $value;
                                        break;
                                    case 'header':
                                        $headers[$parameter->name] = $value;
                                }
                            }
                        }

                        $options = [
                            'query'   => $query,
                            'headers' => $headers,
                        ];

                        if (isset($this->operation->requestBody?->content)) {
                            foreach ($this->operation->requestBody->content as $contentType => $content) {
                                if (isset($content->schema)) {
                                    $data = [];

                                    $required   = $content->schema->required ?? [];
                                    $properties = $content->schema->properties ?? [];

                                    foreach ($properties as $name => $property) {
                                        if (in_array($name, $required) && !isset($args[$name])) {
                                            throw new Exception('Missing required parameter: ' . $name);
                                        }

                                        $value = $args[$name] ?? $property->default;

                                        if (!empty($value)) {
                                            $data[$name] = $value;
                                        }
                                    }

                                    switch ($contentType) {
                                        case 'application/json':
                                            $options['json'] = $data;
                                            break;
                                        case 'application/x-www-form-urlencoded':
                                            $options['form_params'] = $data;
                                            break;
                                    }

                                    break;
                                }
                            }
                        }

                        $path = str_replace(array_keys($pathParams), array_values($pathParams), $this->path);

                        $url = $serverUrl . $path;

                        $client = new Client([
                            'timeout' => 10,
                        ]);

                        $res = $client->request($this->method, $url, $options);

                        $content     = $res->getBody()->getContents();
                        $contentType = $res->getHeaderLine('content-type');

                        if (str_contains($contentType, 'application/json')) {
                            $content = json_decode($content, true);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                throw new Exception('Invalid response: ' . json_last_error_msg());
                            }
                        }

                        if (!empty($this->operation->template)) {
                            $content = View::display($this->operation->template, [
                                'response' => $content,
                            ]);
                        }

                        return (new Plain($content))->setUsage($this->getFee());
                    }

                    protected function transformType($type)
                    {
                        switch ($type) {
                            case 'integer':
                            case 'number':
                                return 'number';
                            case 'boolean':
                                return 'boolean';
                            default:
                                return 'string';
                        }
                    }
                };
            }
        }

        return $tools;
    }
}
