# ThinkAgent

基于 ThinkPHP 和 ThinkAI 的 PHP 智能体框架，提供完整的 AI 智能体开发能力。

## 环境要求

- PHP >= 8.2
- Swoole 扩展 >= 4.0
- PHP 扩展：openssl、json、curl

## 安装

```bash
composer require topthink/think-agent
```

## 依赖

- `topthink/think-helper` - ThinkPHP 辅助函数库
- `topthink/think-ai` - ThinkAI 核心库，提供 AI 模型接口
- `topthink/framework` - ThinkPHP 框架核心
- `cebe/php-openapi` - OpenAPI 规范解析库

## 快速开始

### 创建智能体

继承 `Agent` 抽象类，实现 5 个必需方法：

```php
use think\agent\Agent;
use think\ai\Client;

class MyAgent extends Agent
{
    protected $config = [
        'model' => [
            'name'        => 'gpt-4o',
            'thinking'    => 'enabled',
            'params' => [
                'temperature' => 0.8,
            ],
        ],
    ];

    protected function init($params)
    {
        $this->canUseTool = true;
        $this->userInput  = $params['input'] ?? '';
    }

    protected function buildPromptMessages(): array
    {
        return [
            ['role' => 'system', 'content' => '你是一个智能助手。'],
            ['role' => 'user', 'content' => $this->userInput],
        ];
    }

    protected function getClient(): Client
    {
        return new Client('your-api-token');
    }

    protected function complete(): void
    {
        // 完成后的清理逻辑
    }

    protected function saveMessage($usage, $latency): mixed
    {
        return null;
    }
}
```

### 运行智能体

`run()` 方法返回 Generator，用于流式响应：

```php
$agent = new MyAgent();

foreach ($agent->run(['input' => '你好']) as $chunk) {
    if (isset($chunk['chunks'])) {
        // 文本内容
        echo $chunk['chunks']['content'] ?? '';
        // 思维链内容
        echo $chunk['chunks']['reasoning'] ?? '';
        // 工具调用状态
        if (isset($chunk['chunks']['tools'])) {
            // $chunk['chunks']['tools'] 包含 id、name、title、arguments、response 等字段
        }
    }

    if (isset($chunk['suspend'])) {
        // 智能体挂起，等待外部操作后可恢复
        break;
    }
}
```

## 核心架构

### Agent（智能体基类）

`think\agent\Agent` 是框架核心，负责：

- **多轮迭代**：通过 `iteration()` 实现工具调用后的自动多轮对话
- **流式输出**：实时返回文本（content）、思维链（reasoning）、签名（signature）
- **工具执行**：通过 Swoole 协程并发执行多个工具调用
- **历史管理**：`buildHistoryMessages()` 按Token预算裁剪历史消息
- **停止控制**：`stop()` 可中断当前执行，`Suspend` 结果可挂起等待恢复

需要实现的抽象方法：

| 方法 | 说明 |
|------|------|
| `init($params)` | 初始化智能体，设置配置和工具 |
| `buildPromptMessages()` | 构建发送给模型的消息数组 |
| `getClient(): Client` | 返回 ThinkAI 客户端实例 |
| `complete()` | 运行完成后的回调 |
| `saveMessage($usage, $latency)` | 保存消息，返回消息ID |

保护属性：

| 属性 | 说明 |
|------|------|
| `$config` | 模型和历史配置 |
| `$canUseTool` | 是否允许工具调用 |
| `$extraParams` | 附加的模型请求参数 |

### Plugin（插件基类）

`think\agent\Plugin` 是插件系统的抽象基类：

```php
use think\agent\Plugin;
use think\agent\tool\FunctionCall;

class MyPlugin extends Plugin
{
    protected $title       = '我的插件';
    protected $description = '插件功能描述';
    protected $icon        = 'emoji';
    protected $credentials = [
        'token' => [
            'encrypt'  => true,
            'required' => true,
            'type'     => 'string',
            'title'    => 'API Token',
        ],
    ];

    public function getTools(): array
    {
        return [new MyTool()];
    }
}
```

注册插件到智能体：

```php
protected function init($params)
{
    parent::init($params);
    // 注册 ThinkAI 内置插件
    $this->addPlugin('olejRejN', 'web');
    // 注册自定义插件实例
    $this->addPlugin(new MyPlugin(), 'tool_name');
}
```

### FunctionCall（工具基类）

`think\agent\tool\FunctionCall` 是所有工具的基类，需要实现 `run(Args $args)` 方法：

```php
use think\agent\tool\FunctionCall;
use think\agent\tool\Args;
use think\agent\tool\result\Plain;

class WeatherTool extends FunctionCall
{
    protected $name        = 'get_weather';
    protected $title       = '天气查询';
    protected $description = '获取指定城市的天气信息';
    protected $parameters  = [
        'city' => [
            'type'        => 'string',
            'description' => '城市名称',
            'required'    => true,
        ],
    ];

    protected function run(Args $args)
    {
        $city = $args->get('city');
        return new Plain("今天{$city}：晴，25°C");
    }
}
```

工具可以返回 Generator 实现进度报告：

```php
protected function run(Args $args)
{
    yield ['message' => '正在查询...'];
    // 执行耗时操作
    yield ['message' => '即将完成...'];
    return new Plain($result);
}
```

工具参数支持 `provider` 字段，标记为 `provider: 'user'` 的参数不会发送给 LLM，由运行时注入（用于凭证等敏感信息）。

注册自定义工具：

```php
protected function init($params)
{
    parent::init($params);
    $this->addFunction('get_weather', new WeatherTool());
}
```

工具钩子，在工具执行后触发自定义逻辑：

```php
$this->addFunction('get_weather', new WeatherTool());
$this->onFunctionCall('get_weather', function ($result) {
    // $result 是 Result 实例
});
```

### MCP Server 支持

框架支持通过 `addMcpServer` 添加 MCP（Model Context Protocol）服务器：

```php
protected function init($params)
{
    parent::init($params);
    $this->addMcpServer('server_name', 'https://mcp.example.com/sse', [
        'headers' => ['Authorization' => 'Bearer token'],
    ]);
}
```

### OpenAPI 集成

继承 `think\agent\OpenApi` 可自动从 OpenAPI 规范生成工具：

```php
use think\agent\OpenApi;

class WeatherApi extends OpenApi
{
    protected $auth = [
        'type'     => 'http',
        'scheme'   => 'bearer',
        'provider' => 'user',
    ];
}
```

支持从同目录下的 `openapi.yaml` 文件自动读取规范，也支持传入 YAML 字符串：

```php
$api = new WeatherApi($yamlString, $authConfig);
```

支持的认证方式：

- `http/bearer` - Bearer Token
- `http/basic` - Basic Auth
- `apiKey` - API Key（支持 header 和 query 两种位置）

`provider` 字段控制凭证来源：`user`（用户提供的加密凭证）、`system`（系统配置的固定值）。

OpenAPI Operation 支持自定义扩展字段：
- `x-operation-fee` - 工具调用计费
- `x-operation-template` - ThinkPHP 模板，用于格式化响应内容
- `x-parameter-provider` - 标记参数来源

## 结果类型

所有结果类型继承自 `think\agent\tool\Result`，通过 `getResponse()` 返回给 LLM 的文本，通过 `getContent()` 返回结构化数据给前端。

| 类型 | 类名 | 说明 |
|------|------|------|
| Plain | `result\Plain` | 纯文本，支持字符串或数组（自动 JSON 编码） |
| Json | `result\Json` | JSON 格式结果 |
| Image | `result\Image` | 图片结果，返回 URL（Agent 会自动调用 `saveImage` 本地化） |
| Raw | `result\Raw` | 原始结果，包含 response、content、error、usage、metadata |
| Error | `result\Error` | 错误结果，包装 Exception 对象 |
| Suspend | `result\Suspend` | 挂起结果，Agent 收到后停止迭代并返回 `suspend` 标记 |

```php
use think\agent\tool\result\Plain;
use think\agent\tool\result\Json;
use think\agent\tool\result\Image;
use think\agent\tool\result\Error;
use think\agent\tool\result\Suspend;

return new Plain('文本结果');
return new Json(['key' => 'value']);
return new Image('https://example.com/image.jpg');
return new Error(new \Exception('出错了'));
return new Suspend(['action' => 'confirm', 'data' => $info]);
```

结果支持计费：`return (new Plain($text))->setUsage(100);`

`Raw` 和 `Suspend` 结果支持元数据：`getContent()` 返回的数据会传递给前端。

## 内置工具

### CodeRunner（代码执行器）

通过 ThinkAI Sandbox API 安全执行 Python 代码：

```php
use think\agent\tool\CodeRunner;

$this->addFunction('runner', new CodeRunner($client));
```

- 自动创建和管理沙箱环境
- 支持 `files` 参数上传远程文件到 `/home/user` 目录
- 沙箱在 CodeRunner 销毁时自动清理
- 沙箱创建的 Token 消耗会计入总用量


## 配置项

### 模型配置

```php
protected $config = [
    'model' => [
        'name'        => 'gpt-4o',       // 模型名称
        'thinking'    => 'enabled',       // 思维链：enabled / disabled
        'params' => [
            'temperature' => 0.8,         // 温度 (0-1)
            'max_tokens'  => 2000,        // 最大输出 Token
        ],
    ],
    'user' => 'user-id',                 // 用户标识（透传给 API）
];
```

### 历史消息配置

`buildHistoryMessages()` 的 `$maxTokens` 参数控制历史消息的 Token 预算。当历史消息的 Token 总量超过预算的 60% 时，将停止添加更早的消息。

## 凭证管理

`think\agent\Credentials` 提供安全的凭证存储：

```php
use think\agent\Credentials;

$credentials = Credentials::make(
    ['token' => 'sk-xxxx'],
    ['token' => ['encrypt' => true]]
);

// 获取凭证（自动解密）
$value = $credentials->get('token');

// 脱敏显示
$masked = $credentials->get('token', true); // sk-****xxxx
```

加密使用 `config('app.token')` 作为密钥，采用 AES-128-ECB 加密算法。

## 许可证

Apache-2.0
