# XHProf Performance Profiler

A code performance profiling plugin compatible with webman / Laravel / ThinkPHP / Hyperf.

Collects profiling data via the xhprof extension and stores it in Redis. Developers can quickly access performance analysis reports through a browser to identify code performance bottlenecks.

## Requirements

- PHP >= 8.0
- xhprof extension
- redis extension
- Redis server

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

// CallGraph route (optional, requires graphviz `dot` command on server)
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

// CallGraph route (optional, requires graphviz `dot` command)
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

// CallGraph route (optional, requires graphviz `dot` command)
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

// CallGraph route (optional, requires graphviz `dot` command)
```

**4. Publish config**:

```sh
php bin/hyperf.php vendor:publish aaron-dev/xhprof-webman
```

Config output at `config/autoload/xhprof.php`.

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

---

## Author

[erik](https://erik.xyz)

## Support Open Source

<p align="center">
  <img src="./docs/weixinpay.png" alt="WeChat Pay" width="130" height="130" title="WeChat Pay" />
  <img src="./docs/alipay.png" alt="Alipay" width="130" height="130" title="Alipay" />
</p>

---

This plugin references [phacility/xhprof](https://github.com/phacility/xhprof) and [phpxxb/xhprof](https://github.com/xiexianbo123/xhprof).
