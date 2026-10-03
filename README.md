# XHProf 性能分析插件

![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.0-777bb4) ![CI](https://github.com/erikwang2013/xhprof-webman/actions/workflows/ci.yml/badge.svg) ![Release](https://img.shields.io/github/v/release/erikwang2013/xhprof-webman) ![License](https://img.shields.io/badge/license-MIT-blue)

**中文** · [English](./docs/i18n/en/README.md) · [한국어](./docs/i18n/ko/README.md) · [Русский](./docs/i18n/ru/README.md) · [Deutsch](./docs/i18n/de/README.md) · [Français](./docs/i18n/fr/README.md) · [Español](./docs/i18n/es/README.md) · [Português](./docs/i18n/pt/README.md) · [العربية](./docs/i18n/ar/README.md) · [हिन्दी](./docs/i18n/hi/README.md) · [বাংলা](./docs/i18n/bn/README.md) · [Bahasa Indonesia](./docs/i18n/id/README.md) · [日本語](./docs/i18n/ja/README.md)

兼容 webman / Laravel / ThinkPHP / Hyperf / Yii2 / Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal / 原生 PHP（无框架）的代码性能分析插件。

基于 xhprof 扩展采集数据并存入 Redis，开发者可通过浏览器快速访问性能分析报告，排查代码性能瓶颈。

![项目宠物：小火苗](docs/images/pet.svg)

同一只小火苗也是报告页的站点图标、左上角品牌图标与表格排序图标（`src/html/pet.svg`、`src/html/images/sort_*.svg`，随 `assets_url` 前缀服务）。

**请求记录**

![请求记录](docs/images/runs-list.png)

**单次运行报告**

![单次运行报告](docs/images/run-report.png)

**对比两次运行** — 在「请求记录」列表里勾选恰好两条（每行一个复选框，表头可全选），点「对比选中」进入 diff 视图。两侧按时间取先后（run1 = 较早、run2 = 较晚，与列表当前排序无关），着色语义是「从 run1 到 run2」的改善 / 回归，页内「反转」链接可随时交换两侧。

**导出于机器消费** — 报告页动作栏提供 JSON / CSV 导出（单次运行、对比、聚合三种视图都可导）；`?format=json` 不带 `run` 参数时返回**运行列表 JSON**（每条含 run_id、请求元数据与列表口径的头部信息），巡检脚本 / 看板取 run_id 不必再解析 HTML。带 `symbol=` 的导出请求会返回 400——导出没有单函数视图，静默给一份全量表比报错更坏。

## 环境要求

- PHP >= 8.0
- xhprof 扩展
- redis 扩展
- Redis 服务

## 兼容框架与最低版本

| 框架 | 最低版本 | 最低 PHP | 入口类 | 挂载方式 |
|------|---------|---------|--------|---------|
| webman | `workerman/webman ^2.1` | 8.0 | `Webman\XhprofMiddleware` | `config/middleware.php` 注册全局中间件 |
| Laravel | `laravel/framework ^9.0\|^10.0\|^11.0\|^12.0\|^13.0` | 8.0 | `Laravel\Middleware` | 11+ 用 `bootstrap/app.php` 的 `->withMiddleware()` 追加；10 及以下在 `app/Http/Kernel.php` 注册全局中间件 |
| ThinkPHP | `topthink/framework ^6.0\|^8.0` | 8.0 | `Thinkphp\Middleware` | `app/middleware.php` 注册全局中间件 |
| Hyperf | `hyperf/framework ^3.0` | 8.0 | `Hyperf\Middleware` | ConfigProvider 自动注册 |
| Yii3 | `yiisoft/middleware-dispatcher ^5.0` | 8.1 | `Yii3\XhprofMiddleware` | `config/web/di/application.php` 注册，须放中间件队列第一位 |
| Symfony | `symfony/http-kernel ^6.4\|^7.0\|^8.0` | 8.1（6.4）/ 8.2（7.x）/ 8.4（8.x） | `Symfony\XhprofListener` | `config/services.yaml` 加 `kernel.event_subscriber` tag |
| Slim 4 | `slim/slim ^4.12` | 8.0 | `Slim\XhprofMiddleware` | `$app->add(...)`，须最后 add |
| WordPress | 6.4+ | 8.0 | `Wordpress\XhprofPlugin` | 复制到 `wp-content/mu-plugins/` |
| Joomla | 4.4 / 5.x | 8.1 | `Joomla\Extension\Xhprof` | 复制到 `plugins/system/`，后台「发现」安装 |
| Drupal | 10.x / 11.x | 8.1（10.x）/ 8.3（11.x） | `xhprof` 模块（`Drupal\XhprofMiddleware`） | 标准模块，启用即可 |
| 原生 PHP（无框架） | —（无外部包） | 8.0 | `Native\XhprofBootstrap` | 入口文件顶部一行 `XhprofBootstrap::start()`，无需注册控制器与路由 |
| Yii2 | `yiisoft/yii2 ^2.0` | 8.0 | `Yii2\XhprofBootstrap` | `config/web.php` 的 `bootstrap` 数组注册，无需注册控制器与路由 |

入口类命名空间前缀统一为 `ErikWang2013\Xhprof\`（上表省略）。十二家都不需要你注册控制器与路由：报告页与静态资源由入口类自行接管，Drupal 则由模块路由提供（见 Drupal 一节）。

本包声明 `php >= 8.0`，但 Yii3 依赖的 `yiisoft/*` 组件要求 **PHP 8.1+**，所以 **Yii3 在 PHP 8.0 上不可用**；Symfony 7.x、Drupal 11.x 同理需要更高的 PHP 版本。逐步接入方式见下方「框架配置」。

## 安装

xhprof 扩展从 PECL 安装（PHP 8 下当前是 2.3.x）：

```sh
pecl install xhprof
```

php.ini 中增加 xhprof 配置：

```ini
[xhprof]
extension=xhprof.so
xhprof.output_dir=/tmp/xhprof
```

Composer 安装：

```sh
composer require aaron-dev/xhprof-webman
```

### 快速开始

三步走完最短路径：

1. **装扩展** —— `pecl install xhprof`，并在 php.ini 里加上 `[xhprof]` 段（`extension=xhprof.so`、`xhprof.output_dir=/tmp/xhprof`）。
2. **起 Redis** —— `redis-server --daemonize yes`；或用你已有的实例（连接参数落在各框架配置文件 `config/xhprof.php` 的 `redis` 子数组里）。
3. **接入并打开报告页** —— `composer require aaron-dev/xhprof-webman`，在任一家框架按「框架配置」把入口类挂上，产生一次业务请求后访问 `http://<你的站点>/xhprof`。

> **不想装环境？** `demo/` 里有一套 docker compose 演示（原生 PHP 入口，不依赖任何框架）：`cd demo && docker compose up -d`，然后打开 `http://127.0.0.1:8080/xhprof` 就能看到一份真实报告页；说明见 `demo/README.md`。

### 排障速查

| 症状 | 先查什么 |
|------|---------|
| 报告页空白，列表里没有记录 | `enable` 是否为 `true`；`sample_rate` 是否被调成 `0`（此时只有带 `X-Xhprof-Token` 头的请求会被采样）；Redis 里 `<key_prefix>:run_id` 是否为空 |
| 报告页返回 403 / 401 | 403：`ip_allowlist` 挡住了当前 IP（或请求 IP 来自转发头而 `trusted_proxies` 为空），或配了 `auth_token` 而 URL 没带 `?token=`；401 并弹出浏览器凭据框：配了 `auth_basic` 且输入的用户名/密码与配置不符 |
| 报错连不上 Redis | redis 扩展是否装上（`php -m` 输出里有 `redis`）、Redis 是否在运行、`redis` 子数组的 host / port / password / database 是否与实例一致 |
| 装了扩展，业务请求却不落库 | 入口类是否真的挂上（见「框架配置」）；请求路径是否命中 `ignore_url_arr`；`max_runs_per_minute` 是否已达上限（超出即不采，要等下一分钟） |
| 报告页能打开，样式/脚本却 404 | `assets_url` 前缀是否与部署路径一致；反向代理是否把该前缀也转发到应用 |

---

## 框架配置

### Webman

**1. 注册全局中间件** — `config/middleware.php`：

```php
return [
    '' => [
        ErikWang2013\Xhprof\Webman\XhprofMiddleware::class,
    ],
];
```

**2. 报告页与静态资源** — **无需注册控制器与路由**：中间件在采样开始前判断请求路径，命中报告路径 `/xhprof` 直接输出报告页并返回，命中资源路径（前缀从配置项 `assets_url` 读，默认 `/xhprof-assets`）直接输出静态资源。

**3. 配置** — 见 `config/plugin/aaron-dev/xhprof/xhprof.php`。

---

### Laravel

**1. 注册中间件** — Laravel 11 及以上（slim skeleton 起就没有 `app/Http/Kernel.php`）在 `bootstrap/app.php`：

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\ErikWang2013\Xhprof\Laravel\Middleware::class);
})
```

Laravel 10 及以下仍在 `app/Http/Kernel.php` 的 `protected $middleware` 数组里追加 `\ErikWang2013\Xhprof\Laravel\Middleware::class`。

**2. 报告页与静态资源** — **无需注册控制器与路由**：中间件在采样开始前判断请求路径，命中报告路径 `/xhprof` 直接输出报告页并返回，命中资源路径（前缀从配置项 `assets_url` 读，默认 `/xhprof-assets`）直接输出静态资源。

**3. 发布配置**：

```sh
php artisan vendor:publish --tag=xhprof-config
```

配置文件在 `config/xhprof.php`。Laravel 支持自动发现 ServiceProvider。

**4. CLI 与队列**（需要时先打开 `sample_cli`，默认关闭）— 两类没有 HTTP 请求的入口都在采样窗口内跑：

- **队列 worker**：在 `AppServiceProvider::boot()` 里把四个事件接到包内监听器，按消息开停（常驻 worker 不漏）：

```php
use ErikWang2013\Xhprof\Laravel\XhprofQueueListener;
use Illuminate\Queue\Events\{JobProcessing, JobProcessed, JobFailed, JobExceptionOccurred};

Event::listen(JobProcessing::class, [XhprofQueueListener::class, 'onJobProcessing']);
Event::listen(JobProcessed::class, [XhprofQueueListener::class, 'onJobProcessed']);
Event::listen(JobFailed::class, [XhprofQueueListener::class, 'onJobFailed']);
Event::listen(JobExceptionOccurred::class, [XhprofQueueListener::class, 'onJobExceptionOccurred']);
```

- **artisan 命令**：`xhprof:profile` 随包自动注册（Laravel 自动发现 ServiceProvider），直接用：

```sh
php artisan xhprof:profile "migrate --force"
```

- **自定义脚本 / 定时任务**：非 artisan 入口（自写 PHP 脚本、闭包任务）在任务体外面包一层：

```php
\ErikWang2013\Xhprof\Laravel\XhprofCli::start();
try { /* 原逻辑 */ } finally { \ErikWang2013\Xhprof\Laravel\XhprofCli::stop(); }
```

窗口按任务开停（同步派发的子任务嵌套时只记最外层一条），落库的 `request_uri` 记为 `cli:<脚本名>`。

---

### ThinkPHP

**1. 注册中间件** — `app/middleware.php`：

```php
return [
    \ErikWang2013\Xhprof\Thinkphp\Middleware::class,
];
```

**2. 报告页与静态资源** — **无需注册控制器与路由**：中间件在采样开始前判断请求路径，命中报告路径 `/xhprof` 直接输出报告页并返回，命中资源路径（前缀从配置项 `assets_url` 读，默认 `/xhprof-assets`）直接输出静态资源。

**3. 配置** — 复制 `vendor/aaron-dev/xhprof-webman/src/Thinkphp/config/xhprof.php` 到项目 `config/xhprof.php`。

---

### Hyperf

**1. 中间件自动注册** — ConfigProvider 自动将中间件加入 HTTP 中间件队列。

**2. 报告页与静态资源** — **无需注册控制器与路由**：中间件在采样开始前判断请求路径，命中报告路径 `/xhprof` 直接输出报告页并返回，命中资源路径（前缀从配置项 `assets_url` 读，默认 `/xhprof-assets`）直接输出静态资源。

**3. 发布配置**：

```sh
php bin/hyperf.php vendor:publish aaron-dev/xhprof-webman
```

配置输出在 `config/autoload/xhprof.php`。

---

### Yii3

**1. 注册中间件** — `config/web/di/application.php`：

```php
use ErikWang2013\Xhprof\Yii3\XhprofMiddleware;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;

return [
    MiddlewareDispatcher::class => [
        'class' => MiddlewareDispatcher::class,
        // the first element is the outermost middleware (runs first, finishes last)
        'withMiddlewares()' => [[
            XhprofMiddleware::class,
            // ... other middlewares
        ]],
    ],
];
```

两个容易写错的地方（均已实测）：

- **不要**写 `'__construct()' => ['middlewares' => [...]]`：`MiddlewareDispatcher::__construct()` 只接受 `MiddlewareFactory` 与可选的 `EventDispatcherInterface`，**没有** `middlewares` 参数，中间件列表只能通过 `withMiddlewares()` 这个**实例方法**注入。
- **不要**把实例（`new XhprofMiddleware(...)`）放进 `withMiddlewares()`：定义只接受类名字符串 / 数组定义 / callable。传实例时注册阶段不报错，直到 `dispatch()` 才抛 `TypeError`（`MiddlewareFactory::create()` 的形参类型是 `callable|array|string`）。

**2. 报告页与静态资源** — **无需注册控制器与路由**：`XhprofMiddleware` 是 PSR-15 中间件，在采样开始前判断请求路径，命中报告路径 `/xhprof` 直接输出报告页并返回，命中资源路径（前缀默认 `/xhprof-assets`）直接输出静态资源。报告页响应由入口类显式带上 `Content-Type: text/html; charset=UTF-8`：PSR-7 响应没有默认值、Yii3 的响应发送器也不补，缺了它浏览器会把 HTML 报告按纯文本渲染。

**3. 配置** — 默认值在包内 `src/Yii3/config/xhprof.php`，字段含义见「配置项说明」。需要覆盖时在 DI 里注入 `$config`：

```php
XhprofMiddleware::class => [
    'class' => XhprofMiddleware::class,
    '__construct()' => [
        'config' => [
            'enable' => true,
            'auth_token' => 'your-token',
            'redis' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'password' => '',
                'database' => 0,
                'timeout' => 1.0,
            ],
        ],
    ],
],
```

`redis` 子数组是 Yii3 独有的：不注入 `CacheInterface` 时，中间件用它直连 phpredis。`assets_url` 可以改成任意前缀：报告页的 CSS/JS 链接与 `StaticController` 读的是同一个配置项（默认 `/xhprof-assets`）。目录/子路径部署下的剩余限制见[验证与已知限制](#验证与已知限制)。

**4. 版本要求** — Yii3 依赖的 `yiisoft/*` 组件要求 PHP >= 8.1，本包虽然声明 `php >= 8.0`，但在 PHP 8.0 上无法使用 Yii3 接入。

---

### Symfony

**1. 注册事件订阅器** — `config/services.yaml`：

```yaml
services:
    ErikWang2013\Xhprof\Symfony\XhprofListener:
        tags:
            - { name: kernel.event_subscriber }
```

**2. 报告页与静态资源** — **无需注册控制器与路由**：监听器在采样开始前判断请求路径，命中报告路径 `/xhprof` 直接输出报告页并返回，命中资源路径（前缀默认 `/xhprof-assets`）直接输出静态资源。

**3. 配置** — 默认值在包内 `src/Symfony/config/xhprof.php`，字段含义见「配置项说明」。

**4. 子请求与异常兜底** — 监听 `kernel.request`（优先级 10000）与 `kernel.response`（优先级 -10000）。用 `isMainRequest()` 过滤 ESI/fragment 子请求，否则子请求结束会把采样提前 stop；请求开始时另注册幂等的 `register_shutdown_function` 兜底——HttpKernel 重抛异常时 `kernel.response` 不会触发，没有兜底则采样状态会泄漏到下一个请求。

---

### Slim 4

**1. 注册中间件** — `public/index.php`：

```php
use ErikWang2013\Xhprof\Slim\XhprofMiddleware;

$app->addRoutingMiddleware();

// must be added last: Slim's middleware stack is LIFO, added later = further out = runs first
$app->add(new XhprofMiddleware(
    $app->getResponseFactory()
));
```

后三个构造参数都是可选的，省略即用包内默认值：

- 第 2 个参数 `array $config`：用户配置数组，与包内 `src/Slim/config/xhprof.php` 用 `array_replace` 合并（整块替换，不会递归合并 `ignore_url_arr` 这类列表键）。
- 第 3 个参数 `CacheInterface $cache`：省略则惰性 `new \Redis()`（构造函数刻意不碰 ext-redis，没装扩展也不会在建适配器时就炸）；要注入自己的连接就传 `new \ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter($redis)`，或任何实现了 `ErikWang2013\Xhprof\Core\Contract\CacheInterface` 的对象。
- 第 4 个参数 `LoggerInterface $logger`：省略即 `new \ErikWang2013\Xhprof\Slim\Adapter\LogAdapter()`，**日志会被静默丢弃**（Slim 自身不提供 PSR-3 logger）；要记录就传 `new LogAdapter($psrLogger)`，`$psrLogger` 是你自己已有的 PSR-3 logger。

**不要写 `$app->add(XhprofMiddleware::class)`**：类名字符串会由 Slim 的 `CallableResolver` 解析成 `new XhprofMiddleware($container)`——只传容器这一个参数，而且解析推迟到请求期。结果是 `add()` 阶段不报错、第一个请求才抛 `TypeError`，属于最难排查的失败方式。一律用上面的显式 `new`。

**2. 报告页与静态资源** — **无需注册控制器与路由**：中间件在采样开始前判断请求路径，命中报告路径 `/xhprof` 直接输出报告页并返回，命中资源路径（前缀默认 `/xhprof-assets`）直接输出静态资源。

**3. 配置** — 默认值在包内 `src/Slim/config/xhprof.php`，字段含义见「配置项说明」。

**4. 挂载顺序** — Slim 的中间件栈是 LIFO（实测两个中间件的执行序列为 `B:before → A:before → A:after → B:after`）：`add()` 越晚越靠外层、越先执行。所以 xhprof 必须**最后 add**，并且**晚于 `addRoutingMiddleware()`**——否则 `/xhprof` 不在路由表里，RoutingMiddleware 会先抛 `HttpNotFoundException`，请求根本到不了中间件。报告页响应由入口类显式带上 `Content-Type: text/html; charset=UTF-8`：PSR-7 响应没有默认值、Slim 的 `ResponseEmitter` 也不补，缺了它浏览器会把 HTML 报告按纯文本渲染。

---

### WordPress

**1. 安装 mu-plugin** — 把包内引导文件复制到 `wp-content/mu-plugins/`：

```sh
cp vendor/aaron-dev/xhprof-webman/wordpress/xhprof-webman.php wp-content/mu-plugins/
```

`wordpress/xhprof-webman.php` 带 plugin header，引导 `Wordpress\XhprofPlugin`。mu-plugin 会被自动加载，不需要在后台启用。

**2. 报告页与静态资源** — **无需注册控制器与路由**：入口类在采样开始前判断请求路径，命中报告路径 `/xhprof` 直接输出报告页并返回，命中资源路径（前缀默认 `/xhprof-assets`）直接输出静态资源。

**3. 配置** — 默认值在包内 `src/Wordpress/config/xhprof.php`，字段含义见「配置项说明」。用 `ignore_url_arr` 排除 `wp-cron.php`、`admin-ajax.php` 等高频路径。

需要覆盖配置（Redis 地址、`auth_token` 等）时，在 `wp-config.php` 里定义常量即可（mu-plugin 加载很早，常量此时已可用）：

```php
define('XHPROF_WEBMAN_CONFIG', [
    'auth_token' => 'your-token',
    'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'password' => '', 'database' => 0],
]);
```

也可以挂 `xhprof_webman_config` 过滤器（在主题或插件里）：它在常量之上叠加，返回值与上面数组同形。

**4. 采样窗口的结构性限制** — 采样窗口是 `plugins_loaded` → `shutdown`，**不包含** `wp-settings.php` 的引导与插件加载本身。这是 WordPress 的结构性限制，该阶段的工作量无法被采样。

---

### Joomla

**1. 安装插件** — 把包内 `joomla/` 目录复制到站点的 `plugins/system/xhprof/`：

```sh
cp -r vendor/aaron-dev/xhprof-webman/joomla/ plugins/system/xhprof/
```

包内 `joomla/` 就是插件本体：`xhprof.xml` 清单、`services/provider.php`、`src/Extension/Xhprof.php`。入口类是 `ErikWang2013\Xhprof\Joomla\Extension\Xhprof`（`CMSPlugin`）。复制后在后台「系统 → 管理 → 扩展 → 发现」里执行「发现」并安装启用。

**2. 报告页与静态资源** — **无需注册控制器与路由**：插件在采样开始前判断请求路径，命中报告路径 `/xhprof` 直接输出报告页并返回，命中资源路径（前缀默认 `/xhprof-assets`）直接输出静态资源。

**3. 配置** — 默认值在包内 `src/Joomla/config/xhprof.php`，字段含义见「配置项说明」。**已知取舍**：这里读的是包内配置文件，而不是插件参数——插件参数要读数据库，而配置读取发生在每个请求上。

**4. 采样边界** — 采样窗口是 `ApplicationEvents::AFTER_INITIALISE` → `ApplicationEvents::AFTER_RESPOND`；`AFTER_INITIALISE` 时另注册幂等 `register_shutdown_function` 兜底，异常路径下 `AFTER_RESPOND` 不保证送达，没有兜底采样状态会泄漏到下一个请求。

---

### Drupal

**1. 启用模块** — 包内 `drupal/xhprof/` 是标准 Drupal 模块（`xhprof.info.yml` / `xhprof.routing.yml` / `xhprof.services.yml`），放到站点的 `modules/custom/xhprof/` 后在后台「扩展」页勾选启用（或 `drush en xhprof`）。

**2. 报告页与静态资源** — Drupal 是十二个框架里**唯一走「模块 + 路由」这一形态**的：`xhprof.routing.yml` 注册报告路径 `/xhprof` 与资源路径 `/xhprof-assets`，默认由模块 Controller 输出；其余十一家的入口类在采样开始前自行短路，自服务报告页与静态资源，不注册路由。**改成自定义 `assets_url` 前缀时资源改由中间件接管**：模块的资源路由 path 写死在 `xhprof.routing.yml`（`/xhprof-assets/{file}`），匹配不到别的前缀。

**3. 配置** — 配置是模块内的 typed config：默认值在 `drupal/xhprof/config/install/xhprof.settings.yml`，结构定义在 `drupal/xhprof/config/schema/xhprof.schema.yml`。字段含义见「配置项说明」。

**4. 中间件注册** — 在模块的 `xhprof.services.yml` 里注册中间件服务：

```yaml
services:
  xhprof.http_middleware:
    class: ErikWang2013\Xhprof\Drupal\XhprofMiddleware
    arguments: ['@config.factory', '@logger.factory']
    tags:
      - { name: http_middleware, priority: 1000 }
```

内侧 kernel 由 Drupal 的 `StackedKernelPass` **自动前插为构造参数 0**，**不要自己写**：写了会得到两个内侧 kernel，Drupal <= 11.2.x（含整个 10.x）在容器编译期直接失败，11.3.0 及以上则在每个请求上抛 TypeError。采样在 `finally` 中结束。

**5. 注意事项**

- `priority: 1000` 让中间件位于**页面缓存之外**（core 现有最高是 negotiation：D10 为 400、D11 为 500，而页面缓存是 200），因此**命中 Drupal 页面缓存的请求也会被采样**。对性能分析工具来说这是期望行为，但使用者需要知道。
- 缓存默认开箱即用：中间件内部默认用本包自带的 Redis 适配器（本包硬依赖 ext-redis），也可以按上面的 `arguments` 形式传入一个可选的 `CacheInterface` 覆写。若缓存不可用，落库失败会被 `XhprofProfiler::stop()` 吞成一条日志，**不会报错**。
- 报告页 `/xhprof` 与 `/xhprof-assets/*` 的请求**不采样**：中间件在 `xhprofStart()` 之前按路径跳过采样。响应仍由 `xhprof.routing.yml` 的 Controller 产生（**不是短路**）。因此即便把 `ignore_url_arr` 设为 `[]`（什么都不过滤），这两个请求也不会出现在报告里。

### 原生 PHP（无框架）

适用于没有框架、只有一个前端控制器的应用（`public/index.php` 之类）。

**1. 在入口文件顶部加一行**：

```php
\ErikWang2013\Xhprof\Native\XhprofBootstrap::start();
```

要改配置就把数组传进这一行（键集与另外十一家一致，默认值在 `src/Native/config/xhprof.php`）：

```php
\ErikWang2013\Xhprof\Native\XhprofBootstrap::start([
    'enable' => true,
    'auth_token' => 'xxx',
]);
```

第 2、3 个参数是可选注入点：`CacheInterface $cache` 与 `LoggerInterface $logger`（默认分别是本包的 Redis 适配器与 `error_log`）。返回值是本次请求的入口实例（`stop()` 幂等），同一进程里要提前停表就调它。

**2. 报告页与静态资源** — **无需注册控制器与路由**：这一行在采样开始前判断请求路径，命中报告路径 `/xhprof` 直接输出报告页（带 `Content-Type: text/html; charset=UTF-8` 与 `Cache-Control: no-cache, private`，`auth_token` 照常生效），命中资源路径（前缀从配置项 `assets_url` 读，默认 `/xhprof-assets`）直接输出静态资源。**代价写在明处**：这两条路径处理完就 `exit`——本次请求的余下流程（这一行之后的路由、容器引导、会话启动，以及应用自己注册的请求收尾逻辑）不会执行。

**3. 采样窗口 = 这一行 → 进程 shutdown**（挂的是 `register_shutdown_function`）。**边界照实说**：不包含这一行**之前**的代码（composer autoload、前端控制器的引导），也不包含别的进程/扩展做的事（php-fpm 的请求解析、nginx 侧处理）。正常结束、`exit`、未捕获的 Error / 异常都会到达止点；`SIGKILL` / OOM killer 不会——采样状态随进程消失，不会残留给下一个请求。收窄范围靠配置项 `ignore_url_arr`（对 `uri()` 子串匹配，不改代码生效）。

**4. 用 `php -S` 真跑一次**：

```sh
# public/index.php 顶部有 XhprofBootstrap::start()，并把它当前端控制器
php -S 127.0.0.1:8000 -t public public/index.php
```

访问 `http://127.0.0.1:8000/` 产生数据，再访问 `http://127.0.0.1:8000/xhprof` 看报告页——两者在同一个进程里，资源也一并验证到。

---

### Yii2

适用于 Yii2（`yiisoft/yii2 ^2.0`，PHP >= 8.0）。**与 Yii3 不是同一个框架**：Yii3 是 PSR-15 重写版，Yii2 有自己的一套 `yii\web\Request` / `Response` 与应用生命周期，入口类也因此不同。

**1. 注册引导类** — `config/web.php`：

```php
'bootstrap' => [
    [
        'class' => \ErikWang2013\Xhprof\Yii2\XhprofBootstrap::class,
        'config' => ['auth_token' => 'xxx'],   // 可选，键集见 src/Yii2/config/xhprof.php
    ],
],
```

这是 Yii2 官方扩展点 `yii\base\BootstrapInterface` 的标准注册形状：数组定义里的 `config` 键会由容器按**公有属性**赋给入口类（`cache` / `logger` 也是同样的注入点，传实例即可替换默认的 Redis 适配器与 `error_log`）。

**2. 报告页与静态资源** — **无需注册控制器、无需注册路由、无需改 urlManager**：引导类在 `Application::EVENT_BEFORE_REQUEST` 上判断请求路径，命中报告路径 `/xhprof` 直接输出报告页并结束请求，命中资源路径（前缀从配置项 `assets_url` 读，默认 `/xhprof-assets`）直接输出静态资源。两条路径都在采样开始前短路。

**3. 采样窗口 = `EVENT_BEFORE_REQUEST` → `EVENT_AFTER_REQUEST`**。注意 Yii2 的 `EVENT_AFTER_REQUEST` 在响应**发出之前**触发（`base/Application.php` 的 `run()`），所以「发送响应」本身不在窗口内。**异常路径靠 shutdown 兜底**：`run()` 只捕获 `ExitException`，业务抛出的其它异常会让 `EVENT_AFTER_REQUEST` 永不触发，因此起表时同时注册一次 `register_shutdown_function`（与 Symfony / Joomla 两家同形）。

**4. 控制台应用零影响** — `yii\console\Application` 不覆写 `run()`，所以 CLI 命令（cron、迁移、队列）**也会**触发 `EVENT_BEFORE_REQUEST`；引导类在 `bootstrap()` 里先判 `instanceof yii\web\Application`，控制台一条钩子都不挂。

**5. 客户端 IP 用框架语义** — `getRealIp()` 走 `Request::getUserIP()`：Yii2 默认把 `X-Forwarded-For` 这类转发头按 `secureHeaders` **滤掉**（安全默认），所以反代部署下拿到的是 `REMOTE_ADDR`；要让报告页记录真实客户端 IP，得在应用的 request 组件上配 `trustedHosts`（配了之后 Yii2 取的是「从右往左第一个不可信地址」，与其余适配器「无条件取首段」刻意不同）。这是框架自己的安全判定，本包不替站点决定信任谁。

**6. 配置** — 默认值在包内 `src/Yii2/config/xhprof.php`，用第 1 步里的 `config` 键覆盖；`redis` 子数组可选（不注入 `cache` 时用它直连 phpredis，键名与其余框架一致：`host` / `port` / `password` / `database` / `timeout`）。

---

## 配置项说明

所有框架共用以下配置项：

| 配置 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `enable` | bool | `true` | 是否启用性能分析 |
| `sample_rate` | float | `1.0` | 按比例采样：每个请求以该概率记录（如 `0.05` = 5% 请求被采样）；`1.0` = 全采，`<=0` 或 `false` = 不采 |
| `trigger_token` | string\|null | `null` | 按需触发采样：配置后，带请求头 `X-Xhprof-Token: <该值>` 的请求**强制采样**（无视 `sample_rate`，`0` 也采）；`null` 或空串 = 关闭，该请求头完全被无视。只认请求头、**不认 query**（query 会写进访问日志与 `Referer`）。它能强制任意请求全采样，密钥必须是够长的随机串且只发给可信的人 |
| `auth_basic` | string\|null | `null` | HTTP Basic 凭据（`user:password`，第一个冒号分隔、密码可含冒号）。与 `auth_token` 是**或**关系：任一配置即生效、任一通过即放行；都不配 = 不鉴权。**Apache+CGI/FastCGI 默认剥离 `Authorization` 头**（需 `CGIPassAuth On`，2.4.13+），nginx+php-fpm 不受此限 |
| `ip_allowlist` | array | `[]` | 报告页 IP 白名单，**逐字比对**：不支持 CIDR 网段、不做 IPv6 规范化（`2001:0db8::1` 与 `2001:db8::1` 是两个字符串）。空 = 关闭；写得不是数组 = 一律拒绝（fail closed，记一条 error 日志）。取值来自 `getRealIp()`，需与 `trusted_proxies` 一起理解 |
| `trusted_proxies` | array | `[]` | **部署声明，不是技术强制**：声明「我前面有可信代理」后，`ip_allowlist` 才接受来自 `X-Forwarded-For`/`X-Real-IP` 的客户端 IP。多数适配器无条件取转发头——声明了也**挡不住伪造 XFF**，仅当部署在可信代理之后才安全 |
| `webhook_url` | string\|null | `null` | 慢请求（`wt >= view_wtred`）落库后 POST JSON（`run_id`/`uri`/`wt`/`ct`/`ip`/`time`）到该地址。留空 = 不发送。**不是队列**：不等响应、无重试、无落盘补偿，端点慢或挂掉只丢这一条通知 |
| `sample_cli` | bool | `false` | CLI/无 HTTP 请求也采样：`true` 时落库的 `request_uri` 记为 `cli:<脚本名>`；`false` = 一律忽略（默认，含队列 worker 与定时任务）。能否生效取决于入口：Laravel 已内置（队列监听器 + `XhprofCli`，见 Laravel 一节），原生 PHP 入口天然按 CLI 形态工作（无 HTTP 依赖）；其余框架的入口是 HTTP 专用，需自行包一层 |
| `symbol_lookup_url` | string\|null | `null` | 源码链接模板：报告页渲染 `<模板>?symbol=<urlencoded 函数名>`；`null`/空 = 不显示链接 |
| `max_runs_per_minute` | int\|null | `null` | 自适应预算：每分钟最多记录多少条（分钟桶计数，超出不采）；`null`/非正数 = 关闭。缓存不可用/抛异常时 fail-open（照常按 `sample_rate`）；**触发采样不受它限制** |
| `time_limit` | int | `0` | 仅记录响应超过 n 秒的请求，0 表示全部 |
| `log_num` | int | `1000` | 最大记录条数 |
| `view_wtred` | int | `3` | 列表耗时超过 n 秒标红 |
| `ignore_url_arr` | array | `["/xhprof"]` | 忽略的 URL 路径 |
| `assets_url` | string | `/xhprof-assets` | 静态资源 URL 前缀 |
| `auth_token` | string\|null | `null` | 设置后报告页必须带 `?token=xxx` 才能访问。**默认 `null` 即不鉴权**：报告页与静态资源由入口类在宿主鉴权**之前**接管（代价见「报告页与静态资源」一节），未设置时任何能访问到该路径的人都能读到全部 run 的请求 URI、来源 IP 与函数名——公网/多租户部署**必须**设置；未设置时每次渲染记一条警告日志 |
| `key_prefix` | string | `xhprof` | Redis key 前缀，多项目共用 Redis 时务必改成各自独立的值 |
| `log_ttl` | int | `604800` | 性能数据保留时间（秒），默认 7 天 |
| `locale` | string\|null | `null` | 报告页语言：`zh_CN`/`en`/`ko`/`ru`/`de`/`fr`/`es`/`pt`/`ar`/`hi`/`bn`/`id`/`ja`；`null` = 跟随浏览器 `Accept-Language`，都匹配不上则中文；任意语言下都可用 `?lang=xx` 临时覆盖 |

各配置项在不同框架上的已知限制见[验证与已知限制](#验证与已知限制)。

调低 `sample_rate` 是唯一的按比例降压手段（`0.05` = 只记录 5% 的请求）；`ignore_url_arr` 仍是整条路径的兜底，两者可叠加。判定发生在采样入口（每次请求一次），不影响已存数据的读取与保留。非法值（如 `'5%'`、`'disabled'`）按 `1.0` 处理：宁可多采，也不静默变成「什么都不采」，让报告页看起来像坏了。

要清理性能数据：只清空列表页用 `DEL <prefix>:run_id`——数据键会随 `log_ttl` 自然过期，索引里的悬空 id 会被列表跳过；全清则把 `<prefix>:request_log:*` 与 `<prefix>:xhprof_log:*` 扫出来连同索引一起删（`DEL` 不接受通配符，先用 `redis-cli --scan --pattern '<prefix>:*'` 列出再删，别用 `KEYS`）。索引列表没有 TTL 是刻意的：它有界于 `log_num`，且只是指向数据键的指针（`<prefix>` 即本项目配置的 `key_prefix` 值）。

**触发采样（`trigger_token`）**

按需触发与按比例采样是两条独立轴，先判触发、再抽签：配上 `trigger_token` 后，生产环境可以把 `sample_rate` 压到 `0`（平时完全不采），要排查时给某个请求带上 `X-Xhprof-Token` 头，这次请求就会被完整采样。密钥比较用常量时间的 `hash_equals`；只在请求头上认，不要用 query 传（会写进访问日志、`Referer` 与浏览器历史）。触发不绕过 `ignore_url_arr`（报告页/静态资源请求即使带密钥也照常跳过），`enable: false` 依然是总开关。

**报告页鉴权（`auth_token` 与 `auth_basic`）**

`auth_token`（`?token=xxx`）与 `auth_basic`（HTTP Basic）是**或**关系：任一配置即生效、任一通过即放行；都不配 = 不鉴权（默认，每次渲染记一条警告日志）。Basic 凭据形如 `user:password`（第一个冒号分隔、密码可含冒号，用户名与密码两段都用 `hash_equals` 比对）；配了 Basic 而校验不通过时返回 401 并带 `WWW-Authenticate`——这是浏览器弹出凭据框的唯一触发方式，只用 token 且不通过则返回 403。**默认不鉴权是刻意的**：报告页由入口类在宿主鉴权**之前**接管，未配置时任何能访问到该路径的人都能读到全部 run 的请求 URI、来源 IP 与函数名，公网/多租户部署**必须**配上其中之一。**部署坑**：Apache + CGI/FastCGI 默认剥离 `Authorization` 头，Basic 会永远校不过（表现是一直 401）——需要 `CGIPassAuth On`（2.4.13+）或等效的转发变量；nginx + php-fpm 不受此限。

**IP 白名单与可信代理（`ip_allowlist` / `trusted_proxies`）**

白名单是**逐字比对**：不支持 CIDR 网段，也不做 IPv6 规范化（`2001:0db8::1` 与 `2001:db8::1` 是两个不同的字符串）；空 = 关闭；写得不是数组 = **一律拒绝**并记一条 error 日志（fail closed——静默关闭等于悄悄丢掉一层安全控制）。判定来源是适配器的 `getRealIp()`，而多数适配器收到 `X-Forwarded-For` / `X-Real-IP` 时**无条件**取转发头：直接拿它比对，任何客户端都能自报地址绕过白名单。所以还看 `trusted_proxies`：IP 值恰好来自转发头时，要求 `trusted_proxies` 非空，否则拒绝并记日志。**这是部署声明，不是技术强制**：声明了也挡不住伪造的 XFF，仅当部署在自己控制的代理之后才安全，中间跳数是否可信由你的代理配置负责。白名单闸门跑在凭据校验之前（拒绝即 403）。

**慢请求 webhook（`webhook_url`）**

响应耗时 `wt >= view_wtred` 的 run 落库后，向该地址 POST 一份 JSON（字段：`run_id` / `uri` / `wt` / `ct` / `ip` / `time`）。留空 = 不发送。**它不是队列**：fire-and-forget——连上、写完请求即断，不等响应、不读状态码，无重试、无落盘补偿，端点慢或挂掉只丢这一条通知（连接超时压到 200ms，DNS 解析不受此限）；任何失败只记一条 error 日志，绝不影响业务请求。列表页标红用的是严格 `>`，webhook 条件是 `>=`，边界差一档。

**自适应预算（`max_runs_per_minute`）**

每分钟最多记录多少条：计数的是**走到采样入口的请求数**（含没抽中的，判在抽签之前），超出即不采、下一分钟自动清零；`null`/非正数 = 关闭。计数走缓存：Redis 里会出现 `<key_prefix>:budget:<YmdHi>` 键（如 `xhprof:budget:202610032316`），首次 incr 时设 120 秒 TTL、过期自然清零——运维排查时看到它属于正常现象。缓存不可用/抛异常时 **fail-open**：照常按 `sample_rate` 采样，绝不因预算机制让请求失败或让采样静默停摆。**触发采样不受它限制**：拿着密钥来排查的人不该被预算挡在门外（判定顺序：触发 → 预算 → 抽签）。

**报告页的语言切换器**

导航右侧的下拉列出 13 种语言的**自称**（取自各词表里的 `_meta.name`，如「한국어」「日本語」）。每个选项的链接由**当前页面的查询串**生成（`XhprofLib::report_url()`），所以 `?token=`、排序、`run` 等参数都会跟着走；切换语言**不会离开当前视图**——在 run 报告页换语言仍停在同一个 run 上。

**报告页的诊断区**

报告页正文最上面那块卡片就是「诊断结论」（在操作栏与 run 说明之下）：先列「为什么慢」（最多 3 条归因），再列「其他发现」（最多 3 条体检项）。每条结论后的「查看」链接跳到该方法详情页；递归（R4）只在裸名确实在符号表里时才给链接——xhprof 把递归展开成 `fib@1`/`fib@2`，若符号表里只剩展开后的名字，按 `fib` 去查就会落空。六条规则与阈值：

- **R1** 自身耗时 ≥ 请求总耗时的 10%；
- **R2** 调用次数 ≥ 1000；
- **R3** 单条边的调用次数 ≥ 500，且被调方自身耗时 ≥ 请求总耗时的 5%；
- **R4** 同名符号出现在 ≥ 2 个不同深度（递归）；
- **R5** 自身内存峰值 ≥ 全局内存峰值的 30%；
- **R6** 自身耗时 > 总耗时（`excl_wt > wt`，逻辑上不可能）——数据完整性探针，健康数据下不触发。

阈值写死在 `src/Core/Analysis/Analyzer.php` 的常量里，目前**没有配置项**能调整或关闭诊断区（`enable` 关掉后没有采样数据，自然也没有诊断）。它**只在顶层单 run 视图**出现：diff 对比视图与函数详情页都不渲染——那两处传入的 `$symbol_tab`/`$totals` 不是单 run 的值（diff 模式下是 run2 − run1 的增量），据此分析没有意义。

---

## 手动初始化

如果自动检测框架失败，可以手动注入适配器：

```php
use ErikWang2013\Xhprof\Core\Xhprof;

Xhprof::bootstrap(
    new MyRequestAdapter($request),
    new MyResponseAdapter($response),
    new MyConfigAdapter(),
    new MyCacheAdapter(),
    new MyLoggerAdapter()
);
```

**除 `autoDetect()` 认识的那四个框架之外，其余八个（Yii2 / Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal / 原生 PHP）都不要调用无参 `Xhprof::bootstrap()`**——无参调用走 `autoDetect()`，它只认识 webman / Laravel / ThinkPHP / Hyperf 四个分支，在这八个上会直接抛 `Unsupported framework`。必须像上面的例子一样显式传入 5 个适配器（各框架配套的入口类已经替你做完了这件事）。

---

## 架构与设计思路

Core 只通过 5 个契约访问框架，5 个契约都在 `src/Core/Contract/`：

| 契约 | 方法 | 用途 |
|------|------|------|
| `RequestInterface` | `get()` `all()` `method()` `header()` `host()` `uri()` `url()` `getRealIp()` | 读请求参数、判报告路径、拼报告页链接 |
| `ResponseInterface` | `withBody()` `withHeaders()` `withStatus()` `file()` `send()` | 输出报告页、静态资源与 400/403 |
| `ConfigInterface` | `get()` | 读插件配置：`get('xhprof')` 取整块，`get('xhprof.assets_url')` 取叶子 |
| `CacheInterface` | `get()` `set()` `mget()` `incr()` `lPush()` `rPop()` `lRange()` `del()` `decr()` | Redis 读写 |
| `LoggerInterface` | `error()` | 扩展缺失、落库失败的告警 |

每个框架提供 5 个适配器实现这 5 个接口，由 `Xhprof::bootstrap()` 登记进 Core。框架相关的知识全部收在该框架自己的 `src/<Fw>/` 目录里。

**Core 对框架仅剩两处耦合**：

1. `Xhprof::autoDetect()` 里那串 `class_exists()` 分支（`Webman\App` → `Illuminate\Foundation\Application` → `think\App` → `Hyperf\Context\ApplicationContext`），只有无参 `bootstrap()` 会走到它。
2. 硬编码的 Hyperf 协程开关：`Xhprof::markHyperfContext()` 配合 `\Hyperf\Context\Context` 的存在性判断，决定适配器存进进程静态属性还是协程 Context。

**其余八个框架不经过 `autoDetect()`，全部走显式注入**：入口类自己 `new` 出 5 个适配器传给 `Xhprof::bootstrap($req, $res, $cfg, $cache, $log)`。原因是 PSR-7 / 框架自有请求对象的形态只能从请求管线里拿到，无参 `bootstrap()` 结构上不可能工作；另一个好处是 `autoDetect()` 保持「四个旧框架」的现状不再膨胀。

![架构](docs/images/architecture.svg)

上图讲**结构**：十二个框架各自的入口类、5 个契约、Core 的三层划分，以及仅剩的两处耦合点。

![设计思路](docs/images/design.svg)

上图讲**为什么这么设计**：5 条取舍的「决策 / 理由 / 代价」对照，顶栏是「扩展的 8 个框架对 `src/Core/` 的改动数 = 0」。

---

## 请求生命周期

一次被采样的请求：

1. 入口类在**采样开始前**先判路径：命中报告路径 → 直接输出报告页并返回；命中资源路径 → 直接输出静态资源。这两条路径都不采样，也不进入下面的流程。
2. `XhprofProfiler::isEnabled()` 读配置里的 `enable`；未开启或扩展缺失则整段跳过。
3. `Xhprof::xhprofStart()` → `XhprofProfiler::start()` → `xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY)`。
4. 执行业务逻辑。
5. `finally { Xhprof::xhprofStop(); }` → `xhprof_disable()` 后由 `XHProfRunsDefault::save_run()` 写入 Redis。用 `finally` 而不是顺序语句，是为了业务抛异常时采样状态也能被清理并落库。
6. 浏览器访问报告页，`Xhprof::index()` 从 Redis 读回数据并渲染。

![生命周期](docs/images/lifecycle.svg)

| 框架 | 采样开始 | 采样结束 |
|------|---------|---------|
| webman / Laravel / ThinkPHP / Hyperf | 中间件入口（`process()` / `handle()`） | `finally` |
| Yii3 / Slim 4 | PSR-15 `process()` | `finally` |
| Symfony | `kernel.request`（优先级 10000） | `kernel.response`（优先级 -10000），另有 shutdown 兜底 |
| WordPress | `plugins_loaded` | `shutdown` |
| Joomla | `onAfterInitialise` | `onAfterRespond`，另有 shutdown 兜底 |
| Drupal | `http_middleware`（priority 1000，最外层） | `finally` |
| 原生 PHP（无框架） | 入口文件顶部一行 `XhprofBootstrap::start()` | 进程 shutdown（`register_shutdown_function`），另有 `stop()` 可提前止表 |
| Yii2 | `EVENT_BEFORE_REQUEST` | `EVENT_AFTER_REQUEST`（在响应发出前触发），另有 shutdown 兜底 |

---

## 项目结构

```
xhprof-webman/
├── src/
│   ├── Core/                     # 框架无关：契约、报告页、Redis 落库、静态资源
│   │   ├── Contract/             # 5 个契约接口
│   │   ├── XhprofLib/            # 报告页渲染与 run 存储（源自 phacility/xhprof）
│   │   ├── Xhprof.php            # 静态门面：bootstrap() / index()
│   │   ├── XhprofProfiler.php    # xhprof_enable/disable 与配置
│   │   ├── StaticController.php  # /xhprof-assets 静态资源
│   │   ├── MiddlewareTrait.php   # Laravel / ThinkPHP 共享的采样包裹逻辑
│   │   └── RedisAdapterTrait.php # 各框架 Redis 适配器的共享实现
│   ├── Webman/ Laravel/ Thinkphp/ Hyperf/            # 既有 4 个框架
│   ├── Yii3/ Symfony/ Slim/ Wordpress/ Joomla/ Drupal/   # 新增 6 个框架
│   ├── Yii2/                     # Yii2：BootstrapInterface 入口类与 5 个适配器
│   ├── Native/                   # 原生 PHP（无框架）：入口类与 5 个适配器
│   └── html/                     # 报告页静态资源（css / js / images / pet.svg 站点图标与品牌图标）
├── wordpress/                    # mu-plugin 引导文件（带 plugin header）
├── joomla/                       # Joomla 插件（CMSPlugin + 清单）
├── drupal/xhprof/                # Drupal 标准模块（info / routing / services + Controller）
├── tools/contracts/              # 独立验证环：对真实框架包校验签名与语义（`legacy-symfony64/`、`legacy-symfony8/` 是两条旧版腿）
├── tools/i18n/                   # README 与三张 SVG 的翻译工具链（生成 / 校验 / 自检）
├── docs/i18n/                    # 12 份译文产物（英文、韩语、俄语、德语、法语、西班牙语、葡萄牙语、阿拉伯语、印地语、孟加拉语、印尼语、日语）
├── tests/                        # PHPUnit：适配器单测、接线测试、Core 单测、14 份 README 的结构一致性
├── demo/                         # docker compose 演示（原生 PHP 入口，不装环境看报告页）
└── docs/images/                  # README 配图
```

除 Drupal 外，每个 `src/<Fw>/` 目录形状一致：

```
src/<Fw>/
├── Adapter/{Request,Response,Config,Redis,Log}Adapter.php
├── <入口类>.php
└── config/xhprof.php             # 19 个配置键，与其它框架一致
```

`src/Drupal/` 是唯一的例外：它没有 `config/`，配置改用模块内的 typed config（`drupal/xhprof/config/install/xhprof.settings.yml`）。

---

## 验证与已知限制

**能机械证明的**

| 项 | 怎么证明 |
|---|---|
| 适配器与入口接线的行为 | `tests/Unit/Adapter/*Test.php`：enable 落库 / disable 不落库 / 业务抛异常时 `finally` 仍落库 |
| 十二个框架的配置 key 集一致 | 配置一致性测试（不逐字节比对，注释可不同） |
| 两份 README 逐段镜像 | README 一致性测试：比对 `##` / `###` 标题序列与代码块数量 |
| 适配器调用的方法真实存在 | `tools/contracts/` 验证环（独立 CI job，**三条腿**：主腿装各框架最新包，独立的 `tools/contracts/legacy-symfony64` 项目用同一份 Symfony case 跑 6.4，第三条 `tools/contracts/legacy-symfony8` 跑 Symfony 8.1 + Laravel 13（PHP 8.5））：装真实框架包（Drupal 用真 `drupal/core`，Joomla 用两个真实 CMS 发布包），对**已入环的 10 个框架**（Slim / Symfony / Yii3 / Yii2 / Joomla / WordPress / Drupal / Laravel / Webman / ThinkPHP）用反射断言每个方法 / 常量 / 全局函数存在；原生 PHP / Hyperf 未入环，原因各不相同，见下 |
| 适配器语义正确 | 同一验证环用真实类实例化请求与响应后跑适配器，含两条不变量：`uri()` 不含 scheme/host、`file()` 之后 `withHeaders()` 仍生效。环的 SKIP 总数是冻结常量（主腿 2、6.4 腿 0、8 腿 0），两条都在 Joomla：`#__extensions.params` 的真实读取路径、安装器形态，都需要数据库/安装器才能跑；每个 case 的断言数另有逐腿冻结下限（防编辑型缩水：早退/条件包裹把断言跑少而 status 仍 PASS 时红） |


**未自动化验证的（不要当成已验过）**

| 项 | 为什么没验 |
|---|---|
| 所有框架的接线（钩子是否真挂上、事件是否真触发） | 单测用的是桩，接线正确性目前只有手工冒烟能确认 |
| Joomla 剩下的两条子项 | 环里仍够不到、且原因都是需要数据库/安装器的那两件事：`#__extensions.params` 的真实读取路径（`PluginHelper::getPlugin()` → `bootPlugin()`）、安装器形态（namespacemap 被写过、`bootPlugin()` 找得到类） |
| Symfony 的 `kernel.event_subscriber` 自动配置 | 需要真实容器编译 |
| 长驻进程下的静态状态串扰 | **已按协程隔离**：按请求的渲染态在后端处于协程上下文时存进该环境的 Context（Hyperf / workerman 自动侦测，见 `Xhprof::coroutineContextClass()`）；非协程形态用进程级静态（FPM 本就每请求一进程）。验证：Hyperf 用真让出协程测试；workerman 协程在验证环的 Webman 卡里用真服务器 + 真 TCP 两请求交错钉住（A 挂起期间 B 整页渲染，A 醒来仍是自己的 run / 语言 / 指标列） |
| Hyperf 并发协程下的**采样**串扰 | xhprof 扩展与采样开关都是进程级：同一 worker 上两个协程在 IO 点交错时，先 stop 的取走数据（第二个 stop 幂等 no-op），后完成的 run 被丢、保存的那条混入两个协程的执行记录。渲染态已隔离（上行），采样态无法隔离（扩展语义）。需要干净数据时收紧 `sample_rate` 或对该场景关闭采样 |
| Laravel Octane（Swoole）下的静态状态串扰 | Octane 的依赖树里没有 workerman/workerman，`Workerman\Coroutine` 结构上不存在，webman 侧那个后端无法复用；Octane 要的是第三个后端 `\Swoole\Coroutine::getContext()`（约 10 行 + 一个 `class_exists` 分支），待有 Swoole 环境再开工 |
| 真实 Redis 读写、浏览器渲染、真实负载下的采样开销 | 真实 Redis 读写**已进验证环**（`cases/Redis.php`：真 phpredis + 真 Slim 端到端——业务请求 → 落库 → 列表页 → 报告页）；浏览器渲染与真实负载下的采样开销仍超出单测与验证环的范围 |
| 原生 PHP / Hyperf 的适配器签名与语义 | 两家未装入验证环（环覆盖 10 个框架），原因不同：**原生 PHP 没有第三方包可装**——环的对照物是真实框架包，对它不存在，其适配器语义由 `tests/Unit/Adapter/NativeTest.php` 用真超全局量 + 真 `php -S` 往返覆盖（观测面比环的 CLI 更强）；**Hyperf 能装但跑不起来**：真跑 `Context::set()` 抛 `Class "Swoole\Coroutine" not found`，环的 CI 只装 xhprof+redis，缺的 ext-swoole 协程运行时是**运行前提**而非可安装性，故桩仍是包内手写的 `tests/Stubs/framework-stubs.php`，没有真实包对照 |

**手工冒烟清单（每个框架三步）**

| 步骤 | 动作 | 期望 |
|------|------|------|
| 1 | 按「框架配置」挂上入口类 | 无报错 |
| 2 | 访问任意业务 URL | Redis 里 `xhprof:run_id` 的长度 +1 |
| 3 | 访问 `/xhprof` | 报告页与样式正常显示；`/xhprof-assets/js/xhprof_report.js` 返回 200 |

**原生 PHP 的冒烟**：用 `php -S 127.0.0.1:8000 -t public public/index.php` 起内置服务器（见「原生 PHP」第 4 步），三步照做——报告页与资源跟业务请求在**同一个进程**里，第 3 步能直接验证到。

**已知限制：列表页显示的 `request_uri` 不含端口**

`host()` 契约的语义是「仅 host，不含端口」（R-2），十二个框架都遵守，只是实现方式不同：PSR-7 的 `getHost()` 天然不含端口，Joomla / WordPress 手工 `parse_url` 一次，Webman 与 ThinkPHP 要传严格参数 `host(true)`（默认参数会把 `Host` 头连同端口原样返回）。列表页显示的 `request_uri` 由 `host() . uri()` 拼成（`src/Core/XhprofLib/Utils/XHProfRunsDefault.php`），所以非标准端口（如 `:8080`）部署时，列表里那行 URL **文本**不体现端口。**链接本身不受影响**：列表页与报告页内的链接统一由 `XhprofLib::report_url()` 生成相对 URL（只含 path + query），点进去是正确的页面，不依赖 host。

**`assets_url` 已支持自定义前缀**

静态资源前缀不再是硬编码常量：`src/Core/StaticController.php` 按配置项 `assets_url` 匹配资源路径（默认 `/xhprof-assets`，尾斜杠可有可无）。**十二个框架都跟随这个配置**：十一家的入口类在采样开始前自行短路并服务资源；Drupal 的默认前缀由模块路由 + Controller 服务、自定义前缀由中间件接管。**边界**：Laravel、Hyperf、Webman、ThinkPHP 四家不再需要控制器与路由——中间件先跑，按旧版说明注册过的那两条路由只是被遮蔽：既不会报错，也不会再被命中。目录/子路径部署下的剩余限制见下一条 Drupal。

**已知限制：Drupal 装在子目录时路径守卫失效**

Drupal 装在子目录（如 `/sites/app/xhprof`）时，路径守卫匹配不上带 base path 的 URI，于是回到「采样但不落库」的行为（默认配置下由 `ignore_url_arr` 兜住）。

**Symfony 6.4 兼容性**

Symfony 6.4 的兼容性是实测过的（并因此修掉了两处在 7.4 上看不出的过度拟合：`Request` 属性在 6.4 无原生类型声明、`prepare()` 补的 charset 大小写不同）。**所有腿都进 CI**：主腿 7.x、独立的 `tools/contracts/legacy-symfony64` 项目（跑同一份 case，不复制）以及第三条 `tools/contracts/legacy-symfony8` 腿（Symfony 8.1 + Laravel 13，PHP 8.5）——全部都进 tag 门禁。

---

## 作者

[艾瑞可 erik](https://erik.xyz)

本包以 MIT 授权发布（见 `LICENSE`）；`src/Core/XhprofLib/**`、`src/html/js/xhprof_report.js`、`src/html/css/xhprof.css` 派生自 [phacility/xhprof](https://github.com/phacility/xhprof)（Apache-2.0），沿用其条款；第三方前端库清单见 `NOTICE`。

## 开源不易，欢迎支持

<p align="center">
  <img src="./docs/weixinpay.png" alt="微信支付" width="130" height="130" title="微信支付" />
  <img src="./docs/alipay.png" alt="支付宝" width="130" height="130" title="支付宝" />
</p>

---

本插件参考 [phacility/xhprof](https://github.com/phacility/xhprof)、[xiexianbo123/xhprof](https://github.com/xiexianbo123/xhprof)
