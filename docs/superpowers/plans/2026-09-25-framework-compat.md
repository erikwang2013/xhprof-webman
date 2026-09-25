# 六框架兼容 Implementation Plan

> **For agentic workers:** 本文件是 Wave 1 六个并行 agent 的**共同契约**。先通读「共享规范」，再读你自己那张框架卡。总方案（背景/决策/波次划分/验证方案）见 `/home/erik/.claude/plans/jazzy-stargazing-quokka.md`。

**目标：** 给 xhprof-webman 新增 Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal 六个框架的兼容，使总兼容数达到 10。

**Tech Stack:** PHP >= 8.0（**不能用 8.1+ 语法，尤其 `readonly`、枚举、`never`**；但个别框架自身的门槛更高，见各框架卡）、PHPUnit 11、零新增运行依赖。

---

## 一、共享规范（六个 agent 都必须遵守）

### 1.1 架构：Core 只经 5 个契约访问框架

`src/Core/Contract/` 下 5 个接口（接口文件内**没有 docblock**，语义全在调用点）：

```php
// RequestInterface —— 8 个方法
public function get(string $key, mixed $default = null): mixed;
public function all(): array;
public function method(): string;
public function header(string $name): ?string;
public function host(): string;
public function uri(): string;
public function url(): string;
public function getRealIp(): string;

// ResponseInterface —— 5 个方法
public function withBody(string $body): self;
public function withHeaders(array $headers): self;
public function withStatus(int $status): self;
public function file(string $path): self;
public function send(): mixed;

// ConfigInterface —— 1 个方法
public function get(string $key, mixed $default = null): mixed;

// LoggerInterface —— 1 个方法
public function error(string $message, array $context = []): void;

// CacheInterface —— 9 个方法
public function get(string $key): mixed;
public function set(string $key, mixed $value, ?int $ttl = null): mixed;
public function mget(array $keys): array;
public function incr(string $key): int;
public function lPush(string $key, mixed $value): int;
public function rPop(string $key): mixed;
public function lRange(string $key, int $start, int $end): array;
public function del(string ...$keys): int;
public function decr(string $key): int;
```

### 1.2 硬性语义（踩过坑的，验证环会断言）

| # | 约束 | 理由 |
|---|---|---|
| R-1 | **`uri()` 只返回 路径+query**，绝不含 scheme/host | `XhprofLib::isIgnore()` 对它做 `strpos` 子串匹配；返回绝对 URL 会让 host 叫 `xhprof.*` 的站点**每个请求**都被误判为需忽略。`StaticController::getPathFromRequest()` 还会对它 `parse_url(..., PHP_URL_PATH)` |
| R-2 | **`host()` 只返回 host，不含端口**（用户已拍板） | 契约如此。已知代价：`:8080` 部署下列表页链接会坏——既有问题，**本次不修**，已记入 README 已知限制 |
| R-3 | `header()` / `getRealIp()` / `host()` 无值时**必须返回 string 或 null，绝不给声明 `: string` 的方法返回 null** | `strict_types=1` 下会 `TypeError`。旧实现有「每个请求 500」的事故记录 |
| R-4 | **`withX()` 一律 `return $this`** | `Xhprof::deny()` 的调用链是 `withStatus()->withBody()->send()` |
| R-5 | **`withHeaders()` 必须在 `file()` 之后仍生效** | `StaticController::serve()` 的调用是 `file($realFile)->withHeaders([...])` |
| R-6 | `ConfigAdapter` 必须**同时**满足 `get('xhprof')` 返回整块数组、`get('xhprof.assets_url')` 返回叶子 | `XhprofProfiler::bootstrap()` 用前者、`Xhprof::index()` 用后者 |
| R-7 | 合并用户配置用 `array_replace`，**不要** `array_replace_recursive` | 后者对 `ignore_url_arr` 这类列表键逐下标合并，用户写 `['/admin']` 会得到 `['/admin','/旧值2',...]` 静默失配 |
| R-8 | `lPush` 返回值必须是 push 后的**列表长度**；`rPop` 空列表返回 falsy 且不得抛错；`mget([])` 必须返回 `[]` 而非 `false`；`set()` 的 `$ttl <= 0` 退化为不带过期的普通 SET | `_checkLogNum()` 依赖 lPush 的返回值裁剪；触发路径真实 |
| R-9 | `RedisAdapter` 直接 `use ErikWang2013\Xhprof\Core\RedisAdapterTrait;` 并实现 `protected function redis(): mixed` | R-8 的全部语义由该 trait 保证。`redis()` 返回 phpredis 实例**或**类名（trait 内部用 `call_user_func`，两种都支持） |

### 1.3 三条派生设计原则（六个 agent 的共同形状）

**A. 显式注入，不碰 `autoDetect()`。** `src/Core/Xhprof.php` 是**只读**的。入口类调
`Xhprof::bootstrap($req, $res, $cfg, $cache, $logger)` 显式传入 5 个适配器。
（`autoDetect()` 是无参 `bootstrap()` 的老路径，只认 4 个老框架；新框架走它只会抛 `Unsupported framework`。）

**B. 入口类自服务报告页，不注册控制器与路由。** 入口类在 `xhprofStart()` **之前**判路径：

```
uri() 去掉 query 后的路径 == 报告路径（/xhprof，硬编码）
    → $res->withStatus(200)->withBody(Xhprof::index())->send()，直接返回，不采样
uri() 以配置的 assets_url（默认 /xhprof-assets）+'/' 开头
    → Core\StaticController::serve($req, $res) 的结果 send()，直接返回，不采样
其余
    → bootstrap → isEnabled 判定 → xhprofStart → try{业务}finally{xhprofStop}
```

**唯一例外是 Drupal**（用户要求标准 Drupal 模块），见其框架卡。

**C. 不复用 `MiddlewareTrait`。** 它的 `runXhprof()` 没有报告页短路，复用它就得改
`src/Core/MiddlewareTrait.php`（那是只读的共享文件）。每个入口类自写 `handle()`，
`MiddlewareTrait.php` 零改动。

**入口类 `handle()` 的骨架（顺序不可换）**：

```php
// 1) 先 bootstrap —— 必须在 isEnabled() 之前！
//    否则长驻进程里会读到上一个请求的配置（XhprofProfiler::$config 是静态的）
Xhprof::bootstrap($req, $res, $cfg, $cache, $logger);

// 2) 报告页 / 资源短路（在 start 之前）
// 3) 守卫
$enabled = XhprofProfiler::isEnabled() && extension_loaded('xhprof');
// 4) try/finally 保证 stop
if ($enabled) { Xhprof::xhprofStart(); }
try { return $next(...); } finally { if ($enabled) { Xhprof::xhprofStop(); } }
```

### 1.4 允许与禁止改动的文件

**你独占（可以随便改）**：
- `src/<Fw>/**`（你的框架目录）
- `tests/Unit/Adapter/<Fw>Test.php`
- `tests/Stubs/Framework/<Fw>.php`（**若你需要框架桩**）
- `tools/contracts/cases/<Fw>.php`
- 框架清单目录（见各框架卡：`wordpress/`、`joomla/`、`drupal/`）

**只读，绝对不许改**（其他 agent 正在用）：
`src/Core/**`、`tests/Stubs/framework-stubs.php`、`tests/Stubs/Framework/Psr7.php`、
`tests/bootstrap.php`、`tests/Fixtures/Fakes.php`、`tests/Unit/Adapter/WiringTest.php`、
`tests/Unit/Adapter/HyperfTest.php`、`README.md`、`README.EN.md`、`docs/**`、
`composer.json`、`.github/**`、`phpunit.xml`、`.gitignore`、`tools/contracts/{run.php,case-runner.php,lib/**}`。

### 1.5 三条硬约束（违反即加载期致命错误或 CI 失败）

1. **你的桩文件里不得声明任何 `Psr\*` 接口。** 它们已冻结在 `tests/Stubs/Framework/Psr7.php`，
   重声明是 `Cannot declare interface` 致命错误。（`glob()` 按字母序加载，`Psr7.php` 在前，所以是 loud fatal，不会静默。）
2. **不得在全局命名空间声明 `class Redis`**——与 ext-redis 冲突，致命。要假件就用测试命名空间里的类并注入。
3. **WordPress 的单测必须用 `ob_start()` 包住 `send()`**——它会 `echo`，而 `phpunit.xml` 里 `failOnRisky=true`，裸输出会被标 risky 而失败。

### 1.6 环境陷阱（已实测）

**本机 `zend.assertions = -1`，`assert()` 被编译掉**：`assert(false)` 不抛异常，直接往下执行。
**任何用 `assert()` 写的自检/探针在这个环境里都是空转**，会打印「通过」而一个比较都没做。
要写自检就用显式 `if (...) { fwrite(STDERR, '...'); exit(1); }`，或用 PHPUnit 断言
（PHPUnit 自己的断言不受影响）。

### 1.7 已冻结、可直接用的假件

`tests/Stubs/Framework/Psr7.php`（测试命名空间 `ErikWang2013\Xhprof\Tests\Stubs\Framework`）：

```php
new FakeServerRequest(string $method = 'GET', string $uri = '/', array $serverParams = [], array $headers = [], string $body = '')
// query 自动从 URI 解析；默认 REMOTE_ADDR = 127.0.0.1
new FakePsrResponse(int $status = 200, array $headers = [], string $body = '')
new FakeStream(...)          // write() 追加累积
new FakeUri(...)             // RFC 3986 串回
new FakeResponseFactory()
new FakeStreamFactory()
```
全部 `withX()` 返回**新实例**、原实例不变；`getHeaderLine()` 大小写不敏感、缺省返回 `''`。

`tests/Fixtures/Fakes.php` 提供 `FakeRequest` / `FakeResponse` / `FakeConfig` / `FakeCache` / `FakeLogger`
（框架无关，直接实现 5 个契约）——单测适配器时优先用它们。

### 1.8 单测要求

基线是 **271 tests / 835 assertions**（`vendor/bin/phpunit --no-coverage` 全绿）。
你的测试加进去后必须仍然全绿，且**断言要有强度**：

- 每个适配器的每个方法都要有测试，**不要只测 happy path**。
- R-1～R-9 每条至少一条断言（`uri()` 不含 `://`、`host()` 去端口、`header()` 缺省返回 null、
  `withX()` 链式可调用、`file()` 后 `withHeaders()` 仍生效、`get('xhprof')` 与 `get('xhprof.assets_url')` 双形态、`lPush` 返回长度……）。
- 入口类至少三条：**enable 时落库** / **disable 时不落库** / **业务抛异常时 `finally` 仍执行 stop 并落库**。
  可参考 `tests/Unit/Adapter/WiringTest.php` 里既有 4 框架的写法（那是只读的，可以看，但不要改）。
- **不要写空转的断言。** 一条断言如果去掉被测代码后仍然通过，它就没有价值。
  写完请**逐条回退验证**：把你实现里的关键一行改坏，确认对应测试变红，再还原。

### 1.9 验证环 case 的要求

`tools/contracts/cases/<Fw>.php` 是一个独立 PHP 子进程里跑的脚本，用来**用真实框架包**验证你的适配器，
从而打破「桩由写适配器的人自己写」的循环论证。接口约定：

- 你的 case 文件返回/输出一段 JSON，由 `case-runner.php` 收集；具体形状照抄
  `tools/contracts/cases/Psr7.php` 的写法（**先读它**）。
- 能用真实包做的**必须做**：
  - **L1（签名存在）**：`ReflectionClass` 断言你的适配器调用的每个方法/常量/全局函数真实存在。
    这一层专杀 `Call to undefined method`——最怕的失效模式。
  - **L2（契约语义）**：用**真实类**实例化真实请求/响应，跑你的适配器，断言 R-1～R-5。
- **做不到的必须诚实标 SKIP 并写清原因**，不要假装覆盖。SKIP 数量是被硬断言的（`run.php` 有冻结期望），
  所以标 SKIP 是可见的、不会被当成通过。
- `tools/contracts/` 是**独立嵌套 composer 项目**，包里已有 `nyholm/psr7`、`nyholm/psr7-server`、
  `psr/http-message`、`psr/http-factory`，你的框架包需要的话在自己的 case 里说明，**不要改 `tools/contracts/composer.json`**
  （那是共享文件）——改不了就标 SKIP 并报告，由我统一加依赖。

### 1.10 报告要求

完成后报告：文件清单；`vendor/bin/phpunit --no-coverage` 的**逐字输出**（Tests/Assertions 行）；
`php tools/contracts/run.php` 的输出；**你逐条回退验证的记录**（改坏了哪一行、哪条测试变红、如何还原）；
以及**任何与我给你的前提不符的发现**——发现前提错了要立刻报告，**不要自作主张绕过去、也不要把检查调松让它通过**。

---

## 二、框架卡

### 2.1 Yii3

| | |
|---|---|
| 命名空间/目录 | `ErikWang2013\Xhprof\Yii3\` → `src/Yii3/` |
| 入口类 | `src/Yii3/XhprofMiddleware.php`，`implements Psr\Http\Server\MiddlewareInterface` |
| 目标版本 | `yiisoft/middleware-dispatcher ^5.0`，最低 PHP **8.1** |
| 挂载 | 用户 `config/web/di/application.php` 里把 `XhprofMiddleware::class` 放进 `MiddlewareDispatcher` 的 `withMiddlewares([...])` **数组第一位**（第一位 = 最外层） |

**必须解决的未知（本卡最高风险，优先做）**：`MiddlewareDispatcher` 的**真实构造形状**——
是 `withMiddlewares([...])` 实例方法、还是 DI 里的 `__construct()['middlewares']`？
以及 `__construct(ResponseFactoryInterface $f, ?array $config = null)` 的**第二个参数能否走反射默认值**
（`yiisoft/di` 对不可解析的可选参数的行为）。**在验证环里用真实的 `yiisoft/middleware-dispatcher` + `yiisoft/di` 定掉**，
把结论写进报告——README 现在写的是一个**未经验证的猜测片段**，需要用你的结论回填。

### 2.2 Symfony

| | |
|---|---|
| 命名空间/目录 | `ErikWang2013\Xhprof\Symfony\` → `src/Symfony/` |
| 入口类 | `src/Symfony/XhprofListener.php`，`implements Symfony\Component\EventDispatcher\EventSubscriberInterface` |
| 目标版本 | `symfony/http-foundation ^6.4\|^7.0`、`symfony/http-kernel ^6.4\|^7.0`；最低 PHP 8.1（6.4）/ 8.2（7.x） |
| 挂载 | 用户 `config/services.yaml` 加 `kernel.event_subscriber` tag |

**三个必须写死的守卫**：
1. `getSubscribedEvents()` 返回 `[KernelEvents::REQUEST => ['onRequest', 10000], KernelEvents::RESPONSE => ['onResponse', -10000]]`。
2. `onRequest` 里 **`if (!$event->isMainRequest()) return;`**——ESI / fragment / `forward()` 会产生子请求，
   子请求的 `kernel.response` 会把采样**提前 stop**，主请求后半段全部丢失。
3. `onRequest` 里注册**幂等** `register_shutdown_function` 兜底：HttpKernel 若在无异常监听器产出的情况下
   **重抛**，`kernel.response` 不触发，采样状态会泄漏到下一个请求（PHP-FPM 下是下一个请求，
   RoadRunner 下是同一进程的后续请求）。用 `private static bool $stopped` 守卫，二次 stop 必须无害。

报告页短路走 `RequestEvent::setResponse(new Response($html, 200))`。

### 2.3 Slim 4

| | |
|---|---|
| 命名空间/目录 | `ErikWang2013\Xhprof\Slim\` → `src/Slim/` |
| 入口类 | `src/Slim/XhprofMiddleware.php`，`implements Psr\Http\Server\MiddlewareInterface` |
| 目标版本 | `slim/slim ^4.12`；插件自身仍需 PHP 8.0 |
| 挂载 | `$app->add(new XhprofMiddleware(...))`，**必须最后 add** |

**必须解决的未知**：Slim 的 `Slim\MiddlewareDispatcher::addMiddleware()` 是「后加 = 更外层 = 先执行」
（已从源码推断：`$next = $this->tip; $this->tip = new class(...)`，路由中间件由 `seedMiddlewareStack()` 种在最内层）。
**在验证环里用真实的 `Slim\MiddlewareDispatcher` 跑两个中间件确认顺序**，并确认 `$app->getResponseFactory()` 的返回类型。
另外确认 `slim/psr7` 的 body 流**可写性**——我们的 `withBody` 实现依赖「每个响应只写一次」的不变量（先 `getBody()->write()`、不引入 PSR-17 流工厂）。

README 现在写的是 `$app->add(XhprofMiddleware::class)`（类名字符串走 CallableResolver）——**这是未验证的**，用你的结论回填。

### 2.4 WordPress

| | |
|---|---|
| 命名空间/目录 | `ErikWang2013\Xhprof\Wordpress\` → `src/Wordpress/`；引导文件 `wordpress/xhprof-webman.php` |
| 入口类 | `src/Wordpress/XhprofPlugin.php`（普通类，构造函数无参） |
| 目标版本 | WP 6.4+，最低 PHP 8.0 |
| 挂载 | 作为 **mu-plugin** 复制到 `wp-content/mu-plugins/`（mu-plugin 不用激活、不进数据库、升级不丢） |

**采样窗口**：起 = `add_action('plugins_loaded', ..., PHP_INT_MIN)`；止 = `add_action('shutdown', ..., PHP_INT_MAX)`。

**结构性限制（必须写进 README 与报告）**：窗口**不包含** `wp-settings.php` 的引导与插件加载本身——
WP 没有「一次请求的完整包裹」这个概念。范围收窄靠既有的 `ignore_url_arr`（`isIgnore()` 按 `uri()` 子串匹配），
**不改代码就能排除** `wp-cron` / `admin-ajax` / REST。

**报告页**：`plugins_loaded` 回调里先判 `$_SERVER['REQUEST_URI']`，命中 `/xhprof` 就 `echo Xhprof::index()` 再 `exit`；
`/xhprof-assets/` 同理走 `StaticController::serve()`。**不注册 rewrite 规则、不注册 REST 路由。**

**WordPress 的 RequestAdapter/ResponseAdapter 不使用任何框架对象**——只用超全局（`$_GET`/`$_POST`/`$_SERVER`）
与全局函数（`wp_unslash()`、`status_header()`、`is_ssl()`、`add_action()`）。
因此**验证环**只能做 **L1-lite：对真实 WP 源码做文本核对**（断言这些函数真实存在），
**没有 L2**——这一点必须诚实标 SKIP，不要伪装覆盖。

### 2.5 Joomla

| | |
|---|---|
| 命名空间/目录 | `ErikWang2013\Xhprof\Joomla\` → `src/Joomla/`；插件目录 `joomla/` |
| 入口类 | `src/Joomla/Extension/Xhprof.php`，`final class Xhprof extends CMSPlugin implements Joomla\Event\SubscriberInterface` |
| 目标版本 | Joomla 4.4 / 5.x，最低 PHP 8.1 |
| 包内布局 | `joomla/` **直接就是**插件本体：`joomla/xhprof.xml`（清单）、`joomla/services/provider.php`、`joomla/src/Extension/Xhprof.php`。用户 `cp -r vendor/aaron-dev/xhprof-webman/joomla/ plugins/system/xhprof/` 后在后台「发现」 |

`getSubscribedEvents()`：`ApplicationEvents::AFTER_INITIALISE => ['onAfterInitialise', Priority::NORMAL]`（起）、
`ApplicationEvents::AFTER_RESPOND => ['onAfterRespond', Priority::MIN]`（止）。

**止的兜底**：`onAfterInitialise` 里同时注册**幂等** `register_shutdown_function`
（异常路径下 `onAfterRespond` 不保证送达）。用 `private static bool $stopped` 守卫——
否则二次 `xhprof_disable()` 返回空数据会被写进 Redis。

**报告页**：`onAfterInitialise` 里判 `$_SERVER['REQUEST_URI']`，命中就 `echo Xhprof::index()` 然后 `$app->close()`。
零组件、零菜单项、零路由。

**配置来源（已知取舍，写进 README）**：读包内 `config/xhprof.php` + 用户显式传入的数组，
**不**用插件参数（`#__extensions` 里的 params 要读数据库，而配置读取发生在每个请求上）。

**验证环**：`Joomla\Input\Input` / `Joomla\Registry\Registry` / `Joomla\Uri\Uri` 是纯 PHP、无 DB，可直接 `new`，
所以 **Request/Config 适配器可得真实 L2**。`CMSPlugin` / `CMSApplicationInterface` 在 CMS 里、不在可安装包中 → 需手写桩。

### 2.6 Drupal

| | |
|---|---|
| 命名空间/目录 | `ErikWang2013\Xhprof\Drupal\` → `src/Drupal/`；模块目录 `drupal/xhprof/` |
| 入口类 | `src/Drupal/XhprofMiddleware.php`，`implements Symfony\Component\HttpKernel\HttpKernelInterface` |
| 目标版本 | Drupal 10.x（PHP 8.1）/ 11.x（PHP 8.3） |
| **唯一例外** | **这是六个里唯一用标准模块 + 路由提供报告页的**（用户拍板），不走「入口类自服务」 |

模块文件：`drupal/xhprof/xhprof.info.yml`、`drupal/xhprof/xhprof.routing.yml`、
`drupal/xhprof/xhprof.services.yml`、`src/Drupal/Controller/XhprofController.php`。

`xhprof.services.yml`：
```yaml
services:
  xhprof.http_middleware:
    class: ErikWang2013\Xhprof\Drupal\XhprofMiddleware
    # 只写**自己的额外参数**，内侧 kernel 由 core 的 pass 自动前插为参数 0。
    arguments: ['@config.factory', '@logger.factory']
    tags:
      - { name: http_middleware, priority: 1000 }
```

> **⚠️ 本卡最初给出的写法是错的，而且后果是站点级事故——已于 2026-09-25 用真实源码跨版本复核后更正。**
>
> 原写法写的是 `arguments: ['@xhprof.http_middleware.http_middleware_inner', '@config.factory', '@logger.factory']`，
> 并声称「串接靠该别名指向内侧、不是靠覆写构造参数 0」。**这个结论是完全反的。**
> 真实行为（`core/lib/Drupal/Core/DependencyInjection/Compiler/StackedKernelPass.php`，已跨版本实测）：
>
> | 版本 | `http_middleware_inner` | 后果 |
> |---|---|---|
> | ≤ 11.2.x（含**整个 10.x**） | **不存在**（0 命中） | 写它 → 编译期 `ServiceNotFoundException` → **容器构建失败、整站白屏** |
> | ≥ 11.3.0 | 存在（pass 自建 `setAlias`） | 写它 → 参数变 `[inner, inner, config, logger]` → 构造参数 1 拿到 `StackedHttpKernel` 而声明是 `ConfigFactoryInterface` → **每个请求 TypeError** |
>
> **两个版本里 pass 都自己把内侧 kernel 前插为构造参数 0**（10.x 源码注释逐字：
> `// Prepend the inner kernel as first constructor argument.` 后接 `array_unshift($arguments, new Reference($decorated_id));`）。
> core 自己的服务定义就是佐证：`http_middleware.reverse_proxy` 声明 `arguments: ['@settings']`，
> 而其构造是 `ReverseProxyMiddleware::__construct(HttpKernelInterface $http_kernel, Settings $settings)`。
>
> **priority 那条是对的**：`array_multisort(SORT_ASC)` + 逐个前插 → 高 priority 最后处理 → 最外层。
> core 现有最高是 negotiation（D10 为 400 / D11 为 500），`1000` 确实在页面缓存（200）之外，
> 因此**命中页面缓存的请求也会被采样**（对性能工具是期望行为，但须写进 README）。

`handle()` 里 `if (HttpKernelInterface::MAIN_REQUEST !== $type) return $this->httpKernel->handle($request, $type, $catch);`
（子请求直接透传，不参与采样），其余与通用骨架一致（`try/finally`）。

**Drupal 的 Request/Response 就是 Symfony 的**（Drupal 9 起移除了自己的 Request），
所以这两个适配器与 Symfony 卡逐字节同形是**预期**的——按决策「不抽共享层」复制即可，不要合并。

**验证环**：`symfony/http-foundation` 可真实 L2（与 Symfony 同源），
但 `ConfigAdapter` 的 `config.factory` 需要 booted kernel + DB → **标 SKIP 并说明**。

---

## 三、Wave 2+ 的收口（不属于 Wave 1 agent 的任务，列出以防越界）

`tests/Unit/Adapter/WiringTest.php`（6 框架 × 3 条接线）、
`tests/Unit/Adapter/AdapterParityTest.php`（Symfony↔Drupal、Yii3↔Slim 防漂移）、
`tests/Unit/Adapter/ConfigParityTest.php`（10 份 config 的 key 集一致）、
`tests/Unit/Docs/ReadmeParityTest.php`、`composer.json` 的 `suggest`/`keywords`
**全部由 Wave 2 的统一写者完成**。Wave 1 不要碰这些文件。

---

## 四、实施结果与**前提更正**（2026-09-25 收尾时回写）

> **本节存在的理由**：下面每一条都推翻了本文档**早先写下的**某个前提。文档如果停在上面，后来人会照着一个**已被证伪**的契约干活——而本项目已经因此栽过一次（`http_middleware_inner` 那条会让 Drupal 10 整站白屏）。**凡本文档与本节冲突，以本节为准。**

### 4.1 最终状态

| 项 | 结果 |
|---|---|
| 全量单测 | `OK (539 tests, 2111 assertions)` |
| 契约验证环 | 七个 case 全 PASS，`PASS 7 FAIL 0 SKIP 9（冻结期望 9）→ RESULT: OK` |
| README 双语 | 628/628 行、23/23 标题、50/50 围栏、层级序列逐项相同 |
| PHP 8.0 底线 | `src/` 91 + `tests/` 27 文件全通过 php-parser 8.0 解析；无 8.1+ 函数 |
| `src/Core/**` | **零改动**（六个新框架全部走显式注入，没碰 `autoDetect()`） |
| `composer.json` 的 `require` | 原封不动 —— **零运行依赖的设计没有被破坏**，六个框架全在 `suggest` |

### 4.2 被推翻的前提（逐条）

1. **§2.6 Drupal 的 `services.yml` 写法是错的，后果是站点级事故。** 原文让用户写
   `arguments: ['@xhprof.http_middleware.http_middleware_inner', ...]` 并声称「串接靠该别名、不是靠覆写构造参数 0」。
   **完全相反**：`StackedKernelPass` 自己把内侧 kernel 前插为**构造参数 0**（10.x 源码注释逐字：
   `// Prepend the inner kernel as first constructor argument.`），而 `http_middleware_inner` 别名
   **在 11.3.0 之前根本不存在**。照原文实施：D10/11.0–11.2 引用不存在的服务 → 编译期
   `ServiceNotFoundException` → **整站白屏**；≥11.3 重复注入 → 每请求 `TypeError`。
   已改成 `arguments: ['@config.factory', '@logger.factory']`（不写内侧 kernel）。
   **验证方式：跨 4 个版本拉真实源码 grep**（10.2/10.4/11.2 为 `0/0/2`，11.4 为 `2/1/2`）。
2. **§2.1 Yii3 的命名空间与注册形状都错。** 命名空间是 `Yiisoft\Middleware\Dispatcher\`（不是
   `Yiisoft\MiddlewareDispatcher\`）；注册是 `'withMiddlewares()' => [[...]]` **实例方法**形态，
   `__construct()` 只有 `MiddlewareFactory` 与可选 `EventDispatcherInterface`，**没有 `middlewares` 参数**。
   数组**第一位是最外层**（真实 dispatcher 实测 `A:before B:before B:after A:after`）。
   ⚠️ 真实包的 docblock 写「Last specified handler will be executed first」，**与实测相反**，以实测为准。
3. **§2.3 Slim 的 `$app->add(XhprofMiddleware::class)` 不安全**：`CallableResolver` 只把容器作为唯一实参、
   且解析**推迟到请求期** → 启动期静默、首个请求才 `TypeError`。**一律显式 `new`。**
   另：`slim/psr7` 原不在环依赖里，而 `AppFactory::create()` 默认用的正是它——等于没钉住真实用户拿到的对象（已补）。
4. **PSR-7 响应没有 `__toString()`**（nyholm 与 slim/psr7 都实测为无）。读 body 必须
   `(string) $response->getBody()`；写成 `(string) $response` 直接致命错误。
5. **§1.2 的 R-7 论证是错的，而且本文档给出的判别条件也错过一次。** 默认 `ignore_url_arr` 只有
   1 个元素，所以「用户传 1 个元素」时 `array_replace` 与 `array_replace_recursive` **逐字节相同**。
   真正的分叉条件是「**用户给的列表比默认的短**」（replace 整体替换、recursive 让默认值的尾巴活下来）——
   不是「键的并集不同」（更长时并不分叉）。**唯一可判别输入是 `[]`。**
   四张卡的第一版断言全部落在不可判别分支上，是回退验证抓出来的。
6. **报告页的 `Cache-Control: no-cache, private` 在 HttpFoundation 上「断字面量」是恒真的。**
   真实 `ResponseHeaderBag` 对**从未设过**该头的响应会自己算出**同一个字面量**（`get`/`all`/
   `hasCacheControlDirective` 三个观测点全被掩）。判别式是**扰动**：未设过的响应加 `Last-Modified` 后
   计算值翻成 `'private, must-revalidate'`，显式设过的纹丝不动。受影响的只有 Symfony 与 Drupal
   （走 HttpFoundation）；Slim/Yii3（PSR-7）与 WordPress/Joomla（各自 header 机制）不受影响，
   但也要求实测确认而非推理。
7. **`private static bool $stopped = true` 的初值是承重的，而任何进程内测试都测不到它。** 翻成 `false`
   时单测与环**全部全绿**——因为进程内只要先跑过一个完整周期，初值就被合法覆盖。只有
   「**全新进程 + 第一个周期就没开采样**」（`enable=false`）才暴露（空采样被写进 Redis，runs 0→1）。
   同类：Joomla 的 `$stopped` 初值（M2）也是**假覆盖**，原用例在进程内断言、初值早被前序用例改过。
8. **Symfony 卡的 `^6.4|^7.0` 只被测了一半。** 环里只装 7.4；用同一份 case 在真 6.4 上跑，抓出两处
   **只在 7.4 成立的过拟合断言**（`Request::$query/$request/$headers` 在 6.4 无原生类型；`prepare()`
   补的 charset 6.4 是 `UTF-8`、7.x 是 `utf-8`），已改成版本无关写法并两版复验。
   **CI 仍只跑 7.4**，README 的「验证与已知限制」已诚实标注这一点。
9. **WordPress 的 L2 是结构上做不到，不是偷懒。** 适配器不使用任何框架对象、只读超全局与全局函数；
   `php-stubs/wordpress-stubs` 的函数体是**空的**（实测 `wp_unslash('x')`/`is_ssl()` 都返回 `null`）。
   该 case 用一个子进程把这条理由做成**机器可复核**：哪天这个包有了真函数体，case 会 FAIL 要求重估 L2——
   **理由会自己过期报警，而不是腐烂成一句没人敢动的注释。**

### 4.3 验证覆盖面的诚实边界

**`EXPECTED_SKIPS = 9` 已签字**（Drupal 3 + Joomla 5 + WordPress 1），逐条理由记在
`tools/contracts/run.php` 的常量注释里。**L2 语义覆盖率是 4.5/6，不是 6。**

- **没被自动化验证的**：六个框架的**接线**（钩子/事件/标签是否真在真实应用里挂上）、WordPress 全链路、
  Joomla 插件发现与 `$app->close()`、Drupal 的服务串接与 `routing.yml`、Symfony 的
  `kernel.event_subscriber` 自动配置、真实 Redis 读写、长驻进程下的静态状态串扰（**继承自既有架构，
  Webman 同样如此**）。这些只能靠手工冒烟，README 里列了三步清单。
- **`EXPECTED_SKIPS` 必须与环境无关**：Joomla 的 case 在**缺 `ext-xhprof`** 时会把 4 条采样断言
  **诚实地计入 SKIP**（好过静默通过），总数会变成 13。因此 `contracts.yml` **必须装 xhprof**——
  一个随环境漂移的冻结常量不是闸门。
- **`assets_url` 实际是「只有保持默认值才有效」的假配置项**（`StaticController::URI_PREFIX` 硬编码）；
  Drupal 装在子目录时路径守卫也匹配不上。两条都进 README「已知限制」，**Core 本次不修**。

### 4.4 给后续维护者的三条

1. **`tests/Stubs/Framework/*.php` 是 `glob()` 全量加载的**：任何一张卡的桩在编辑中出现语法错误，
   **所有人的 phpunit 全部加载期 fatal**。跑不动测试时先
   `for f in tests/Stubs/Framework/*.php; do php -l $f; done` 看一眼是不是别人的在飞。
2. **不要把 md5 编进断言**：写死的哈希会随内容增长而说谎，一改注释就红，会逼人去更新常量而不是查真问题。
   md5 只用来确认基线；断言请用「归一化后逐字节比较」这类**语义**判据。
3. **`tests/Stubs/Framework/Symfony.php` 里的 `ResponseHeaderBag` 是空子类**，Drupal 与 Symfony 各有两条
   no-cache 断言的判别力**依赖这一点**。`stubDoesNotComputeCacheControlDefaults` 是**设计好的绊线**：
   谁给桩补忠实度，它先红——届时必须把那两条改成扰动式判别，**而不是删掉绊线或把断言改弱**。
