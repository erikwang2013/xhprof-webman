# Multi-Framework Refactor Design

From webman-only to webman / Laravel / ThinkPHP / Hyperf, single Composer package, adapter pattern.

## Decisions

| Decision | Choice |
|----------|--------|
| Package | Single Composer package |
| Abstraction | Adapter pattern (custom interfaces) |
| Init method | Framework-standard auto-registration + manual init |
| Backward compat | webman users upgrade with zero changes |

## Directory Structure

```
src/
  Core/                            # Framework-agnostic core
    Contract/
      RequestInterface.php
      ResponseInterface.php
      ConfigInterface.php
      LoggerInterface.php
      CacheInterface.php
    Xhprof.php                     # Main entry point (holds static properties)
    XhprofProfiler.php             # Core profiling logic (start/stop/init/bootstrap)
    XhprofMiddleware.php           # Abstract middleware hooks
    StaticController.php           # Static asset serving
    XhprofLib/
      Display/XhprofDisplay.php
      Utils/XhprofLib.php
      Utils/CallGraph.php
      Utils/XHProfRuns.php         # Interface
      Utils/XHProfRunsDefault.php
  Webman/                          # Webman adapters
    Adapter/
      RequestAdapter.php
      ResponseAdapter.php
      ConfigAdapter.php
      RedisAdapter.php
      LogAdapter.php
    XhprofMiddleware.php           # Compat shim: extends Core\XhprofMiddleware
    Xhprof.php                     # Compat shim: extends Core\Xhprof
    Install.php                    # Unchanged
    config/
      plugin/aaron-dev/xhprof/
        app.php
        xhprof.php
  Laravel/
    Adapter/...
    Middleware.php
    XhprofServiceProvider.php
    config/xhprof.php
  Thinkphp/
    Adapter/...
    Middleware.php
    config/
      xhprof.php
      middleware.php
  Hyperf/
    Adapter/...
    Middleware.php
    ConfigProvider.php
    config/
      xhprof.php
      middleware.php
  html/                            # Static assets moved from Webman/src/html
    css/
    js/
    jquery/
    images/
```

## Core Interfaces

### RequestInterface

```php
interface RequestInterface {
    public function get(string $key, mixed $default = null): mixed;
    public function all(): array;
    public function method(): string;
    public function header(string $name): ?string;
    public function host(): string;
    public function uri(): string;
    public function url(): string;
    public function getRealIp(): string;
}
```

### ResponseInterface

```php
interface ResponseInterface {
    public function withBody(string $body): self;
    public function withHeaders(array $headers): self;
    public function file(string $path): self;
    public function send(): mixed;
}
```

### CacheInterface

```php
interface CacheInterface {
    public function get(string $key): mixed;
    public function set(string $key, mixed $value): bool;
    public function incr(string $key): int;
    public function lPush(string $key, mixed $value): int;
    public function rpop(string $key): mixed;
    public function lrange(string $key, int $start, int $end): array;
    public function del(string ...$keys): int;
    public function decr(string $key): int;
}
```

### ConfigInterface

```php
interface ConfigInterface {
    public function get(string $key, mixed $default = null): mixed;
}
```

### LoggerInterface

```php
interface LoggerInterface {
    public function error(string $message, array $context = []): void;
}
```

## Core Classes

### XhprofProfiler (new)

Extracts profiling logic from Xhprof/XhprofMiddleware into one place:

- `init()` — check extensions, set timezone
- `start()` — call `xhprof_enable()`
- `stop()` — call `xhprof_disable()`, delegate to XHProfRunsDefault::save_run
- `isEnabled()` — read `enable` from ConfigInterface
- `shouldIgnore()` — check current request URI against `ignore_url_arr`
- `bootstrap()` — sync ConfigInterface values to Xhprof static properties

### Xhprof (refactored)

- Keeps all static properties (`$time_limit`, `$ignore_url_arr`, `$key_prefix`, `$log_num`, `$view_wtred`, `$ui_html`, `$symbol_lookup_url`)
- Holds all 5 adapters as static properties: `$request`, `$response`, `$config`, `$cache`, `$logger`
- `bootstrap()` — auto-detect framework and create all adapters; also exposed as manual override: `Xhprof::bootstrap($request, $response, $config, $cache, $logger)`
- `index()` — renders the main page, replaces `$_SERVER` with adapter calls
- `xhprofStart()` / `xhprofStop()` — delegate to XhprofProfiler
- `getRequest()` / `getResponse()` — return injected adapters (backward compat)

### XhprofMiddleware (abstract base)

Core method: `processProfiling(callable $handler, RequestInterface $request): ResponseInterface`

The framework-specific middleware classes:
- Create adapter wrappers for native request/response
- Call `Xhprof::getInstance()->bootstrap()` and inject adapters
- Delegate to `processProfiling()`

### StaticController

Moves from Webman to Core. `getPathFromRequest` receives `RequestInterface` instead of `Webman\Http\Request`. The `serve()` method returns generic response, adapters handle the output.

### XhprofLib changes

- `support\Redis` → `CacheInterface` (injected via Xhprof static methods)
- `support\Log` → `LoggerInterface`
- `config()` calls → `ConfigInterface`
- `$_SERVER['SCRIPT_NAME']` / `$_SERVER['REQUEST_URI']` → RequestInterface methods

## Adapter Mapping

| Interface | Webman | Laravel | ThinkPHP | Hyperf |
|-----------|--------|---------|----------|--------|
| Request | `Webman\Http\Request` | `Illuminate\Http\Request` | `think\Request` | `Hyperf\HttpServer\Request` |
| Response | `Webman\Http\Response` | `Illuminate\Http\Response` | `think\Response` | `Hyperf\HttpServer\Response` |
| Config | `config()` helper | `config()` helper | `think\facade\Config` | `Hyperf\Contract\ConfigInterface` |
| Cache | `support\Redis` | `Illuminate\Support\Facades\Redis` | `think\facade\Cache` (Redis) | `Hyperf\Redis\Redis` |
| Logger | `support\Log` | `Illuminate\Support\Facades\Log` | `think\facade\Log` | PSR-3 via `LoggerFactory` |

## Framework Registration

### Webman

Backward compatible — `ErikWang2013\Xhprof\Webman\XhprofMiddleware` and `ErikWang2013\Xhprof\Webman\Xhprof` remain as compat shims extending Core classes. Install.php unchanged.

### Laravel

`XhprofServiceProvider`:
- Registers middleware alias in Kernel
- Publishes config via `vendor:publish`
- Supports Laravel auto-discovery (composer.json extra)

### ThinkPHP

Manual: copy middleware config, register in `app/middleware.php`.

### Hyperf

`ConfigProvider` declares middlewares and config file. Composer plugin auto-discovers.

## Backward Compatibility

`ErikWang2013\Xhprof\Webman\XhprofMiddleware` and `ErikWang2013\Xhprof\Webman\Xhprof` remain as classes extending their Core counterparts. Existing webman users' composer.json, middleware registration, route config, and class references continue to work unchanged.

## Dependencies

- `php: >=8.0`
- `ext-xhprof: *`
- `ext-redis: *`
- No framework-specific packages in `require` (each framework is suggest-only)
- Existing `workerman/webman` and `webman/redis` move from `require` to `suggest`

## Static Assets

Move `src/Webman/src/html/` to `src/html/` (or keep at `src/html/`). `StaticController::getPackageRoot()` adjusts accordingly. All four frameworks serve static files through the same Core StaticController.
