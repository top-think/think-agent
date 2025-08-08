# ThinkAgent

基于 ThinkAI 实现的智能体基础库，为构建强大的 AI 智能体应用提供完整的框架支持。

## 项目简介

ThinkAgent 是一个基于 ThinkPHP 框架和 ThinkAI 的智能体开发库，它提供了构建 AI 智能体所需的核心组件和工具。该库支持多轮对话、工具调用、插件系统、OpenAPI 集成等功能，让开发者能够快速构建功能丰富的 AI 应用。

## 主要功能

### 🤖 智能体核心
- **抽象智能体类 (Agent)**: 提供智能体的基础框架，支持多轮对话和工具调用
- **流式响应**: 支持实时流式输出，提供更好的用户体验
- **消息管理**: 自动管理对话历史和上下文
- **Token 统计**: 精确统计和管理 Token 使用量

### 🔧 工具系统
- **函数调用 (FunctionCall)**: 灵活的工具调用机制，支持自定义工具
- **代码执行器 (CodeRunner)**: 内置 Python 代码沙箱执行环境
- **结果类型**: 支持多种结果类型（文本、JSON、图片、错误等）
- **参数验证**: 自动参数验证和类型检查

### 🔌 插件系统
- **内置插件**: 支持 ThinkAI 内置的各种插件工具
- **认证管理**: 安全的凭证管理和加密存储
- **OpenAPI 集成**: 自动解析 OpenAPI 规范并生成工具

### 🛠️ 实用工具
- **Token 编码**: 内置 tiktoken 编码器，支持精确的 Token 计算
- **文本处理**: 丰富的文本处理和格式化工具
- **配置管理**: 灵活的配置系统，支持多种配置选项

## 环境要求

- **PHP**: >= 8.0
- **ThinkPHP**: >= 6.0
- **扩展要求**:
  - `openssl` - 用于凭证加密
  - `json` - JSON 数据处理
  - `curl` - HTTP 请求支持

## 安装说明

### 使用 Composer 安装

```bash
composer require topthink/think-agent
```

### 手动安装

1. 下载源码到项目目录
2. 在 `composer.json` 中添加依赖：

```json
{
    "require": {
        "topthink/think-agent": "*"
    }
}
```

3. 运行 Composer 安装：

```bash
composer install
```

### 依赖说明

ThinkAgent 依赖以下核心包：

- `topthink/think-helper`: ThinkPHP 辅助函数库
- `topthink/think-ai`: ThinkAI 核心库，提供 AI 模型接口
- `topthink/framework`: ThinkPHP 框架核心
- `cebe/php-openapi`: OpenAPI 规范解析库

## 快速开始

### 创建基础智能体

首先，创建一个继承自 `Agent` 的智能体类：

```php
<?php

use think\agent\Agent;
use think\ai\Client;

class MyAgent extends Agent
{
    protected $config = [
        'model' => [
            'name' => 'gpt-3.5-turbo',
            'thinking' => 'enabled',
            'params' => [
                'temperature' => 0.8
            ]
        ]
    ];

    protected function init($params)
    {
        // 初始化智能体参数
        $this->canUseTool = true; // 启用工具调用
        $this->userInput = $params['input'] ?? '';
    }

    protected function buildPromptMessages()
    {
        // 构建提示消息
        return [
            [
                'role' => 'system',
                'content' => '你是一个智能助手，可以帮助用户解决各种问题。'
            ],
            [
                'role' => 'user',
                'content' => $this->userInput
            ]
        ];
    }

    protected function getClient(): Client
    {
        // 返回 ThinkAI 客户端实例
        return new Client('token');
    }

    protected function saveMessage($usage, $latency)
    {
        // 保存消息到数据库
        return null;
    }

    protected function consumeTokens(int $usage): int
    {
        // 消费 Token 逻辑
        return $usage;
    }
}
```

### 运行智能体

```php
// 创建智能体实例
$agent = new MyAgent();

// 运行智能体并获取流式响应
foreach ($agent->run(['input' => '你好，请介绍一下自己']) as $chunk) {
    if (isset($chunk['chunks'])) {
        // 处理流式内容
        echo $chunk['chunks']['content'] ?? '';
    }

    if (isset($chunk['stats'])) {
        // 处理统计信息
        echo "Token 使用量: " . $chunk['stats']['usage'] . "\n";
        echo "响应延迟: " . $chunk['stats']['latency'] . "ms\n";
    }
}
```

### 添加自定义工具

创建自定义工具类：

```php
<?php

use think\agent\tool\FunctionCall;
use think\agent\tool\Args;
use think\agent\tool\result\Plain;

class WeatherTool extends FunctionCall
{
    protected $name = 'get_weather';
    protected $title = '天气查询';
    protected $description = '获取指定城市的天气信息';
    protected $parameters = [
        'city' => [
            'type' => 'string',
            'description' => '城市名称',
            'required' => true
        ]
    ];

    public function run(Args $args)
    {
        $city = $args->get('city');

        // 模拟天气查询
        $weather = [
            'city' => $city,
            'temperature' => '25°C',
            'condition' => '晴朗',
            'humidity' => '60%'
        ];

        return new Plain("今天{$city}的天气：温度{$weather['temperature']}，{$weather['condition']}，湿度{$weather['humidity']}");
    }
}
```

在智能体中注册工具：

```php
class MyAgent extends Agent
{
    protected function init($params)
    {
        parent::init($params);

        // 注册自定义工具
        $this->addFunction('get_weather', new WeatherTool());

        // 注册代码执行器
        $this->addFunction('code_runner', new \think\agent\tool\CodeRunner());
    }
}
```

### 使用内置插件

ThinkAgent 支持使用 ThinkAI 内置的插件，只需指定插件名和工具名：

```php
protected function init($params)
{
    parent::init($params);

    // 使用内置插件，指定插件名和工具名
    $this->addPlugin('olejRejN', 'web');
}
```

## API 接口说明

### Agent 类

`Agent` 是智能体的抽象基类，提供了智能体的核心功能。

#### 主要方法

##### `run($params)` - 运行智能体
```php
public function run($params): \Generator
```
- **参数**: `$params` - 运行参数数组
- **返回**: 生成器，产生流式响应数据
- **说明**: 启动智能体并返回流式响应

##### `addFunction($key, FunctionCall $func, $args = [])` - 添加工具函数
```php
protected function addFunction($key, FunctionCall $func, $args = []): self
```
- **参数**:
  - `$key` - 工具唯一标识
  - `$func` - 工具函数实例
  - `$args` - 额外参数
- **返回**: 当前实例

##### `addPlugin($name, $tool, $args = [])` - 添加插件
```php
protected function addPlugin($name, $tool, $args = []): self
```
- **参数**:
  - `$name` - 插件名称
  - `$tool` - 插件实例
  - `$args` - 额外参数
- **返回**: 当前实例

#### 抽象方法（需要实现）

##### `init($params)` - 初始化
```php
abstract protected function init($params): void
```
- **说明**: 初始化智能体，设置配置和工具

##### `buildPromptMessages()` - 构建提示消息
```php
abstract protected function buildPromptMessages(): array
```
- **返回**: 消息数组
- **说明**: 构建发送给 AI 模型的消息

##### `getClient()` - 获取 AI 客户端
```php
abstract protected function getClient(): Client
```
- **返回**: ThinkAI 客户端实例

##### `saveMessage($usage, $latency)` - 保存消息
```php
abstract protected function saveMessage($usage, $latency): mixed
```
- **参数**:
  - `$usage` - Token 使用量
  - `$latency` - 响应延迟（毫秒）
- **返回**: 消息 ID 或其他标识

##### `consumeTokens(int $usage)` - 消费 Token
```php
abstract protected function consumeTokens(int $usage): int
```
- **参数**: `$usage` - 使用的 Token 数量
- **返回**: 实际消费的 Token 数量

### FunctionCall 类

`FunctionCall` 是工具函数的抽象基类。

#### 主要属性
```php
protected $name = null;        // 工具名称
protected $title = null;       // 工具标题
protected $description = null; // 工具描述
protected $parameters = null;  // 参数定义
```

#### 主要方法

##### `run(Args $args)` - 执行工具（抽象方法）
```php
abstract public function run(Args $args): Result
```
- **参数**: `$args` - 工具参数
- **返回**: 结果对象
- **说明**: 执行工具的核心逻辑

##### `setCredentials(Credentials $credentials)` - 设置凭证
```php
public function setCredentials(Credentials $credentials): self
```
- **参数**: `$credentials` - 凭证对象
- **返回**: 当前实例

##### `getCredential($name, $default = null)` - 获取凭证
```php
public function getCredential($name, $default = null): mixed
```
- **参数**:
  - `$name` - 凭证名称
  - `$default` - 默认值
- **返回**: 凭证值

### 内置插件使用

ThinkAgent 支持使用 ThinkAI 的内置插件，通过 `addPlugin()` 方法指定插件名和工具名：

```php
// 添加内置插件的语法
$this->addPlugin($pluginName, $toolName, $args = []);
```

#### 常用内置插件

> 可在这里查看 https://console.topthink.com/ai/plugin

#### 插件配置示例

```php
protected function init($params)
{
    parent::init($params);

    // 网页搜索
    $this->addPlugin('olejRejN', 'web');
}
```

### 结果类型

#### Plain - 纯文本结果
```php
use think\agent\tool\result\Plain;

return new Plain('这是一个文本结果');
```

#### Json - JSON 结果
```php
use think\agent\tool\result\Json;

return new Json(['key' => 'value']);
```

#### Image - 图片结果
```php
use think\agent\tool\result\Image;

return new Image('https://example.com/image.jpg');
```

#### Error - 错误结果
```php
use think\agent\tool\result\Error;

return new Error(new Exception('错误信息'));
```

## 配置选项说明

### 智能体配置

智能体的配置通过 `$config` 属性进行设置：

```php
protected $config = [
    'model' => [
        'name' => 'gpt-3.5-turbo',           // 模型名称
        'thinking' => 'enabled',              // 思维链模式
        'params' => [
            'temperature' => 0.8,             // 温度参数 (0-1)
            'max_tokens' => 2000,             // 最大 Token 数
        ]
    ],
    'history' => [
        'max_tokens' => 4000,                 // 历史消息最大 Token 数
        'enabled' => true,                    // 是否启用历史消息
    ]
];
```

### 模型配置详解

#### 基础配置
- **name**: AI 模型名称，如 `gpt-3.5-turbo`、`gpt-4` 等
- **thinking**: 思维链模式，可选值：
  - `enabled`: 启用思维链
  - `disabled`: 禁用思维链

#### 模型参数
- **temperature**: 控制输出的随机性 (0-1)
  - 0: 确定性输出
  - 1: 最大随机性
- **max_tokens**: 单次响应的最大 Token 数

### 工具配置

#### 工具参数定义
```php
protected $parameters = [
    'param_name' => [
        'type' => 'string',                   // 参数类型
        'description' => '参数描述',           // 参数说明
        'required' => true,                   // 是否必需
        'enum' => ['option1', 'option2'],     // 枚举值（可选）
        'default' => 'default_value',         // 默认值（可选）
    ]
];
```

#### 支持的参数类型
- `string`: 字符串
- `number`: 数字
- `integer`: 整数
- `boolean`: 布尔值
- `array`: 数组
- `object`: 对象

### 凭证配置

#### 凭证类型

##### HTTP Bearer Token
```php
protected $credentials = [
    'type' => 'http',
    'scheme' => 'bearer',
    'token' => [
        'title' => 'API Token',
        'description' => '请输入您的 API Token',
        'placeholder' => 'sk-...'
    ]
];
```

##### HTTP Basic Auth
```php
protected $credentials = [
    'type' => 'http',
    'scheme' => 'basic',
    'username' => [
        'title' => '用户名',
        'description' => '请输入用户名'
    ],
    'password' => [
        'title' => '密码',
        'description' => '请输入密码'
    ]
];
```

##### API Key
```php
protected $credentials = [
    'type' => 'apiKey',
    'key' => [
        'title' => 'API Key',
        'description' => '请输入 API Key',
        'placeholder' => 'your-api-key'
    ]
];
```

## 常见问题解答

### Q: 如何处理工具调用失败？

A: ThinkAgent 提供了完善的错误处理机制：

```php
public function run(Args $args)
{
    try {
        // 工具执行逻辑
        $result = $this->doSomething($args->get('param'));
        return new Plain($result);
    } catch (\Exception $e) {
        // 返回错误结果
        return new Error($e);
    }
}
```

### Q: 如何优化 Token 使用？

A: 可以通过以下方式优化：

1. **限制历史消息长度**：
```php
protected $config = [
    'history' => [
        'max_tokens' => 2000, // 限制历史消息 Token 数
    ]
];
```

2. **使用精确的 Token 计算**：
```php
use think\agent\Util;

$tokens = Util::tikToken($messages);
if ($tokens > $maxTokens) {
    // 截断消息
}
```

### Q: 如何实现自定义的消息存储？

A: 重写 `saveMessage` 方法：

```php
protected function saveMessage($usage, $latency)
{
    // 保存到数据库
    $messageId = db('agent_messages')->insertGetId([
        'content' => json_encode($this->chunks),
        'usage' => $usage,
        'latency' => $latency,
        'created_at' => time()
    ]);

    return $messageId;
}
```

### Q: 如何处理流式响应中的错误？

A: 在流式响应中捕获和处理错误：

```php
foreach ($agent->run($params) as $chunk) {
    if (isset($chunk['chunks']['error'])) {
        // 处理错误
        echo "错误: " . $chunk['chunks']['error'];
        break;
    }

    if (isset($chunk['chunks']['content'])) {
        // 处理正常内容
        echo $chunk['chunks']['content'];
    }
}
```

### Q: 如何调试智能体的执行过程？

A: 启用调试模式并记录关键信息：

```php
protected function iteration($messages, $tools)
{
    // 记录请求信息
    if (config('app.debug')) {
        trace('Agent Request: ' . json_encode($messages));
    }

    // 调用父类方法
    yield from parent::iteration($messages, $tools);
}
```

## 高级特性

### OpenAPI 集成

ThinkAgent 支持自动解析 OpenAPI 规范并生成工具：

```php
use think\agent\OpenApi;

class ApiPlugin extends OpenApi
{
    protected $openapi = 'https://api.example.com/openapi.json';
    protected $auth = [
        'type' => 'http',
        'scheme' => 'bearer',
        'provider' => 'user'
    ];
}
```

### 代码执行器

内置的 Python 代码执行器支持安全的代码执行：

```php
use think\agent\tool\CodeRunner;

$codeRunner = new CodeRunner();
$result = $codeRunner->run(new Args([
    'code' => 'print("Hello, World!")',
    'files' => [] // 可选的文件列表
]));
```

### Token 计算

精确计算消息的 Token 使用量：

```php
use think\agent\Util;

$messages = [
    ['role' => 'user', 'content' => '你好']
];

$tokenCount = Util::tikToken($messages);
echo "Token 数量: {$tokenCount}";
```

## 最佳实践

### 1. 错误处理
- 始终在工具中使用 try-catch 处理异常
- 返回有意义的错误信息
- 使用适当的结果类型

### 2. 性能优化
- 合理设置历史消息长度限制
- 使用缓存减少重复计算
- 异步处理耗时操作

### 3. 安全考虑
- 验证所有用户输入
- 使用凭证系统管理敏感信息
- 限制工具的执行权限

### 4. 可维护性
- 保持工具功能单一
- 使用清晰的命名和文档
- 编写单元测试

## 许可证

本项目采用 Apache-2.0 许可证。详情请参阅 [LICENSE](LICENSE) 文件。

## 贡献

欢迎提交 Issue 和 Pull Request 来改进这个项目。

### 开发环境设置

1. 克隆仓库
2. 安装依赖：`composer install`
3. 运行测试：`composer test`

### 提交规范

- 使用清晰的提交信息
- 遵循 PSR-12 编码规范
- 添加必要的测试用例

## 支持

如果您在使用过程中遇到问题，可以通过以下方式获取帮助：

- 查看文档和示例
- 提交 Issue
- 参与社区讨论

## 更新日志

### v1.0.0
- 初始版本发布
- 支持基础智能体功能
- 提供工具和插件系统
- 集成 OpenAPI 支持

---

**ThinkAgent** - 让 AI 智能体开发更简单！
```
```
```
```
```
