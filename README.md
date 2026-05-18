# XHProf 性能分析插件

兼容 webman / Laravel / ThinkPHP / Hyperf 的代码性能分析插件。

基于 xhprof 扩展采集数据并存入 Redis，开发者可通过浏览器快速访问性能分析报告，排查代码性能瓶颈。

## 环境要求

- PHP >= 8.0
- xhprof 扩展
- redis 扩展
- Redis 服务

## 安装

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

**2. 创建控制器**：

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

**3. 注册路由** — `config/route.php`：

```php
use Webman\Route;
use ErikWang2013\Xhprof\Webman\StaticController;

Route::get('/xhprof', [app\controller\XhprofController::class, 'index']);
Route::get('/xhprof-assets/{path:.+}', [StaticController::class, 'serve']);

// CallGraph 调用图路由（可选，需要服务器安装 graphviz `dot` 命令）
// Route::any('/xhprof/callgraph', [app\controller\XhprofController::class, 'callgraph']);
```

**4. 配置** — 见 `config/plugin/aaron-dev/xhprof/xhprof.php`。

---

### Laravel

**1. 注册中间件** — `app/Http/Kernel.php`：

```php
protected $middleware = [
    // ...
    \ErikWang2013\Xhprof\Laravel\Middleware::class,
];
```

**2. 创建控制器**：

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

**3. 注册路由** — `routes/web.php`：

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

// CallGraph 调用图路由（可选，需要 graphviz `dot` 命令）
// Route::any('/xhprof/callgraph', [XhprofController::class, 'callgraph']);
```

**4. 发布配置**：

```sh
php artisan vendor:publish --tag=xhprof-config
```

配置文件在 `config/xhprof.php`。Laravel 支持自动发现 ServiceProvider。

---

### ThinkPHP

**1. 注册中间件** — `app/middleware.php`：

```php
return [
    \ErikWang2013\Xhprof\Thinkphp\Middleware::class,
];
```

**2. 创建控制器**：

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

**3. 注册路由** — `route/app.php`：

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

// CallGraph 调用图路由（可选，需要 graphviz `dot` 命令）
// Route::any('/xhprof/callgraph', 'app\controller\XhprofController@callgraph');
```

**4. 配置** — 复制 `vendor/aaron-dev/xhprof-webman/src/Thinkphp/config/xhprof.php` 到项目 `config/xhprof.php`。

---

### Hyperf

**1. 中间件自动注册** — ConfigProvider 自动将中间件加入 HTTP 中间件队列。

**2. 创建控制器**：

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

**3. 静态资源路由** — `config/routes.php`：

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

// CallGraph 调用图路由（可选，需要 graphviz `dot` 命令）
// Router::addRoute(['GET', 'POST'], '/xhprof/callgraph', 'App\Controller\XhprofController@callgraph');
```

**4. 发布配置**：

```sh
php bin/hyperf.php vendor:publish aaron-dev/xhprof-webman
```

配置输出在 `config/autoload/xhprof.php`。

---

## 配置项说明

所有框架共用以下配置项：

| 配置 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `enable` | bool | `true` | 是否启用性能分析 |
| `time_limit` | int | `0` | 仅记录响应超过 n 秒的请求，0 表示全部 |
| `log_num` | int | `1000` | 最大记录条数 |
| `view_wtred` | int | `3` | 列表耗时超过 n 秒标红 |
| `ignore_url_arr` | array | `["/test"]` | 忽略的 URL 路径 |
| `assets_url` | string | `/xhprof-assets` | 静态资源 URL 前缀 |

---

## CallGraph 调用图

报告页面中的「CallGraph」功能依赖服务器安装 Graphviz：

```sh
# Debian/Ubuntu
apt install graphviz

# CentOS
yum install graphviz
```

安装后在各框架中注册 CallGraph 路由（见上方各框架路由配置中的注释部分）即可使用。

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

---

## 作者

[艾瑞可 erik](https://erik.xyz)

## 开源不易，欢迎支持

| 微信 | 支付宝 |
|:---:|:---:|
| ![微信](./docs/weixinpay.png "微信") | ![支付宝](./docs/alipay.png "支付宝") |

---

本插件参考 [phacility/xhprof](https://github.com/phacility/xhprof)、[phpxxb/xhprof](https://github.com/xiexianbo123/xhprof)
