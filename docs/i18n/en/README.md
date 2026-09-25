[中文](../../../README.md) · **English** · [한국어](../ko/README.md) · [Русский](../ru/README.md) · [Deutsch](../de/README.md) · [Français](../fr/README.md) · [Español](../es/README.md) · [Português](../pt/README.md) · [العربية](../ar/README.md) · [हिन्दी](../hi/README.md) · [বাংলা](../bn/README.md) · [Bahasa Indonesia](../id/README.md) · [日本語](../ja/README.md)

# XHProf Performance Profiler

[中文](./README.md) · **English** · [한국어](../../../docs/i18n/ko/README.md) · [Русский](../../../docs/i18n/ru/README.md) · [Deutsch](../../../docs/i18n/de/README.md) · [Français](../../../docs/i18n/fr/README.md) · [Español](../../../docs/i18n/es/README.md) · [Português](../../../docs/i18n/pt/README.md) · [العربية](../../../docs/i18n/ar/README.md) · [हिन्दी](../../../docs/i18n/hi/README.md) · [বাংলা](../../../docs/i18n/bn/README.md) · [Bahasa Indonesia](../../../docs/i18n/id/README.md) · [日本語](../../../docs/i18n/ja/README.md)

A code performance profiling plugin compatible with webman / Laravel / ThinkPHP / Hyperf / Yii3 / Symfony / Slim 4 / WordPress / Joomla and Drupal.

Collects profiling data via the xhprof extension and stores it in Redis. Developers can quickly access performance analysis reports through a browser to identify code performance bottlenecks.

## Requirements

- PHP >= 8.0
- xhprof extension
- redis extension
- Redis server

## Compatible Frameworks and Minimum Versions

| Framework | Minimum version | Minimum PHP | Entry class | How to mount |
|-----------|----------------|-------------|-------------|--------------|
| webman | `workerman/webman ^2.1` | 8.0 | `Webman\XhprofMiddleware` | Register global middleware in `config/middleware.php` |
| Laravel | `laravel/framework ^9.0\|^10.0\|^11.0` | 8.0 | `Laravel\Middleware` | Register global middleware in `app/Http/Kernel.php` |
| ThinkPHP | `topthink/framework ^6.0\|^8.0` | 8.0 | `Thinkphp\Middleware` | Register global middleware in `app/middleware.php` |
| Hyperf | `hyperf/framework ^3.0` | 8.0 | `Hyperf\Middleware` | Auto-registered via ConfigProvider |
| Yii3 | `yiisoft/middleware-dispatcher ^5.0` | 8.1 | `Yii3\XhprofMiddleware` | Register in `config/web/di/application.php`, must be first in the middleware list |
| Symfony | `symfony/http-kernel ^6.4\|^7.0` | 8.1 (6.4) / 8.2 (7.x) | `Symfony\XhprofListener` | Add the `kernel.event_subscriber` tag in `config/services.yaml` |
| Slim 4 | `slim/slim ^4.12` | 8.0 | `Slim\XhprofMiddleware` | `$app->add(...)`, must be added last |
| WordPress | 6.4+ | 8.0 | `Wordpress\XhprofPlugin` | Copy into `wp-content/mu-plugins/` |
| Joomla | 4.4 / 5.x | 8.1 | `Joomla\Extension\Xhprof` | Copy into `plugins/system/`, install via Discover |
| Drupal | 10.x / 11.x | 8.1 (10.x) / 8.3 (11.x) | `xhprof` module (`Drupal\XhprofMiddleware`) | Standard module, just enable it |

Entry classes all live under the `ErikWang2013\Xhprof\` namespace prefix (omitted above). Of the six new frameworks, Drupal is the exception — it serves the report page through module routes — while the other five entry classes **serve the report page themselves**, with no controller or route registration needed.

This package declares `php >= 8.0`, but the `yiisoft/*` components Yii3 relies on require **PHP 8.1+**, so **Yii3 is not usable on PHP 8.0**; Symfony 7.x and Drupal 11.x likewise need a higher PHP version. Step-by-step setup is in "Framework Configuration" below.

## Installation

Add xhprof configuration in php.ini:

```ini
[xhprof]
extension=xhprof.so
xhprof.output_dir=/tmp/xhprof
```

Install via Composer:

```sh
composer require aaron-dev/xhprof-webman
```

---

## Framework Configuration

### Webman

**1. Register global middleware** — `config/middleware.php`:

```php
return [
    '' => [
        ErikWang2013\Xhprof\Webman\XhprofMiddleware::class,
    ],
];
```

**2. Create controller**:

```php
<?php

namespace app\controller;

use support\Request;
use ErikWang2013\Xhprof\Webman\Xhprof;

class XhprofController
{
    public function index(Request $request)
    {
        return Xhprof::index();
    }
}
```

**3. Register routes** — `config/route.php`:

```php
use Webman\Route;
use ErikWang2013\Xhprof\Webman\StaticController;

Route::get('/xhprof', [app\controller\XhprofController::class, 'index']);
Route::get('/xhprof-assets/{path:.+}', [StaticController::class, 'serve']);

```

**4. Configuration** — See `config/plugin/aaron-dev/xhprof/xhprof.php`.

---

### Laravel

**1. Register middleware** — `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ...
    \ErikWang2013\Xhprof\Laravel\Middleware::class,
];
```

**2. Create controller**:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use ErikWang2013\Xhprof\Core\Xhprof;

class XhprofController extends Controller
{
    public function index(Request $request)
    {
        Xhprof::bootstrap();
        return Xhprof::index();
    }
}
```

**3. Register routes** — `routes/web.php`:

```php
use App\Http\Controllers\XhprofController;
use ErikWang2013\Xhprof\Core\StaticController;
use Illuminate\Support\Facades\Route;

Route::get('/xhprof', [XhprofController::class, 'index']);
Route::get('/xhprof-assets/{path}', function ($path) {
    $req = new \ErikWang2013\Xhprof\Laravel\Adapter\RequestAdapter(request());
    $res = new \ErikWang2013\Xhprof\Laravel\Adapter\ResponseAdapter(response(''));
    return StaticController::serve($req, $res)->send();
})->where('path', '.*');

```

**4. Publish config**:

```sh
php artisan vendor:publish --tag=xhprof-config
```

Config file at `config/xhprof.php`. Laravel supports auto-discovery of ServiceProvider.

---

### ThinkPHP

**1. Register middleware** — `app/middleware.php`:

```php
return [
    \ErikWang2013\Xhprof\Thinkphp\Middleware::class,
];
```

**2. Create controller**:

```php
<?php

namespace app\controller;

use think\Request;
use ErikWang2013\Xhprof\Core\Xhprof;

class XhprofController
{
    public function index(Request $request)
    {
        Xhprof::bootstrap();
        return Xhprof::index();
    }
}
```

**3. Register routes** — `route/app.php`:

```php
use think\facade\Route;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter;

Route::get('/xhprof', 'app\controller\XhprofController@index');
Route::get('/xhprof-assets/[:path]', function ($path = '') {
    $req = new RequestAdapter(app('request'));
    $res = new ResponseAdapter(response(''));
    return StaticController::serve($req, $res)->send();
})->pattern(['path' => '.*']);

```

**4. Configuration** — Copy `vendor/aaron-dev/xhprof-webman/src/Thinkphp/config/xhprof.php` to project `config/xhprof.php`.

---

### Hyperf

**1. Middleware auto-registration** — ConfigProvider automatically adds middleware to the HTTP middleware queue.

**2. Create controller**:

```php
<?php

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use ErikWang2013\Xhprof\Core\Xhprof;

#[Controller(prefix: '/xhprof')]
class XhprofController
{
    #[RequestMapping(path: '')]
    public function index()
    {
        Xhprof::bootstrap();
        return Xhprof::index();
    }
}
```

**3. Static asset routes** — `config/routes.php`:

```php
use Hyperf\HttpServer\Router\Router;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Hyperf\Adapter\RequestAdapter;
use ErikWang2013\Xhprof\Hyperf\Adapter\ResponseAdapter;
use Hyperf\Context\ApplicationContext;

Router::get('/xhprof-assets/{path:.+}', function ($path) {
    $container = ApplicationContext::getContainer();
    $req = new RequestAdapter($container->get(\Hyperf\HttpServer\Contract\RequestInterface::class));
    $res = new ResponseAdapter($container->get(\Hyperf\HttpServer\Contract\ResponseInterface::class));
    return StaticController::serve($req, $res)->send();
});

```

**4. Publish config**:

```sh
php bin/hyperf.php vendor:publish aaron-dev/xhprof-webman
```

Config output at `config/autoload/xhprof.php`.

---

### Yii3

**1. Register the middleware** — `config/web/di/application.php`:

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

Two easy mistakes (both measured):

- **Do not** write `'__construct()' => ['middlewares' => [...]]`: `MiddlewareDispatcher::__construct()` accepts only a `MiddlewareFactory` and an optional `EventDispatcherInterface` — there is **no** `middlewares` parameter. The middleware list can only be injected through the **instance method** `withMiddlewares()`.
- **Do not** put an instance (`new XhprofMiddleware(...)`) into `withMiddlewares()`: definitions accept only a class-string, an array definition, or a callable. With an instance, registration reports nothing and `dispatch()` throws a `TypeError` (`MiddlewareFactory::create()` is typed `callable|array|string`).

**2. Report page and static assets** — **no controller or route registration needed**: `XhprofMiddleware` is a PSR-15 middleware. Before profiling starts it inspects the request path: a hit on the report path `/xhprof` returns the report page immediately, and a hit on the asset path (default prefix `/xhprof-assets`) returns the static asset directly. The report page response carries an explicit `Content-Type: text/html; charset=UTF-8` from the entry class: PSR-7 responses have no default and Yii3's response sender does not add one, so without it browsers render the HTML report as plain text.

**3. Configuration** — defaults live in the package at `src/Yii3/config/xhprof.php`; see "Configuration Reference" for the fields. To override them, inject `$config` through DI:

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

The `redis` sub-array is Yii3-specific: when no `CacheInterface` is injected, the middleware uses it to talk to phpredis directly. Keep `assets_url` at its default — any other prefix only yields a 200 with an empty body (it is a hardcoded constant in Core; see [Verification and Known Limitations](#verification-and-known-limitations)).

**4. Version requirement** — the `yiisoft/*` components Yii3 relies on require PHP >= 8.1. Although this package declares `php >= 8.0`, the Yii3 integration cannot be used on PHP 8.0.

---

### Symfony

**1. Register the event subscriber** — `config/services.yaml`:

```yaml
services:
    ErikWang2013\Xhprof\Symfony\XhprofListener:
        tags:
            - { name: kernel.event_subscriber }
```

**2. Report page and static assets** — **no controller or route registration needed**: before profiling starts the listener inspects the request path: a hit on the report path `/xhprof` returns the report page immediately, and a hit on the asset path (default prefix `/xhprof-assets`) returns the static asset directly.

**3. Configuration** — defaults live in the package at `src/Symfony/config/xhprof.php`; see "Configuration Reference" for the fields.

**4. Sub-requests and exception fallback** — it listens on `kernel.request` (priority 10000) and `kernel.response` (priority -10000). `isMainRequest()` filters out ESI/fragment sub-requests, which would otherwise stop profiling too early; an idempotent `register_shutdown_function` is also registered when the request starts — if HttpKernel rethrows an exception, `kernel.response` never fires, and without that fallback the profiling state would leak into the next request.

---

### Slim 4

**1. Register the middleware** — `public/index.php`:

```php
use ErikWang2013\Xhprof\Slim\XhprofMiddleware;

$app->addRoutingMiddleware();

// must be added last: Slim's middleware stack is LIFO, added later = further out = runs first
$app->add(new XhprofMiddleware(
    $app->getResponseFactory()
));
```

The remaining three constructor arguments are all optional; omit them to use the packaged defaults:

- Argument 2, `array $config`: your config array, merged over the packaged `src/Slim/config/xhprof.php` with `array_replace` (whole-value replacement — list keys such as `ignore_url_arr` are never merged recursively).
- Argument 3, `CacheInterface $cache`: omitted, it lazily does `new \Redis()` (the constructor deliberately never touches ext-redis, so a missing extension does not blow up while the adapter is being built). To inject your own connection pass `new \ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter($redis)`, or any object implementing `ErikWang2013\Xhprof\Core\Contract\CacheInterface`.
- Argument 4, `LoggerInterface $logger`: omitted, it is `new \ErikWang2013\Xhprof\Slim\Adapter\LogAdapter()` and **logs are silently discarded** (Slim ships no PSR-3 logger). To record them pass `new LogAdapter($psrLogger)`, where `$psrLogger` is a PSR-3 logger you already have.

**Do not write `$app->add(XhprofMiddleware::class)`**: Slim's `CallableResolver` turns that class string into `new XhprofMiddleware($container)` — passing only the container, and deferring resolution until request time. The result is that `add()` reports nothing and the first request throws a `TypeError` — the hardest failure mode to diagnose. Always use the explicit `new` above.

**2. Report page and static assets** — **no controller or route registration needed**: before profiling starts the middleware inspects the request path: a hit on the report path `/xhprof` returns the report page immediately, and a hit on the asset path (default prefix `/xhprof-assets`) returns the static asset directly.

**3. Configuration** — defaults live in the package at `src/Slim/config/xhprof.php`; see "Configuration Reference" for the fields.

**4. Mounting order** — Slim's middleware stack is LIFO (measured with two middlewares, the execution order is `B:before → A:before → A:after → B:after`): the later you `add()`, the further out it sits and the earlier it runs. So xhprof must be added **last**, and **after `addRoutingMiddleware()`** — otherwise `/xhprof` is not in the routing table, RoutingMiddleware throws `HttpNotFoundException` first, and the request never reaches the middleware. The report page response carries an explicit `Content-Type: text/html; charset=UTF-8` from the entry class: PSR-7 responses have no default and Slim's `ResponseEmitter` does not add one, so without it browsers render the HTML report as plain text.

---

### WordPress

**1. Install the mu-plugin** — copy the bootstrap file from the package into `wp-content/mu-plugins/`:

```sh
cp vendor/aaron-dev/xhprof-webman/wordpress/xhprof-webman.php wp-content/mu-plugins/
```

`wordpress/xhprof-webman.php` carries a plugin header and boots `Wordpress\XhprofPlugin`. mu-plugins are loaded automatically — nothing to enable in wp-admin.

**2. Report page and static assets** — **no controller or route registration needed**: before profiling starts the entry class inspects the request path: a hit on the report path `/xhprof` returns the report page immediately, and a hit on the asset path (default prefix `/xhprof-assets`) returns the static asset directly.

**3. Configuration** — defaults live in the package at `src/Wordpress/config/xhprof.php`; see "Configuration Reference" for the fields. Use `ignore_url_arr` to exclude high-frequency paths such as `wp-cron.php` and `admin-ajax.php`.

**4. A structural limit of the profiling window** — the window is `plugins_loaded` → `shutdown`, which **does not include** the `wp-settings.php` bootstrap or plugin loading itself. That is a structural limit of WordPress: work done in that phase cannot be profiled.

---

### Joomla

**1. Install the plugin** — copy the package's `joomla/` directory into the site's `plugins/system/xhprof/`:

```sh
cp -r vendor/aaron-dev/xhprof-webman/joomla/ plugins/system/xhprof/
```

The package's `joomla/` directory *is* the plugin: the `xhprof.xml` manifest, `services/provider.php`, and `src/Extension/Xhprof.php`. The entry class is `ErikWang2013\Xhprof\Joomla\Extension\Xhprof` (a `CMSPlugin`). After copying, run Discover under "System → Manage → Extensions → Discover" and install/enable it.

**2. Report page and static assets** — **no controller or route registration needed**: before profiling starts the plugin inspects the request path: a hit on the report path `/xhprof` returns the report page immediately, and a hit on the asset path (default prefix `/xhprof-assets`) returns the static asset directly.

**3. Configuration** — defaults live in the package at `src/Joomla/config/xhprof.php`; see "Configuration Reference" for the fields. **Known trade-off**: this reads the package config file rather than plugin parameters — plugin parameters require a database read, and configuration is read on every request.

**4. Profiling boundaries** — the window is `ApplicationEvents::AFTER_INITIALISE` → `ApplicationEvents::AFTER_RESPOND`; an idempotent `register_shutdown_function` is also registered in `AFTER_INITIALISE`, because `AFTER_RESPOND` is not guaranteed to be reached on the exception path — without the fallback the profiling state would leak into the next request.

---

### Drupal

**1. Enable the module** — `drupal/xhprof/` in the package is a standard Drupal module (`xhprof.info.yml` / `xhprof.routing.yml` / `xhprof.services.yml`). Place it at `modules/custom/xhprof/` in your site, then enable it on the "Extend" page (or with `drush en xhprof`).

**2. Report page** — Drupal is the **only one of the ten frameworks that serves the report page through module routes**: `xhprof.routing.yml` registers the report path `/xhprof` and a module controller renders it. The other five new frameworks serve the report page and static assets themselves and register no routes.

**3. Configuration** — configuration is module-level typed config: defaults live in `drupal/xhprof/config/install/xhprof.settings.yml`, with the schema in `drupal/xhprof/config/schema/xhprof.schema.yml`. See "Configuration Reference" for the fields.

**4. Middleware registration** — register the middleware service in the module's `xhprof.services.yml`:

```yaml
services:
  xhprof.http_middleware:
    class: ErikWang2013\Xhprof\Drupal\XhprofMiddleware
    arguments: ['@config.factory', '@logger.factory']
    tags:
      - { name: http_middleware, priority: 1000 }
```

The inner kernel is **prepended automatically as constructor argument 0** by Drupal's `StackedKernelPass` — **do not write it yourself**: doing so yields two inner kernels, which fails at container compile time on Drupal <= 11.2.x (including all of 10.x) and throws a TypeError on every request on 11.3.0 and above. Profiling stops in `finally`.

**5. Notes**

- `priority: 1000` places the middleware **outside the page cache** (core's highest existing priority is negotiation: 400 on D10, 500 on D11, while the page cache is 200), so **requests served from Drupal's page cache are still profiled**. For a profiling tool that is the intended behaviour, but users should know it.
- The cache works out of the box: the middleware defaults to the Redis adapter shipped with this package (the package hard-depends on ext-redis), and it also accepts an optional `CacheInterface` argument via `arguments` in `services.yml` to override it. If the cache is unavailable, a failed save is swallowed by `XhprofProfiler::stop()` into a single log line — **no error is raised**.
- Requests to the report page `/xhprof` and to `/xhprof-assets/*` are **not profiled**: the middleware skips profiling by path before `xhprofStart()`. The response is still produced by the Controller in `xhprof.routing.yml` (**this is not a short-circuit**). So even with `ignore_url_arr` set to `[]` (filtering nothing), these two requests never show up in the report.

---

## Configuration Reference

All frameworks share these configuration options:

| Config | Type | Default | Description |
|--------|------|---------|-------------|
| `enable` | bool | `true` | Enable/disable profiling |
| `time_limit` | int | `0` | Only profile requests exceeding n seconds, 0 means all |
| `log_num` | int | `1000` | Maximum number of records |
| `view_wtred` | int | `3` | Highlight rows with response time > n seconds in red |
| `ignore_url_arr` | array | `["/xhprof"]` | URL paths to ignore |
| `assets_url` | string | `/xhprof-assets` | Static asset URL prefix |
| `auth_token` | string\|null | `null` | When set, report page requires `?token=xxx`; recommended for public deployments |
| `key_prefix` | string | `xhprof` | Redis key prefix; set distinct values per project when sharing one Redis |
| `log_ttl` | int | `604800` | Data retention in seconds (default 7 days) |

Known limitations of these options on each framework are listed in [Verification and Known Limitations](#verification-and-known-limitations).

---

## Manual Initialization

If automatic framework detection fails, you can manually inject adapters:

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

**The six new frameworks (Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal) must not call the no-argument `Xhprof::bootstrap()`** — with no arguments it goes through `autoDetect()`, which only knows the webman / Laravel / ThinkPHP / Hyperf branches and throws `Unsupported framework` on these six. Pass all 5 adapters explicitly as in the example above (the entry class shipped with each framework already does this for you).

---

## Architecture and Design

Core reaches the framework through exactly 5 contracts, all of them in `src/Core/Contract/`:

| Contract | Methods | Purpose |
|----------|---------|---------|
| `RequestInterface` | `get()` `all()` `method()` `header()` `host()` `uri()` `url()` `getRealIp()` | Read request data, match the report path, build report-page links |
| `ResponseInterface` | `withBody()` `withHeaders()` `withStatus()` `file()` `send()` | Emit the report page, static assets, and 400/403 |
| `ConfigInterface` | `get()` | Read plugin config: `get('xhprof')` for the whole block, `get('xhprof.assets_url')` for a leaf |
| `CacheInterface` | `get()` `set()` `mget()` `incr()` `lPush()` `rPop()` `lRange()` `del()` `decr()` | Redis reads and writes |
| `LoggerInterface` | `error()` | Warnings for missing extensions and failed saves |

Each framework provides 5 adapters implementing these contracts, registered into Core by `Xhprof::bootstrap()`. Everything framework-specific stays inside that framework's own `src/<Fw>/` directory.

**Only two framework couplings remain in Core**:

1. The `class_exists()` chain in `Xhprof::autoDetect()` (`Webman\App` → `Illuminate\Foundation\Application` → `think\App` → `Hyperf\Context\ApplicationContext`), reached only by the no-argument `bootstrap()`.
2. The hardcoded Hyperf coroutine switch: `Xhprof::markHyperfContext()` plus the `\Hyperf\Context\Context` existence checks, which decide whether adapters go into process-wide static properties or the coroutine Context.

**The six new frameworks never go through `autoDetect()` — they all use explicit injection**: each entry class constructs its own 5 adapters and passes them to `Xhprof::bootstrap($req, $res, $cfg, $cache, $log)`. The reason is that on PSR-7 frameworks the Request/Response can only be obtained from the request pipeline, so a no-argument `bootstrap()` cannot work by construction; a second benefit is that `autoDetect()` stays frozen at its current four frameworks.

![Architecture](./images/architecture.svg)

The first diagram is the **structure**: each of the ten frameworks' entry class, the 5 contracts, Core's three layers, and the only two couplings left.

![Design rationale](./images/design.svg)

The second diagram is the **reasoning**: five trade-offs laid out as decision / reason / cost, headed by "changes to `src/Core/` from the six new frameworks = 0".

---

## Request Lifecycle

One profiled request:

1. **Before profiling starts**, the entry class inspects the path: a hit on the report path returns the report page immediately; a hit on the asset path returns the static asset immediately. Neither path is profiled, and neither enters the flow below.
2. `XhprofProfiler::isEnabled()` reads `enable` from the config; if profiling is off or an extension is missing, the whole block is skipped.
3. `Xhprof::xhprofStart()` → `XhprofProfiler::start()` → `xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY)`.
4. Business logic runs.
5. `finally { Xhprof::xhprofStop(); }` → `xhprof_disable()`, then `XHProfRunsDefault::save_run()` writes to Redis. `finally` rather than a plain statement, so that a thrown exception still clears the profiling state and saves the run.
6. The browser opens the report page; `Xhprof::index()` reads the data back from Redis and renders it.

![Lifecycle](./images/lifecycle.svg)

| Framework | Profiling starts | Profiling ends |
|-----------|-----------------|----------------|
| webman / Laravel / ThinkPHP / Hyperf | Middleware entry (`process()` / `handle()`) | `finally` |
| Yii3 / Slim 4 | PSR-15 `process()` | `finally` |
| Symfony | `kernel.request` (priority 10000) | `kernel.response` (priority -10000), plus a shutdown fallback |
| WordPress | `plugins_loaded` | `shutdown` |
| Joomla | `onAfterInitialise` | `onAfterRespond`, plus a shutdown fallback |
| Drupal | `http_middleware` (priority 1000, outermost) | `finally` |

---

## Project Structure

```
xhprof-webman/
├── src/
│   ├── Core/                     # Framework-agnostic: contracts, report page, Redis, assets
│   │   ├── Contract/             # the 5 contract interfaces
│   │   ├── XhprofLib/            # report rendering and run storage (from phacility/xhprof)
│   │   ├── Xhprof.php            # static facade: bootstrap() / index()
│   │   ├── XhprofProfiler.php    # xhprof_enable/disable and config
│   │   ├── StaticController.php  # /xhprof-assets static assets
│   │   ├── MiddlewareTrait.php   # shared profiling wrapper for Laravel / ThinkPHP
│   │   └── RedisAdapterTrait.php # shared Redis adapter implementation
│   ├── Webman/ Laravel/ Thinkphp/ Hyperf/            # the existing 4 frameworks
│   ├── Yii3/ Symfony/ Slim/ Wordpress/ Joomla/ Drupal/   # the 6 new frameworks
│   └── html/                     # report page assets (css / js / images)
├── wordpress/                    # mu-plugin bootstrap file (with plugin header)
├── joomla/                       # Joomla plugin (CMSPlugin + manifest)
├── drupal/xhprof/                # standard Drupal module (info / routing / services + controller)
├── tools/contracts/              # standalone verification loop: signatures and semantics against real framework packages
├── tests/                        # PHPUnit: adapters, wiring, README parity
└── docs/images/                  # README diagrams
```

Except for Drupal, every `src/<Fw>/` directory has the same shape:

```
src/<Fw>/
├── Adapter/{Request,Response,Config,Redis,Log}Adapter.php
├── <EntryClass>.php
└── config/xhprof.php             # the same 9 config keys as every other framework
```

`src/Drupal/` is the one exception: it has no `config/` directory — its configuration lives in module-level typed config (`drupal/xhprof/config/install/xhprof.settings.yml`).

---

## Verification and Known Limitations

**What is mechanically proven**

| Item | How |
|------|-----|
| Adapter and entry-wiring behaviour | `tests/Unit/Adapter/*Test.php`: enabled → saved / disabled → not saved / business exception → still saved via `finally` |
| All ten frameworks share one config key set | config parity test (key sets, not byte-for-byte; comments may differ) |
| The two READMEs mirror each other | README parity test: compares the `##` / `###` heading sequence and the number of code blocks |
| The methods the adapters call really exist | `tools/contracts/` verification loop (its own CI job): installs real framework packages and asserts every method / constant / global function via reflection |
| Adapter semantics | The same loop instantiates real request and response objects and runs the adapters, including two invariants: `uri()` carries no scheme/host, and `withHeaders()` still applies after `file()` |

The first three rows above — adapter and entry-wiring behaviour, one shared config key set across all ten frameworks, and the two READMEs mirroring each other — describe **future deliverables** (the six-framework wiring cases, the config parity test, the README parity test) that are not in place as of this document. For the six new frameworks, treat the manual smoke checklist as the source of truth for now.

**Not automatically verified (do not read this as "all six were tested")**

| Item | Why not |
|------|---------|
| Every framework's **wiring** (is the hook really attached, does the event really fire) | Unit tests use stubs; wiring can currently only be confirmed by manual smoke tests |
| WordPress end to end | The actual `plugins_loaded` timing, whether `shutdown` fires on a fatal error, and whether the mu-plugin is loaded all require a real WordPress |
| Joomla plugin discovery and `$app->close()` | Requires running Discover in a real Joomla admin |
| Whether Drupal's priority really lands outside the page cache | Requires a booted Drupal kernel |
| Symfony's `kernel.event_subscriber` auto-configuration | Requires a real container compile |
| Static-state crosstalk in long-running processes | Inherited from the existing architecture (the same is true of Webman / Hyperf); unchanged here |
| Real Redis I/O, browser rendering, profiling overhead under real load | Outside the scope of unit tests and the verification loop |

**Manual smoke checklist (three steps per framework)**

| Step | Action | Expected |
|------|--------|----------|
| 1 | Mount the entry class as described in "Framework Configuration" | No errors |
| 2 | Hit any application URL | The length of the `xhprof:run_id` key in Redis goes up by 1 |
| 3 | Open `/xhprof` | The report page renders with its styles; `/xhprof-assets/js/xhprof_report.js` returns 200 |

**Known limitation: `host()` has no port**

The `host()` contract means "host only, no port", but the links in the report list are built as `host() . uri()` (`src/Core/XhprofLib/Utils/XHProfRunsDefault.php`). So **on a non-standard port (e.g. `:8080`) the list links lose the port and lead nowhere**. This is a latent problem in the existing implementation (it affects webman / Laravel / ThinkPHP / Hyperf equally), is not fixed here, and is recorded as a known limitation.

**Known limitation: `assets_url` only works when it is `/xhprof-assets`**

The asset prefix is a hardcoded constant in `src/Core/StaticController.php` (`private const URI_PREFIX = '/xhprof-assets'`), while the report page's CSS/JS links read the `assets_url` config option (`src/Core/Xhprof.php`). Once the two disagree, `getPathFromRequest()` returns `null` and `serve()` returns `withBody('')->withHeaders([])` — **a 200 empty response, not a 404**. The consequence: set `assets_url` to anything else and the CSS/JS silently go empty, leaving the report page unstyled with no error of any kind. In other words, `assets_url` is currently a fake option that only works when left at its default. This is a pre-existing problem and is not fixed here.

**Known limitation: the path guard fails when Drupal lives in a subdirectory**

When Drupal is installed under a subdirectory (e.g. `/sites/app/xhprof`), the path guard cannot match a URI that carries the base path, so behaviour falls back to "profiled but not saved" (with the default config, `ignore_url_arr` catches it). This is the same class of limitation as the hardcoded `assets_url` prefix.

**Symfony 6.4 compatibility**

Symfony 6.4 compatibility has been measured (which is how two over-fits invisible on 7.4 were fixed: `Request` properties carry no native type declaration on 6.4, and the charset added by `prepare()` differs in case), but the CI verification loop only runs 7.4.

---

## Author

[erik](https://erik.xyz)

## Support Open Source

<p align="center">
  <img src="../../../docs/weixinpay.png" alt="WeChat Pay" width="130" height="130" title="WeChat Pay" />
  <img src="../../../docs/alipay.png" alt="Alipay" width="130" height="130" title="Alipay" />
</p>

---

This plugin references [phacility/xhprof](https://github.com/phacility/xhprof) and [phpxxb/xhprof](https://github.com/xiexianbo123/xhprof).
