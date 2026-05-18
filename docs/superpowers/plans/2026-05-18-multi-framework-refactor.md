# Multi-Framework Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor xhprof-webman from webman-only to support webman / Laravel / ThinkPHP / Hyperf four frameworks via adapter pattern, with zero breaking changes for existing webman users.

**Architecture:** Adapter pattern — 5 framework-agnostic interfaces (Request, Response, Config, Cache, Logger) in `Core/Contract/`, core profiling logic in `Core/`, one adapter directory per framework. Webman backward compat via class shims.

**Tech Stack:** PHP >=8.0, ext-xhprof, ext-redis. No framework packages in require (moved to suggest).

---

### Task 1: Create contract interfaces

**Files:**
- Create: `src/Core/Contract/RequestInterface.php`
- Create: `src/Core/Contract/ResponseInterface.php`
- Create: `src/Core/Contract/ConfigInterface.php`
- Create: `src/Core/Contract/CacheInterface.php`
- Create: `src/Core/Contract/LoggerInterface.php`

- [ ] **Step 1: Create RequestInterface**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Contract;

interface RequestInterface
{
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

- [ ] **Step 2: Create ResponseInterface**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Contract;

interface ResponseInterface
{
    public function withBody(string $body): self;
    public function withHeaders(array $headers): self;
    public function file(string $path): self;
    public function send(): mixed;
}
```

- [ ] **Step 3: Create ConfigInterface**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Contract;

interface ConfigInterface
{
    public function get(string $key, mixed $default = null): mixed;
}
```

- [ ] **Step 4: Create CacheInterface**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Contract;

interface CacheInterface
{
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

- [ ] **Step 5: Create LoggerInterface**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Contract;

interface LoggerInterface
{
    public function error(string $message, array $context = []): void;
}
```

- [ ] **Step 6: Commit**

```bash
git add src/Core/Contract/
git commit -m "feat: add core contract interfaces for multi-framework adapter pattern"
```

---

### Task 2: Move static assets to src/html

**Files:**
- Create: `src/html/css/xhprof.css` (from `src/html/css/xhprof.css` — already there)
- Move: `src/Webman/src/html/` → empty (assets already at `src/html/`)

Note: The current `src/html/` already exists at the project root. Verify assets are at `src/html/` and not duplicated inside `src/Webman/src/html/`.

- [ ] **Step 1: Check current asset locations**

```bash
ls src/html/ 2>/dev/null && echo "--- root src/html exists ---"
ls src/Webman/src/html/ 2>/dev/null && echo "--- Webman src/html exists ---"
```

- [ ] **Step 2: If assets are under src/Webman/src/html, move them to src/html**

```bash
if [ -d src/Webman/src/html ]; then
  mkdir -p src/html
  cp -r src/Webman/src/html/* src/html/
  rm -rf src/Webman/src/html
fi
```

- [ ] **Step 3: Commit**

```bash
git add src/html/ && git rm -r src/Webman/src/html 2>/dev/null; git commit -m "refactor: move static assets to src/html for shared use"
```

---

### Task 3: Move and refactor StaticController to Core

**Files:**
- Create: `src/Core/StaticController.php` (from `src/Webman/StaticController.php`)
- Modify: `src/Webman/StaticController.php` → compat shim

- [ ] **Step 1: Read current StaticController**

Source is at `src/Webman/StaticController.php`. The class uses `Webman\Http\Request` and `Webman\Http\Response`.

- [ ] **Step 2: Write Core StaticController**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class StaticController
{
    private const ASSETS_DIR = 'src/html';
    private const URI_PREFIX = '/xhprof-assets';

    public static function getPackageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function getAssetsPath(): string
    {
        return self::getPackageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::ASSETS_DIR);
    }

    public static function serve(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $path = self::getPathFromRequest($request);
        if ($path === null) {
            return $response->withBody('')->withHeaders([]);
        }

        $base = self::getAssetsPath();
        if (!is_dir($base)) {
            return $response->withBody('')->withHeaders([]);
        }

        $file = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $realBase = realpath($base);
        $realFile = $file !== '' && is_file($file) ? realpath($file) : false;

        if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            return $response->withBody('')->withHeaders([]);
        }

        return $response->file($realFile)->withHeaders([
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private static function getPathFromRequest(RequestInterface $request): ?string
    {
        $uri = $request->uri();
        if (!is_string($uri)) {
            return null;
        }
        $pathOnly = parse_url($uri, PHP_URL_PATH);
        if ($pathOnly === null || $pathOnly === '') {
            return null;
        }
        $prefix = self::URI_PREFIX . '/';
        if (!str_starts_with($pathOnly, $prefix)) {
            return null;
        }
        $path = substr($pathOnly, strlen($prefix));
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        return $path;
    }
}
```

- [ ] **Step 3: Replace Webman StaticController with compat shim**

The old `src/Webman/StaticController.php` becomes a thin adapter that wraps the Core version:

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman;

use Webman\Http\Request;
use Webman\Http\Response;
use ErikWang2013\Xhprof\Core\StaticController as CoreStaticController;
use ErikWang2013\Xhprof\Webman\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\ResponseAdapter;

class StaticController
{
    public static function serve(Request $request): Response
    {
        $req = new RequestAdapter($request);
        $res = new ResponseAdapter(new Response(200));
        $result = CoreStaticController::serve($req, $res);
        return $result->send();
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add src/Core/StaticController.php src/Webman/StaticController.php
git commit -m "refactor: move StaticController to Core, keep Webman compat shim"
```

---

### Task 4: Move XhprofLib to Core with adapter references

**Files:**
- Move: `src/Webman/XhprofLib/` → `src/Core/XhprofLib/`
- Modify: All files in `src/Core/XhprofLib/` — replace `support\Redis`, `support\Log`, `config()`, `$_SERVER` references

- [ ] **Step 1: Copy XhprofLib to Core, preserving originals**

```bash
cp -r src/Webman/XhprofLib src/Core/XhprofLib
```

- [ ] **Step 2: Update namespace in XHProfRuns.php**

Change namespace from `ErikWang2013\Xhprof\Webman\XhprofLib\Utils` to `ErikWang2013\Xhprof\Core\XhprofLib\Utils`.

- [ ] **Step 3: Update XHProfRunsDefault.php — namespaces and Redis/Log references**

Change namespace to `ErikWang2013\Xhprof\Core\XhprofLib\Utils`.

Replace `use support\Redis;` and `use support\Log;` with `use ErikWang2013\Xhprof\Core\Xhprof;`.

Replace `Redis::get(...)` → `Xhprof::$cache->get(...)`
Replace `Redis::set(...)` → `Xhprof::$cache->set(...)`
Replace `Redis::incr(...)` → `Xhprof::$cache->incr(...)`
Replace `Redis::lPush(...)` → `Xhprof::$cache->lPush(...)`
Replace `Redis::rpop(...)` → `Xhprof::$cache->rpop(...)`
Replace `Redis::lrange(...)` → `Xhprof::$cache->lrange(...)`
Replace `Redis::del(...)` → `Xhprof::$cache->del(...)`
Replace `Redis::decr(...)` → `Xhprof::$cache->decr(...)`

Replace `$_SERVER['SCRIPT_NAME']` with `Xhprof::$request->url()`.

All `use` statements change from `ErikWang2013\Xhprof\Webman\...` to `ErikWang2013\Xhprof\Core\...`.

- [ ] **Step 4: Update XhprofLib.php — namespaces and Redis/Log/config references**

Change namespace to `ErikWang2013\Xhprof\Core\XhprofLib\Utils`.

Replace `use support\Redis;` and `use support\Log;` → remove, replace with references to `Xhprof::$cache` and `Xhprof::$logger`.

`Log::error(...)` → `Xhprof::$logger->error(...)`

All `ErikWang2013\Xhprof\Webman\...` → `ErikWang2013\Xhprof\Core\...`.

- [ ] **Step 5: Update XhprofDisplay.php — namespaces, $_SERVER references**

Change namespace to `ErikWang2013\Xhprof\Core\XhprofLib\Display`.

Replace `$_SERVER['SCRIPT_NAME']` → `Xhprof::$request->url()`.
Replace `$_SERVER['REQUEST_URI']` → `Xhprof::$request->uri()`.

All `ErikWang2013\Xhprof\Webman\...` → `ErikWang2013\Xhprof\Core\...`.

- [ ] **Step 6: Update CallGraph.php — namespaces and Log references**

Change namespace to `ErikWang2013\Xhprof\Core\XhprofLib\Utils`.

Replace `use support\Log;` → remove.
Replace `Log::error(...)` → `Xhprof::$logger->error(...)`.

All `ErikWang2013\Xhprof\Webman\...` → `ErikWang2013\Xhprof\Core\...`.

- [ ] **Step 7: Verify no stale webman references remain in Core**

```bash
grep -rn "support\\\\" src/Core/XhprofLib/ && echo "ERROR: stale webman refs" || echo "OK"
grep -rn "Webman\\\\" src/Core/XhprofLib/ && echo "ERROR: stale namespace refs" || echo "OK"
```

- [ ] **Step 8: Commit**

```bash
git add src/Core/XhprofLib/
git commit -m "refactor: move XhprofLib to Core, replace webman helpers with adapter references"
```

---

### Task 5: Create Webman adapters

**Files:**
- Create: `src/Webman/Adapter/RequestAdapter.php`
- Create: `src/Webman/Adapter/ResponseAdapter.php`
- Create: `src/Webman/Adapter/ConfigAdapter.php`
- Create: `src/Webman/Adapter/RedisAdapter.php`
- Create: `src/Webman/Adapter/LogAdapter.php`

- [ ] **Step 1: Create RequestAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman\Adapter;

use Webman\Http\Request;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

class RequestAdapter implements RequestInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->request->get($key, $default);
    }

    public function all(): array
    {
        return $this->request->all();
    }

    public function method(): string
    {
        return $this->request->method();
    }

    public function header(string $name): ?string
    {
        return $this->request->header($name);
    }

    public function host(): string
    {
        return $this->request->host();
    }

    public function uri(): string
    {
        return $this->request->uri();
    }

    public function url(): string
    {
        return $this->request->url();
    }

    public function getRealIp(): string
    {
        return $this->request->getRealIp(true);
    }
}
```

- [ ] **Step 2: Create ResponseAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman\Adapter;

use Webman\Http\Response;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class ResponseAdapter implements ResponseInterface
{
    private Response $response;

    public function __construct(Response $response)
    {
        $this->response = $response;
    }

    public function withBody(string $body): self
    {
        $this->response = $this->response->withBody($body);
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        $this->response = $this->response->withHeaders($headers);
        return $this;
    }

    public function file(string $path): self
    {
        $this->response = response()->file($path);
        return $this;
    }

    public function send(): mixed
    {
        return $this->response;
    }
}
```

- [ ] **Step 3: Create ConfigAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

class ConfigAdapter implements ConfigInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        // Translate uniform key to webman-specific plugin config path
        $webmanKey = 'plugin.aaron-dev.xhprof.' . $key;
        return config($webmanKey, $default);
    }
}
```

- [ ] **Step 4: Create RedisAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman\Adapter;

use support\Redis;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;

class RedisAdapter implements CacheInterface
{
    public function get(string $key): mixed
    {
        return Redis::get($key);
    }

    public function set(string $key, mixed $value): bool
    {
        return Redis::set($key, $value);
    }

    public function incr(string $key): int
    {
        return Redis::incr($key);
    }

    public function lPush(string $key, mixed $value): int
    {
        return Redis::lPush($key, $value);
    }

    public function rpop(string $key): mixed
    {
        return Redis::rpop($key);
    }

    public function lrange(string $key, int $start, int $end): array
    {
        return Redis::lrange($key, $start, $end);
    }

    public function del(string ...$keys): int
    {
        return Redis::del(...$keys);
    }

    public function decr(string $key): int
    {
        return Redis::decr($key);
    }
}
```

- [ ] **Step 5: Create LogAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman\Adapter;

use support\Log;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

class LogAdapter implements LoggerInterface
{
    public function error(string $message, array $context = []): void
    {
        Log::error($message, $context);
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add src/Webman/Adapter/
git commit -m "feat: add Webman adapters for Request, Response, Config, Cache, Logger"
```

---

### Task 6: Create XhprofProfiler (Core)

**Files:**
- Create: `src/Core/XhprofProfiler.php`

- [ ] **Step 1: Write XhprofProfiler**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;

class XhprofProfiler
{
    public static function init(): void
    {
        date_default_timezone_set('PRC');
        if (!extension_loaded('xhprof')) {
            Xhprof::$response->withBody('请安装xhprof扩展');
            return;
        }
        if (!extension_loaded('redis')) {
            Xhprof::$response->withBody('请安装redis扩展');
            return;
        }
    }

    public static function start(): void
    {
        xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
    }

    public static function stop(): void
    {
        $xhprof_data = xhprof_disable();
        XHProfRunsDefault::save_run($xhprof_data, "xhprof_foo");
    }

    public static function bootstrap(): void
    {
        $config = Xhprof::$config;
        if ($config === null) {
            return;
        }
        $pluginConfig = $config->get('xhprof', []);
        Xhprof::$ignore_url_arr = $pluginConfig['ignore_url_arr'] ?? ['/test'];
        Xhprof::$time_limit = (int) ($pluginConfig['time_limit'] ?? 0);
        Xhprof::$log_num = (int) ($pluginConfig['log_num'] ?? 1000);
        Xhprof::$view_wtred = (int) ($pluginConfig['view_wtred'] ?? 3);
    }

    public static function isEnabled(): bool
    {
        if (Xhprof::$config === null) {
            return false;
        }
        $config = Xhprof::$config->get('xhprof', []);
        return (bool) ($config['enable'] ?? false);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Core/XhprofProfiler.php
git commit -m "feat: add XhprofProfiler - framework-agnostic profiling lifecycle"
```

---

### Task 7: Refactor Core Xhprof (main entry point)

**Files:**
- Create: `src/Core/Xhprof.php` (from `src/Webman/Xhprof.php` logic)
- Modify: `src/Webman/Xhprof.php` → compat shim

- [ ] **Step 1: Write Core Xhprof with adapter injection and bootstrap auto-detect**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;

class Xhprof
{
    public static $time_limit = 0;
    public static $ignore_url_arr = ["/test"];
    public static $key_prefix = 'xhprof';
    public static $log_num = 1000;
    public static $view_wtred = 3;
    public static $ui_html = '';
    public static $symbol_lookup_url = "";

    public static ?RequestInterface $request = null;
    public static ?ResponseInterface $response = null;
    public static ?ConfigInterface $config = null;
    public static ?CacheInterface $cache = null;
    public static ?LoggerInterface $logger = null;

    public static function getRequest(): RequestInterface
    {
        return self::$request;
    }

    public static function getResponse(): ResponseInterface
    {
        return self::$response;
    }

    public static function index(): string
    {
        $run = self::$request->get('run');
        $wts = self::$request->get('wts');
        $symbol = self::$request->get('symbol');
        $sort = self::$request->get('sort');
        $run1 = self::$request->get('run1');
        $run2 = self::$request->get('run2');
        $source = self::$request->get('source');
        $params = self::$request->all();
        $echo_page = "<html lang=\"zh-CN\">";
        $assetsUrl = '';
        if (self::$config !== null) {
            $assetsUrl = self::$config->get('xhprof.assets_url', '');
        }
        if ($assetsUrl === '') {
            $assetsUrl = self::$ui_html ?: '/xhprof-assets';
        }
        $echo_page .= "<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>XHProf 性能分析报告</title>";
        $echo_page .= XhprofDisplay::xhprof_include_js_css($assetsUrl);
        $echo_page .= "</head>";
        $echo_page .= "<body>";
        $echo_page .= XhprofDisplay::displayXHProfReport(
            $params,
            $source,
            $run,
            $wts,
            $symbol,
            $sort,
            $run1,
            $run2
        );
        $echo_page .= "</body>";
        $echo_page .= "</html>";
        return $echo_page;
    }

    public static function xhprofStart(): void
    {
        XhprofProfiler::init();
        XhprofProfiler::start();
    }

    public static function xhprofStop(): void
    {
        XhprofProfiler::stop();
    }

    public static function bootstrap(
        ?RequestInterface $request = null,
        ?ResponseInterface $response = null,
        ?ConfigInterface $config = null,
        ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null
    ): void {
        if ($request !== null) {
            self::$request = $request;
            self::$response = $response;
            self::$config = $config;
            self::$cache = $cache;
            self::$logger = $logger;
        } else {
            self::autoDetect();
        }
        XhprofProfiler::bootstrap();
    }

    private static function autoDetect(): void
    {
        if (class_exists(\Webman\App::class)) {
            self::$request = new \ErikWang2013\Xhprof\Webman\Adapter\RequestAdapter(request());
            self::$response = new \ErikWang2013\Xhprof\Webman\Adapter\ResponseAdapter(response());
            self::$config = new \ErikWang2013\Xhprof\Webman\Adapter\ConfigAdapter();
            self::$cache = new \ErikWang2013\Xhprof\Webman\Adapter\RedisAdapter();
            self::$logger = new \ErikWang2013\Xhprof\Webman\Adapter\LogAdapter();
        } elseif (class_exists(\Illuminate\Foundation\Application::class)) {
            self::$request = new \ErikWang2013\Xhprof\Laravel\Adapter\RequestAdapter(app('request'));
            self::$response = new \ErikWang2013\Xhprof\Laravel\Adapter\ResponseAdapter(response());
            self::$config = new \ErikWang2013\Xhprof\Laravel\Adapter\ConfigAdapter();
            self::$cache = new \ErikWang2013\Xhprof\Laravel\Adapter\RedisAdapter();
            self::$logger = new \ErikWang2013\Xhprof\Laravel\Adapter\LogAdapter();
        } elseif (class_exists(\think\App::class)) {
            self::$request = new \ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter(app('request'));
            self::$response = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter(response());
            self::$config = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ConfigAdapter();
            self::$cache = new \ErikWang2013\Xhprof\Thinkphp\Adapter\RedisAdapter();
            self::$logger = new \ErikWang2013\Xhprof\Thinkphp\Adapter\LogAdapter();
        } elseif (class_exists(\Hyperf\Framework\ApplicationContext::class)) {
            $container = \Hyperf\Context\ApplicationContext::getContainer();
            self::$request = new \ErikWang2013\Xhprof\Hyperf\Adapter\RequestAdapter($container->get(\Hyperf\HttpServer\Request::class));
            self::$response = new \ErikWang2013\Xhprof\Hyperf\Adapter\ResponseAdapter($container->get(\Hyperf\HttpServer\Response::class));
            self::$config = new \ErikWang2013\Xhprof\Hyperf\Adapter\ConfigAdapter($container->get(\Hyperf\Contract\ConfigInterface::class));
            self::$cache = new \ErikWang2013\Xhprof\Hyperf\Adapter\RedisAdapter($container->get(\Hyperf\Redis\Redis::class));
            self::$logger = new \ErikWang2013\Xhprof\Hyperf\Adapter\LogAdapter($container->get(\Psr\Log\LoggerInterface::class));
        } else {
            throw new \RuntimeException('ErikWang2013\Xhprof: Unsupported framework. Use Xhprof::bootstrap() to inject adapters manually.');
        }
    }
}
```

- [ ] **Step 2: Replace Webman Xhprof with compat shim**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman;

class Xhprof extends \ErikWang2013\Xhprof\Core\Xhprof
{
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Core/Xhprof.php src/Webman/Xhprof.php
git commit -m "refactor: Core Xhprof with adapter pattern, Webman compat shim"
```

---

### Task 8: Create Core XhprofMiddleware (abstract base)

**Files:**
- Create: `src/Core/XhprofMiddleware.php`
- Modify: `src/Webman/XhprofMiddleware.php` → compat shim

- [ ] **Step 1: Write Core XhprofMiddleware**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class XhprofMiddleware
{
    public static function processProfiling(
        RequestInterface $request,
        callable $handler,
        callable $responseFactory
    ): mixed {
        Xhprof::bootstrap($request, null, null, null, null);

        if (XhprofProfiler::isEnabled()) {
            Xhprof::xhprofStart();
        }

        $response = $handler($request);

        if (XhprofProfiler::isEnabled()) {
            Xhprof::xhprofStop();
        }

        return $response;
    }

    protected static function wrapRequest($nativeRequest): RequestInterface
    {
        // Override in framework-specific adapters
        throw new \BadMethodCallException('Must be overridden by framework adapter');
    }
}
```

- [ ] **Step 2: Replace Webman XhprofMiddleware with compat shim**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use ErikWang2013\Xhprof\Core\XhprofMiddleware as CoreMiddleware;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Webman\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Webman\Adapter\LogAdapter;

class XhprofMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $req = new RequestAdapter($request);
        $res = new ResponseAdapter(response(''));

        Xhprof::bootstrap($req, $res, new ConfigAdapter(), new RedisAdapter(), new LogAdapter());

        $xhprof = XhprofProfiler::isEnabled();
        $extension = extension_loaded('xhprof');
        $redis = extension_loaded('redis');

        if (!$extension) {
            return response()->withBody('请安装xhprof扩展');
        }
        if (!$redis) {
            return response()->withBody('请安装redis扩展');
        }

        if ($xhprof && $extension) {
            Xhprof::xhprofStart();
        }

        $response = $handler($request);

        if ($xhprof && $extension) {
            Xhprof::xhprofStop();
        }

        return $response;
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Core/XhprofMiddleware.php src/Webman/XhprofMiddleware.php
git commit -m "refactor: Core XhprofMiddleware abstract base, Webman compat shim"
```

---

### Task 9: Update composer.json

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Update composer.json**

```json
{
    "name": "aaron-dev/xhprof-webman",
    "description": "aaron-dev/xhprof-webman is a code performance analysis plugin compatible with webman, Laravel, ThinkPHP and Hyperf. Uses xhprof extension for profiling, Redis for storage, provides browser-based performance analysis reports.",
    "keywords": [
        "erikwang2013",
        "xhprof",
        "webman",
        "laravel",
        "thinkphp",
        "hyperf",
        "profiling",
        "performance"
    ],
    "type": "library",
    "license": "MIT",
    "minimum-stability": "dev",
    "authors": [
        {
            "name": "erik",
            "email": "erik@erik.xyz",
            "homepage": "https://erik.xyz",
            "role": "Developer"
        }
    ],
    "support": {
        "email": "erik@erik.xyz",
        "issues": "https://github.com/erikwang2013/xhprof-webman/issues",
        "source": "https://github.com/erikwang2013/xhprof-webman"
    },
    "require": {
        "php": ">=8.0",
        "ext-xhprof": "*",
        "ext-redis": "*"
    },
    "suggest": {
        "workerman/webman": "^2.1",
        "webman/redis": "^2.1",
        "laravel/framework": "^9.0|^10.0|^11.0",
        "topthink/framework": "^6.0|^8.0",
        "hyperf/framework": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "ErikWang2013\\Xhprof\\": "src/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "ErikWang2013\\Xhprof\\Laravel\\XhprofServiceProvider"
            ]
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add composer.json
git commit -m "refactor: update composer.json for multi-framework support, move framework deps to suggest"
```

---

### Task 10: Create Laravel adapters and ServiceProvider

**Files:**
- Create: `src/Laravel/Adapter/RequestAdapter.php`
- Create: `src/Laravel/Adapter/ResponseAdapter.php`
- Create: `src/Laravel/Adapter/ConfigAdapter.php`
- Create: `src/Laravel/Adapter/RedisAdapter.php`
- Create: `src/Laravel/Adapter/LogAdapter.php`
- Create: `src/Laravel/Middleware.php`
- Create: `src/Laravel/XhprofServiceProvider.php`
- Create: `src/Laravel/config/xhprof.php`

- [ ] **Step 1: Create Laravel RequestAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel\Adapter;

use Illuminate\Http\Request;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

class RequestAdapter implements RequestInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->request->get($key, $default);
    }

    public function all(): array
    {
        return $this->request->all();
    }

    public function method(): string
    {
        return $this->request->method();
    }

    public function header(string $name): ?string
    {
        return $this->request->header($name);
    }

    public function host(): string
    {
        return $this->request->getHost();
    }

    public function uri(): string
    {
        return $this->request->getRequestUri();
    }

    public function url(): string
    {
        return $this->request->url();
    }

    public function getRealIp(): string
    {
        return $this->request->ip();
    }
}
```

- [ ] **Step 2: Create Laravel ResponseAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class ResponseAdapter implements ResponseInterface
{
    private $response;

    public function __construct($response = null)
    {
        $this->response = $response ?? response('');
    }

    public function withBody(string $body): self
    {
        $this->response = response($body);
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        $this->response = $this->response->withHeaders($headers);
        return $this;
    }

    public function file(string $path): self
    {
        $this->response = response()->file($path);
        return $this;
    }

    public function send(): mixed
    {
        return $this->response;
    }
}
```

- [ ] **Step 3: Create Laravel ConfigAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

class ConfigAdapter implements ConfigInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return config($key, $default);
    }
}
```

- [ ] **Step 4: Create Laravel RedisAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel\Adapter;

use Illuminate\Support\Facades\Redis;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;

class RedisAdapter implements CacheInterface
{
    public function get(string $key): mixed
    {
        return Redis::get($key);
    }

    public function set(string $key, mixed $value): bool
    {
        return Redis::set($key, $value);
    }

    public function incr(string $key): int
    {
        return Redis::incr($key);
    }

    public function lPush(string $key, mixed $value): int
    {
        return Redis::lpush($key, $value);
    }

    public function rpop(string $key): mixed
    {
        return Redis::rpop($key);
    }

    public function lrange(string $key, int $start, int $end): array
    {
        return Redis::lrange($key, $start, $end);
    }

    public function del(string ...$keys): int
    {
        return Redis::del(...$keys);
    }

    public function decr(string $key): int
    {
        return Redis::decr($key);
    }
}
```

- [ ] **Step 5: Create Laravel LogAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel\Adapter;

use Illuminate\Support\Facades\Log;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

class LogAdapter implements LoggerInterface
{
    public function error(string $message, array $context = []): void
    {
        Log::error($message, $context);
    }
}
```

- [ ] **Step 6: Create Laravel Middleware**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel;

use Closure;
use Illuminate\Http\Request;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Laravel\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Laravel\Adapter\LogAdapter;

class Middleware
{
    public function handle(Request $request, Closure $next)
    {
        $req = new RequestAdapter($request);
        $res = new ResponseAdapter(response(''));

        Xhprof::bootstrap($req, $res, new ConfigAdapter(), new RedisAdapter(), new LogAdapter());

        $response = $next($request);

        if (XhprofProfiler::isEnabled() && extension_loaded('xhprof')) {
            // profiling already started in bootstrap via auto-detect, stop here
            Xhprof::xhprofStop();
        }

        return $response;
    }
}
```

- [ ] **Step 7: Create Laravel XhprofServiceProvider**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel;

use Illuminate\Support\ServiceProvider;

class XhprofServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/xhprof.php', 'xhprof'
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/xhprof.php' => config_path('xhprof.php'),
        ], 'xhprof-config');
    }
}
```

- [ ] **Step 8: Create Laravel config**

```php
<?php

return [
    'enable' => true,
    'time_limit' => 0,
    'log_num' => 1000,
    'view_wtred' => 3,
    'ignore_url_arr' => ['/xhprof'],
    'assets_url' => '/xhprof-assets',
];
```

- [ ] **Step 9: Commit**

```bash
git add src/Laravel/
git commit -m "feat: add Laravel adapters, middleware, and ServiceProvider"
```

---

### Task 11: Create ThinkPHP adapters and middleware

**Files:**
- Create: `src/Thinkphp/Adapter/RequestAdapter.php`
- Create: `src/Thinkphp/Adapter/ResponseAdapter.php`
- Create: `src/Thinkphp/Adapter/ConfigAdapter.php`
- Create: `src/Thinkphp/Adapter/RedisAdapter.php`
- Create: `src/Thinkphp/Adapter/LogAdapter.php`
- Create: `src/Thinkphp/Middleware.php`
- Create: `src/Thinkphp/config/xhprof.php`

- [ ] **Step 1: Create ThinkPHP RequestAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\Request;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

class RequestAdapter implements RequestInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->request->param($key, $default);
    }

    public function all(): array
    {
        return $this->request->param();
    }

    public function method(): string
    {
        return $this->request->method();
    }

    public function header(string $name): ?string
    {
        return $this->request->header($name);
    }

    public function host(): string
    {
        return $this->request->host();
    }

    public function uri(): string
    {
        return $this->request->url();
    }

    public function url(): string
    {
        return $this->request->url(true);
    }

    public function getRealIp(): string
    {
        return $this->request->ip();
    }
}
```

- [ ] **Step 2: Create ThinkPHP ResponseAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\Response;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class ResponseAdapter implements ResponseInterface
{
    private Response $response;

    public function __construct(?Response $response = null)
    {
        $this->response = $response ?? response('');
    }

    public function withBody(string $body): self
    {
        $this->response = response($body);
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        $this->response = $this->response->header($headers);
        return $this;
    }

    public function file(string $path): self
    {
        $this->response = download($path, '');
        return $this;
    }

    public function send(): mixed
    {
        return $this->response;
    }
}
```

- [ ] **Step 3: Create ThinkPHP ConfigAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\facade\Config;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

class ConfigAdapter implements ConfigInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}
```

- [ ] **Step 4: Create ThinkPHP RedisAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\facade\Cache;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;

class RedisAdapter implements CacheInterface
{
    public function get(string $key): mixed
    {
        return Cache::store('redis')->get($key);
    }

    public function set(string $key, mixed $value): bool
    {
        return Cache::store('redis')->set($key, $value);
    }

    public function incr(string $key): int
    {
        return Cache::store('redis')->inc($key);
    }

    public function lPush(string $key, mixed $value): int
    {
        $redis = Cache::store('redis')->handler();
        return $redis->lPush($key, $value);
    }

    public function rpop(string $key): mixed
    {
        $redis = Cache::store('redis')->handler();
        return $redis->rPop($key);
    }

    public function lrange(string $key, int $start, int $end): array
    {
        $redis = Cache::store('redis')->handler();
        return $redis->lRange($key, $start, $end);
    }

    public function del(string ...$keys): int
    {
        $redis = Cache::store('redis')->handler();
        return $redis->del(...$keys);
    }

    public function decr(string $key): int
    {
        $redis = Cache::store('redis')->handler();
        return $redis->decr($key);
    }
}
```

- [ ] **Step 5: Create ThinkPHP LogAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\facade\Log;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

class LogAdapter implements LoggerInterface
{
    public function error(string $message, array $context = []): void
    {
        Log::error($message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE));
    }
}
```

- [ ] **Step 6: Create ThinkPHP Middleware**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp;

use think\Request;
use Closure;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\LogAdapter;

class Middleware
{
    public function handle(Request $request, Closure $next)
    {
        $req = new RequestAdapter($request);
        $res = new ResponseAdapter(response(''));

        Xhprof::bootstrap($req, $res, new ConfigAdapter(), new RedisAdapter(), new LogAdapter());

        $response = $next($request);

        if (XhprofProfiler::isEnabled() && extension_loaded('xhprof')) {
            Xhprof::xhprofStop();
        }

        return $response;
    }
}
```

- [ ] **Step 7: Create ThinkPHP config**

```php
<?php

return [
    'enable' => true,
    'time_limit' => 0,
    'log_num' => 1000,
    'view_wtred' => 3,
    'ignore_url_arr' => ['/xhprof'],
    'assets_url' => '/xhprof-assets',
];
```

- [ ] **Step 8: Commit**

```bash
git add src/Thinkphp/
git commit -m "feat: add ThinkPHP adapters, middleware, and config"
```

---

### Task 12: Create Hyperf adapters and ConfigProvider

**Files:**
- Create: `src/Hyperf/Adapter/RequestAdapter.php`
- Create: `src/Hyperf/Adapter/ResponseAdapter.php`
- Create: `src/Hyperf/Adapter/ConfigAdapter.php`
- Create: `src/Hyperf/Adapter/RedisAdapter.php`
- Create: `src/Hyperf/Adapter/LogAdapter.php`
- Create: `src/Hyperf/Middleware.php`
- Create: `src/Hyperf/ConfigProvider.php`
- Create: `src/Hyperf/config/xhprof.php`

- [ ] **Step 1: Create Hyperf RequestAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf\Adapter;

use Hyperf\HttpServer\Request;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

class RequestAdapter implements RequestInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->request->input($key, $default);
    }

    public function all(): array
    {
        return $this->request->all();
    }

    public function method(): string
    {
        return $this->request->getMethod();
    }

    public function header(string $name): ?string
    {
        return $this->request->header($name);
    }

    public function host(): string
    {
        return $this->request->getHost();
    }

    public function uri(): string
    {
        return $this->request->getRequestUri();
    }

    public function url(): string
    {
        return $this->request->url();
    }

    public function getRealIp(): string
    {
        return $this->request->getServerParams()['remote_addr'] ?? '127.0.0.1';
    }
}
```

- [ ] **Step 2: Create Hyperf ResponseAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf\Adapter;

use Hyperf\HttpServer\Response;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class ResponseAdapter implements ResponseInterface
{
    private Response $response;

    public function __construct(Response $response)
    {
        $this->response = $response;
    }

    public function withBody(string $body): self
    {
        $this->response = $this->response->withBody(
            \Hyperf\Utils\Context::get(\Psr\Http\Message\StreamInterface::class) ?? new \Hyperf\HttpMessage\Stream\SwooleStream($body)
        );
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        foreach ($headers as $key => $value) {
            $this->response = $this->response->withHeader($key, $value);
        }
        return $this;
    }

    public function file(string $path): self
    {
        $this->response = $this->response->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(''));
        if (file_exists($path)) {
            $this->response = $this->response->withHeader('X-Sendfile', $path);
        }
        return $this;
    }

    public function send(): mixed
    {
        return $this->response;
    }
}
```

- [ ] **Step 3: Create Hyperf ConfigAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf\Adapter;

use Hyperf\Contract\ConfigInterface as HyperfConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;

class ConfigAdapter implements ConfigInterface
{
    private HyperfConfigInterface $config;

    public function __construct(HyperfConfigInterface $config)
    {
        $this->config = $config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config->get($key, $default);
    }
}
```

- [ ] **Step 4: Create Hyperf RedisAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf\Adapter;

use Hyperf\Redis\Redis;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;

class RedisAdapter implements CacheInterface
{
    private Redis $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    public function get(string $key): mixed
    {
        return $this->redis->get($key);
    }

    public function set(string $key, mixed $value): bool
    {
        return $this->redis->set($key, $value);
    }

    public function incr(string $key): int
    {
        return $this->redis->incr($key);
    }

    public function lPush(string $key, mixed $value): int
    {
        return $this->redis->lPush($key, $value);
    }

    public function rpop(string $key): mixed
    {
        return $this->redis->rPop($key);
    }

    public function lrange(string $key, int $start, int $end): array
    {
        return $this->redis->lRange($key, $start, $end);
    }

    public function del(string ...$keys): int
    {
        return $this->redis->del(...$keys);
    }

    public function decr(string $key): int
    {
        return $this->redis->decr($key);
    }
}
```

- [ ] **Step 5: Create Hyperf LogAdapter**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf\Adapter;

use Psr\Log\LoggerInterface as PsrLoggerInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

class LogAdapter implements LoggerInterface
{
    private PsrLoggerInterface $logger;

    public function __construct(PsrLoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }
}
```

- [ ] **Step 6: Create Hyperf Middleware**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf;

use Hyperf\HttpServer\Contract\RequestInterface as HyperfRequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Hyperf\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\ResponseAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\ConfigAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\RedisAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\LogAdapter;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

class Middleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $container = ApplicationContext::getContainer();

        $req = new RequestAdapter($container->get(HyperfRequestInterface::class));
        $res = new ResponseAdapter($container->get(HyperfResponseInterface::class));

        Xhprof::bootstrap(
            $req,
            $res,
            new ConfigAdapter($container->get(ConfigInterface::class)),
            new RedisAdapter($container->get(Redis::class)),
            new LogAdapter($container->get(LoggerInterface::class))
        );

        $response = $handler->handle($request);

        if (XhprofProfiler::isEnabled() && extension_loaded('xhprof')) {
            Xhprof::xhprofStop();
        }

        return $response;
    }
}
```

- [ ] **Step 7: Create Hyperf ConfigProvider**

```php
<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'middlewares' => [
                'http' => [
                    \ErikWang2013\Xhprof\Hyperf\Middleware::class,
                ],
            ],
            'publish' => [
                [
                    'id' => 'xhprof',
                    'description' => 'XHProf config',
                    'source' => __DIR__ . '/config/xhprof.php',
                    'destination' => BASE_PATH . '/config/autoload/xhprof.php',
                ],
            ],
        ];
    }
}
```

- [ ] **Step 8: Create Hyperf config**

```php
<?php

declare(strict_types=1);

return [
    'enable' => true,
    'time_limit' => 0,
    'log_num' => 1000,
    'view_wtred' => 3,
    'ignore_url_arr' => ['/xhprof'],
    'assets_url' => '/xhprof-assets',
];
```

- [ ] **Step 9: Commit**

```bash
git add src/Hyperf/
git commit -m "feat: add Hyperf adapters, middleware, and ConfigProvider"
```

---

### Task 13: Remove old Webman XhprofLib (now in Core)

**Files:**
- Remove: `src/Webman/XhprofLib/`

- [ ] **Step 1: Verify Core XhprofLib is complete**

```bash
diff -rq src/Webman/XhprofLib src/Core/XhprofLib 2>/dev/null | head -20
```

- [ ] **Step 2: Remove old Webman XhprofLib**

```bash
rm -rf src/Webman/XhprofLib
```

- [ ] **Step 3: Commit**

```bash
git rm -r src/Webman/XhprofLib
git commit -m "refactor: remove old Webman XhprofLib, now in Core"
```

---

### Task 14: Final verification

- [ ] **Step 1: Verify no stale Webman namespace references in Core**

```bash
grep -rn "Webman\\\\" src/Core/ && echo "FAIL" || echo "PASS"
```

- [ ] **Step 2: Verify all Aaron namespaces replaced**

```bash
grep -rn "Aaron" src/ && echo "FAIL" || echo "PASS"
```

- [ ] **Step 3: Verify file structure matches design**

```bash
echo "=== Expected structure ===" && echo "Core/Contract/: $(ls src/Core/Contract/)" && echo "Core/: $(ls src/Core/*.php)" && echo "Core/XhprofLib/: $(ls src/Core/XhprofLib/Display/ src/Core/XhprofLib/Utils/)" && echo "Webman/Adapter/: $(ls src/Webman/Adapter/)" && echo "Laravel/Adapter/: $(ls src/Laravel/Adapter/)" && echo "Thinkphp/Adapter/: $(ls src/Thinkphp/Adapter/)" && echo "Hyperf/Adapter/: $(ls src/Hyperf/Adapter/)" && echo "html/: $(ls src/html/ 2>/dev/null | head -5)"
```

- [ ] **Step 4: PHP syntax check all files**

```bash
find src/Core src/Webman src/Laravel src/Thinkphp src/Hyperf -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

- [ ] **Step 5: Commit final verification**

```bash
git add -A && git status
```
