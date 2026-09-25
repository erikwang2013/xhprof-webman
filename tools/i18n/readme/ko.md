# XHProf 성능 프로파일러

webman / Laravel / ThinkPHP / Hyperf / Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal 과 호환되는 코드 성능 프로파일링 플러그인입니다.

xhprof 확장으로 프로파일링 데이터를 수집해 Redis에 저장합니다. 개발자는 브라우저로 성능 분석 보고서를 빠르게 열어 코드의 성능 병목을 찾아낼 수 있습니다.

## 요구 사항

- PHP >= 8.0
- xhprof 확장
- redis 확장
- Redis 서버

## 호환 프레임워크와 최소 버전

| 프레임워크 | 최소 버전 | 최소 PHP | 진입 클래스 | 마운트 방법 |
|-----------|----------------|-------------|-------------|--------------|
| webman | `workerman/webman ^2.1` | 8.0 | `Webman\XhprofMiddleware` | `config/middleware.php` 에 전역 미들웨어 등록 |
| Laravel | `laravel/framework ^9.0\|^10.0\|^11.0` | 8.0 | `Laravel\Middleware` | `app/Http/Kernel.php` 에 전역 미들웨어 등록 |
| ThinkPHP | `topthink/framework ^6.0\|^8.0` | 8.0 | `Thinkphp\Middleware` | `app/middleware.php` 에 전역 미들웨어 등록 |
| Hyperf | `hyperf/framework ^3.0` | 8.0 | `Hyperf\Middleware` | ConfigProvider로 자동 등록 |
| Yii3 | `yiisoft/middleware-dispatcher ^5.0` | 8.1 | `Yii3\XhprofMiddleware` | `config/web/di/application.php` 에 등록, 미들웨어 목록의 맨 앞이어야 함 |
| Symfony | `symfony/http-kernel ^6.4\|^7.0` | 8.1 (6.4) / 8.2 (7.x) | `Symfony\XhprofListener` | `config/services.yaml` 에 `kernel.event_subscriber` 태그 추가 |
| Slim 4 | `slim/slim ^4.12` | 8.0 | `Slim\XhprofMiddleware` | `$app->add(...)`, 맨 마지막에 추가해야 함 |
| WordPress | 6.4+ | 8.0 | `Wordpress\XhprofPlugin` | `wp-content/mu-plugins/` 로 복사 |
| Joomla | 4.4 / 5.x | 8.1 | `Joomla\Extension\Xhprof` | `plugins/system/` 로 복사 후 Discover로 설치 |
| Drupal | 10.x / 11.x | 8.1 (10.x) / 8.3 (11.x) | `xhprof` 모듈 (`Drupal\XhprofMiddleware`) | 표준 모듈, 활성화만 하면 됨 |

진입 클래스는 모두 `ErikWang2013\Xhprof\` 네임스페이스 접두사 아래에 있습니다(위 표에서는 생략했습니다). 신규 6개 프레임워크 중 Drupal은 예외로, 모듈 라우트를 통해 보고서 페이지를 제공합니다. 나머지 다섯 진입 클래스는 **보고서 페이지를 직접 제공**하므로 컨트롤러도 라우트 등록도 필요하지 않습니다.

이 패키지는 `php >= 8.0` 을 선언하지만, Yii3가 의존하는 `yiisoft/*` 컴포넌트는 **PHP 8.1 이상**을 요구하므로 **PHP 8.0에서는 Yii3를 사용할 수 없습니다**. Symfony 7.x와 Drupal 11.x도 마찬가지로 더 높은 PHP 버전이 필요합니다. 단계별 설정 방법은 아래 "프레임워크 설정"에 있습니다.

## 설치

php.ini 에 xhprof 설정을 추가합니다:

```ini
[xhprof]
extension=xhprof.so
xhprof.output_dir=/tmp/xhprof
```

Composer로 설치합니다:

```sh
composer require aaron-dev/xhprof-webman
```

---

## 프레임워크 설정

### Webman

**1. 전역 미들웨어 등록** — `config/middleware.php`:

```php
return [
    '' => [
        ErikWang2013\Xhprof\Webman\XhprofMiddleware::class,
    ],
];
```

**2. 컨트롤러 생성**:

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

**3. 라우트 등록** — `config/route.php`:

```php
use Webman\Route;
use ErikWang2013\Xhprof\Webman\StaticController;

Route::get('/xhprof', [app\controller\XhprofController::class, 'index']);
Route::get('/xhprof-assets/{path:.+}', [StaticController::class, 'serve']);

```

**4. 설정** — `config/plugin/aaron-dev/xhprof/xhprof.php` 를 참고하십시오.

---

### Laravel

**1. 미들웨어 등록** — `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ...
    \ErikWang2013\Xhprof\Laravel\Middleware::class,
];
```

**2. 컨트롤러 생성**:

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

**3. 라우트 등록** — `routes/web.php`:

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

**4. 설정 파일 배포**:

```sh
php artisan vendor:publish --tag=xhprof-config
```

설정 파일은 `config/xhprof.php` 입니다. Laravel은 ServiceProvider 자동 탐색을 지원합니다.

---

### ThinkPHP

**1. 미들웨어 등록** — `app/middleware.php`:

```php
return [
    \ErikWang2013\Xhprof\Thinkphp\Middleware::class,
];
```

**2. 컨트롤러 생성**:

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

**3. 라우트 등록** — `route/app.php`:

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

**4. 설정** — `vendor/aaron-dev/xhprof-webman/src/Thinkphp/config/xhprof.php` 를 프로젝트의 `config/xhprof.php` 로 복사합니다.

---

### Hyperf

**1. 미들웨어 자동 등록** — ConfigProvider가 HTTP 미들웨어 큐에 미들웨어를 자동으로 추가합니다.

**2. 컨트롤러 생성**:

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

**3. 정적 리소스 라우트** — `config/routes.php`:

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

**4. 설정 파일 배포**:

```sh
php bin/hyperf.php vendor:publish aaron-dev/xhprof-webman
```

설정은 `config/autoload/xhprof.php` 에 출력됩니다.

---

### Yii3

**1. 미들웨어 등록** — `config/web/di/application.php`:

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

흔히 하는 실수 두 가지(둘 다 실측으로 확인했습니다):

- `'__construct()' => ['middlewares' => [...]]` 라고 **쓰면 안 됩니다**. `MiddlewareDispatcher::__construct()` 는 `MiddlewareFactory` 와 선택적 `EventDispatcherInterface` 만 받으며 `middlewares` 파라미터는 **없습니다**. 미들웨어 목록은 **인스턴스 메서드** `withMiddlewares()` 로만 주입할 수 있습니다.
- `withMiddlewares()` 에 인스턴스(`new XhprofMiddleware(...)`)를 **넣으면 안 됩니다**. 정의는 클래스 문자열, 배열 정의, 콜러블만 받습니다. 인스턴스를 넣으면 등록은 아무것도 보고하지 않고 `dispatch()` 가 `TypeError` 를 던집니다(`MiddlewareFactory::create()` 의 타입은 `callable|array|string` 입니다).

**2. 보고서 페이지와 정적 리소스** — **컨트롤러도 라우트 등록도 필요하지 않습니다**. `XhprofMiddleware` 는 PSR-15 미들웨어입니다. 프로파일링을 시작하기 전에 요청 경로를 확인해, 보고서 경로 `/xhprof` 에 맞으면 보고서 페이지를 즉시 반환하고, 리소스 경로(기본 접두사 `/xhprof-assets`)에 맞으면 정적 리소스를 바로 반환합니다. 보고서 페이지 응답에는 진입 클래스가 `Content-Type: text/html; charset=UTF-8` 을 명시적으로 붙입니다. PSR-7 응답에는 기본값이 없고 Yii3의 응답 송신기도 이를 붙이지 않으므로, 이것이 없으면 브라우저가 HTML 보고서를 일반 텍스트로 렌더링합니다.

**3. 설정** — 기본값은 패키지의 `src/Yii3/config/xhprof.php` 에 있습니다. 각 필드는 "설정 레퍼런스"를 참고하십시오. 값을 덮어쓰려면 DI로 `$config` 를 주입합니다:

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

`redis` 하위 배열은 Yii3 전용입니다. `CacheInterface` 를 주입하지 않으면 미들웨어가 이 값으로 phpredis와 직접 통신합니다. `assets_url` 은 기본값 그대로 두십시오. 다른 접두사를 쓰면 본문이 빈 200 응답만 돌아옵니다(Core에 하드코딩된 상수입니다. [검증과 알려진 제한 사항](#검증과-알려진-제한-사항) 참고).

**4. 버전 요구 사항** — Yii3가 의존하는 `yiisoft/*` 컴포넌트는 PHP >= 8.1을 요구합니다. 이 패키지가 `php >= 8.0` 을 선언하더라도 Yii3 연동은 PHP 8.0에서 사용할 수 없습니다.

---

### Symfony

**1. 이벤트 구독자 등록** — `config/services.yaml`:

```yaml
services:
    ErikWang2013\Xhprof\Symfony\XhprofListener:
        tags:
            - { name: kernel.event_subscriber }
```

**2. 보고서 페이지와 정적 리소스** — **컨트롤러도 라우트 등록도 필요하지 않습니다**. 프로파일링을 시작하기 전에 리스너가 요청 경로를 확인해, 보고서 경로 `/xhprof` 에 맞으면 보고서 페이지를 즉시 반환하고, 리소스 경로(기본 접두사 `/xhprof-assets`)에 맞으면 정적 리소스를 바로 반환합니다.

**3. 설정** — 기본값은 패키지의 `src/Symfony/config/xhprof.php` 에 있습니다. 각 필드는 "설정 레퍼런스"를 참고하십시오.

**4. 서브 요청과 예외 폴백** — `kernel.request`(우선순위 10000)와 `kernel.response`(우선순위 -10000)를 수신합니다. `isMainRequest()` 가 ESI/프래그먼트 서브 요청을 걸러내는데, 그렇지 않으면 프로파일링이 너무 일찍 끝나 버립니다. 요청이 시작될 때 멱등한 `register_shutdown_function` 도 함께 등록합니다. HttpKernel이 예외를 다시 던지면 `kernel.response` 가 아예 발생하지 않으므로, 이 폴백이 없으면 프로파일링 상태가 다음 요청으로 새어 나갑니다.

---

### Slim 4

**1. 미들웨어 등록** — `public/index.php`:

```php
use ErikWang2013\Xhprof\Slim\XhprofMiddleware;

$app->addRoutingMiddleware();

// must be added last: Slim's middleware stack is LIFO, added later = further out = runs first
$app->add(new XhprofMiddleware(
    $app->getResponseFactory()
));
```

나머지 생성자 인자 세 개는 모두 선택 사항이며, 생략하면 패키지의 기본값을 씁니다:

- 두 번째 인자 `array $config`: 직접 만든 설정 배열로, 패키지의 `src/Slim/config/xhprof.php` 위에 `array_replace` 로 병합됩니다(값 전체를 교체하며, `ignore_url_arr` 같은 리스트 키는 재귀 병합되지 않습니다).
- 세 번째 인자 `CacheInterface $cache`: 생략하면 지연 초기화로 `new \Redis()` 를 실행합니다(생성자는 의도적으로 ext-redis를 건드리지 않으므로, 확장이 없어도 어댑터를 만드는 중에 터지지 않습니다). 직접 연결을 주입하려면 `new \ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter($redis)` 를 넘기거나 `ErikWang2013\Xhprof\Core\Contract\CacheInterface` 를 구현한 아무 객체나 넘기면 됩니다.
- 네 번째 인자 `LoggerInterface $logger`: 생략하면 `new \ErikWang2013\Xhprof\Slim\Adapter\LogAdapter()` 가 되고 **로그는 조용히 버려집니다**(Slim은 PSR-3 로거를 제공하지 않습니다). 기록하려면 `new LogAdapter($psrLogger)` 를 넘기십시오. `$psrLogger` 는 이미 가지고 있는 PSR-3 로거입니다.

**`$app->add(XhprofMiddleware::class)` 라고 쓰지 마십시오**: Slim의 `CallableResolver` 가 그 클래스 문자열을 `new XhprofMiddleware($container)` 로 바꿔 버려, 컨테이너만 넘기고 해석을 요청 시점까지 미룹니다. 그 결과 `add()` 는 아무것도 보고하지 않고 첫 요청에서 `TypeError` 가 납니다. 진단하기 가장 어려운 실패 방식입니다. 항상 위 예시처럼 `new` 를 명시하십시오.

**2. 보고서 페이지와 정적 리소스** — **컨트롤러도 라우트 등록도 필요하지 않습니다**. 프로파일링을 시작하기 전에 미들웨어가 요청 경로를 확인해, 보고서 경로 `/xhprof` 에 맞으면 보고서 페이지를 즉시 반환하고, 리소스 경로(기본 접두사 `/xhprof-assets`)에 맞으면 정적 리소스를 바로 반환합니다.

**3. 설정** — 기본값은 패키지의 `src/Slim/config/xhprof.php` 에 있습니다. 각 필드는 "설정 레퍼런스"를 참고하십시오.

**4. 마운트 순서** — Slim의 미들웨어 스택은 LIFO입니다(미들웨어 두 개로 실측한 실행 순서는 `B:before → A:before → A:after → B:after`). 늦게 `add()` 할수록 바깥에 놓여 먼저 실행됩니다. 따라서 xhprof는 **맨 마지막에**, 그리고 **`addRoutingMiddleware()` 뒤에** 추가해야 합니다. 그렇지 않으면 `/xhprof` 가 라우팅 테이블에 없어 RoutingMiddleware가 먼저 `HttpNotFoundException` 을 던지고, 요청이 미들웨어까지 도달하지 못합니다. 보고서 페이지 응답에는 진입 클래스가 `Content-Type: text/html; charset=UTF-8` 을 명시적으로 붙입니다. PSR-7 응답에는 기본값이 없고 Slim의 `ResponseEmitter` 도 이를 붙이지 않으므로, 이것이 없으면 브라우저가 HTML 보고서를 일반 텍스트로 렌더링합니다.

---

### WordPress

**1. mu-plugin 설치** — 패키지의 부트스트랩 파일을 `wp-content/mu-plugins/` 로 복사합니다:

```sh
cp vendor/aaron-dev/xhprof-webman/wordpress/xhprof-webman.php wp-content/mu-plugins/
```

`wordpress/xhprof-webman.php` 에는 플러그인 헤더가 있고 `Wordpress\XhprofPlugin` 을 부팅합니다. mu-plugin은 자동으로 로드되므로 wp-admin에서 활성화할 것은 없습니다.

**2. 보고서 페이지와 정적 리소스** — **컨트롤러도 라우트 등록도 필요하지 않습니다**. 프로파일링을 시작하기 전에 진입 클래스가 요청 경로를 확인해, 보고서 경로 `/xhprof` 에 맞으면 보고서 페이지를 즉시 반환하고, 리소스 경로(기본 접두사 `/xhprof-assets`)에 맞으면 정적 리소스를 바로 반환합니다.

**3. 설정** — 기본값은 패키지의 `src/Wordpress/config/xhprof.php` 에 있습니다. 각 필드는 "설정 레퍼런스"를 참고하십시오. `ignore_url_arr` 로 `wp-cron.php` 나 `admin-ajax.php` 같은 고빈도 경로를 제외할 수 있습니다.

**4. 프로파일링 구간의 구조적 한계** — 구간은 `plugins_loaded` → `shutdown` 이며, 여기에는 `wp-settings.php` 부트스트랩과 플러그인 로딩 자체가 **포함되지 않습니다**. 이는 WordPress의 구조적 한계로, 그 단계에서 수행되는 작업은 프로파일링할 수 없습니다.

---

### Joomla

**1. 플러그인 설치** — 패키지의 `joomla/` 디렉터리를 사이트의 `plugins/system/xhprof/` 로 복사합니다:

```sh
cp -r vendor/aaron-dev/xhprof-webman/joomla/ plugins/system/xhprof/
```

패키지의 `joomla/` 디렉터리 자체가 플러그인입니다. `xhprof.xml` 매니페스트, `services/provider.php`, `src/Extension/Xhprof.php` 로 구성됩니다. 진입 클래스는 `ErikWang2013\Xhprof\Joomla\Extension\Xhprof` (`CMSPlugin`)입니다. 복사한 뒤 "시스템 → 관리 → 확장 → 발견"에서 Discover를 실행하고 설치·활성화하십시오.

**2. 보고서 페이지와 정적 리소스** — **컨트롤러도 라우트 등록도 필요하지 않습니다**. 프로파일링을 시작하기 전에 플러그인이 요청 경로를 확인해, 보고서 경로 `/xhprof` 에 맞으면 보고서 페이지를 즉시 반환하고, 리소스 경로(기본 접두사 `/xhprof-assets`)에 맞으면 정적 리소스를 바로 반환합니다.

**3. 설정** — 기본값은 패키지의 `src/Joomla/config/xhprof.php` 에 있습니다. 각 필드는 "설정 레퍼런스"를 참고하십시오. **알려진 트레이드오프**: 플러그인 파라미터가 아니라 패키지 설정 파일을 읽습니다. 플러그인 파라미터는 데이터베이스 조회가 필요하고, 설정은 요청마다 읽히기 때문입니다.

**4. 프로파일링 경계** — 구간은 `ApplicationEvents::AFTER_INITIALISE` → `ApplicationEvents::AFTER_RESPOND` 이며, `AFTER_INITIALISE` 에서 멱등한 `register_shutdown_function` 도 함께 등록합니다. 예외 경로에서는 `AFTER_RESPOND` 에 도달한다는 보장이 없어, 이 폴백이 없으면 프로파일링 상태가 다음 요청으로 새어 나갑니다.

---

### Drupal

**1. 모듈 활성화** — 패키지의 `drupal/xhprof/` 는 표준 Drupal 모듈입니다(`xhprof.info.yml` / `xhprof.routing.yml` / `xhprof.services.yml`). 사이트의 `modules/custom/xhprof/` 에 놓은 뒤 "확장" 페이지에서 활성화하십시오(`drush en xhprof` 도 가능합니다).

**2. 보고서 페이지** — Drupal은 **열 개 프레임워크 중 유일하게 모듈 라우트로 보고서 페이지를 제공합니다**. `xhprof.routing.yml` 이 보고서 경로 `/xhprof` 를 등록하고 모듈 컨트롤러가 이를 렌더링합니다. 나머지 신규 다섯 프레임워크는 보고서 페이지와 정적 리소스를 직접 제공하며 라우트를 등록하지 않습니다.

**3. 설정** — 설정은 모듈 수준의 타입 지정 config입니다. 기본값은 `drupal/xhprof/config/install/xhprof.settings.yml` 에 있고 스키마는 `drupal/xhprof/config/schema/xhprof.schema.yml` 에 있습니다. 각 필드는 "설정 레퍼런스"를 참고하십시오.

**4. 미들웨어 등록** — 모듈의 `xhprof.services.yml` 에 미들웨어 서비스를 등록합니다:

```yaml
services:
  xhprof.http_middleware:
    class: ErikWang2013\Xhprof\Drupal\XhprofMiddleware
    arguments: ['@config.factory', '@logger.factory']
    tags:
      - { name: http_middleware, priority: 1000 }
```

안쪽 커널은 Drupal의 `StackedKernelPass` 가 **생성자 인자 0번으로 자동으로 앞에 붙입니다**. **직접 쓰지 마십시오**: 직접 쓰면 안쪽 커널이 둘이 되어, Drupal <= 11.2.x(10.x 전체 포함)에서는 컨테이너 컴파일 시점에 실패하고 11.3.0 이상에서는 모든 요청에서 TypeError가 납니다. 프로파일링은 `finally` 에서 멈춥니다.

**5. 참고**

- `priority: 1000` 은 미들웨어를 **페이지 캐시 바깥**에 놓습니다(코어에 이미 있는 가장 높은 우선순위는 negotiation으로 D10에서 400, D11에서 500이고, 페이지 캐시는 200입니다). 그래서 **Drupal 페이지 캐시에서 처리되는 요청도 여전히 프로파일링됩니다**. 프로파일링 도구로서는 의도한 동작이지만, 사용자는 이 점을 알아 두는 편이 좋습니다.
- 캐시는 별도 설정 없이 동작합니다. 미들웨어는 기본적으로 이 패키지에 포함된 Redis 어댑터를 사용하며(패키지는 ext-redis에 하드 의존합니다), `services.yml` 의 `arguments` 로 선택적 `CacheInterface` 인자를 받아 이를 덮어쓸 수도 있습니다. 캐시를 쓸 수 없으면 저장 실패는 `XhprofProfiler::stop()` 이 로그 한 줄로 삼켜 버리며 **오류는 발생하지 않습니다**.
- 보고서 페이지 `/xhprof` 와 `/xhprof-assets/*` 로 가는 요청은 **프로파일링되지 않습니다**. 미들웨어가 `xhprofStart()` 전에 경로로 걸러내기 때문입니다. 응답은 여전히 `xhprof.routing.yml` 의 Controller가 생성합니다(**이것은 단락이 아닙니다**). 따라서 `ignore_url_arr` 를 `[]` 로 두어 아무것도 걸러내지 않아도 이 두 요청은 보고서에 나타나지 않습니다.

---

## 설정 레퍼런스

모든 프레임워크가 다음 설정 옵션을 공통으로 씁니다:

| 설정 | 타입 | 기본값 | 설명 |
|--------|------|---------|-------------|
| `enable` | bool | `true` | 프로파일링 활성화/비활성화 |
| `time_limit` | int | `0` | n초를 초과한 요청만 프로파일링, 0은 전체 |
| `log_num` | int | `1000` | 최대 기록 수 |
| `view_wtred` | int | `3` | 응답 시간이 n초를 넘는 행을 빨간색으로 강조 |
| `ignore_url_arr` | array | `["/xhprof"]` | 무시할 URL 경로 |
| `assets_url` | string | `/xhprof-assets` | 정적 리소스 URL 접두사 |
| `auth_token` | string\|null | `null` | 설정하면 보고서 페이지에 `?token=xxx` 가 필요합니다. 공개 배포에 권장합니다 |
| `key_prefix` | string | `xhprof` | Redis 키 접두사. Redis를 공유할 때 프로젝트마다 다른 값을 설정하십시오 |
| `log_ttl` | int | `604800` | 데이터 보존 기간(초), 기본 7일 |

각 프레임워크에서 이 옵션들의 알려진 제한은 [검증과 알려진 제한 사항](#검증과-알려진-제한-사항)에 정리되어 있습니다.

---

## 수동 초기화

프레임워크 자동 감지가 실패하면 어댑터를 직접 주입할 수 있습니다:

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

**신규 6개 프레임워크(Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal)는 인자 없는 `Xhprof::bootstrap()` 을 호출하면 안 됩니다**. 인자가 없으면 `autoDetect()` 를 거치는데, 이 함수는 webman / Laravel / ThinkPHP / Hyperf 분기만 알고 있어 이 여섯 프레임워크에서는 `Unsupported framework` 를 던집니다. 위 예시처럼 5개 어댑터를 모두 명시적으로 넘기십시오(각 프레임워크에 포함된 진입 클래스가 이미 이렇게 하고 있습니다).

---

## 아키텍처와 설계

Core는 정확히 5개의 계약을 통해 프레임워크에 접근하며, 모두 `src/Core/Contract/` 에 있습니다:

| 계약 | 메서드 | 용도 |
|----------|---------|---------|
| `RequestInterface` | `get()` `all()` `method()` `header()` `host()` `uri()` `url()` `getRealIp()` | 요청 데이터 읽기, 보고서 경로 매칭, 보고서 페이지 링크 생성 |
| `ResponseInterface` | `withBody()` `withHeaders()` `withStatus()` `file()` `send()` | 보고서 페이지, 정적 리소스, 400/403 전송 |
| `ConfigInterface` | `get()` | 플러그인 설정 읽기: `get('xhprof')` 는 블록 전체, `get('xhprof.assets_url')` 은 개별 값 |
| `CacheInterface` | `get()` `set()` `mget()` `incr()` `lPush()` `rPop()` `lRange()` `del()` `decr()` | Redis 읽기와 쓰기 |
| `LoggerInterface` | `error()` | 확장 누락과 저장 실패에 대한 경고 |

각 프레임워크는 이 계약들을 구현한 어댑터 5개를 제공하고, `Xhprof::bootstrap()` 이 이를 Core에 등록합니다. 프레임워크별 코드는 모두 해당 프레임워크의 `src/<Fw>/` 디렉터리 안에 남습니다.

**Core에 남은 프레임워크 결합은 두 곳뿐입니다**:

1. `Xhprof::autoDetect()` 의 `class_exists()` 체인 (`Webman\App` → `Illuminate\Foundation\Application` → `think\App` → `Hyperf\Context\ApplicationContext`)이며, 인자 없는 `bootstrap()` 으로만 도달합니다.
2. 하드코딩된 Hyperf 코루틴 전환: `Xhprof::markHyperfContext()` 와 `\Hyperf\Context\Context` 존재 여부 검사로, 어댑터를 프로세스 전역 정적 프로퍼티에 넣을지 코루틴 Context에 넣을지 결정합니다.

**신규 6개 프레임워크는 `autoDetect()` 를 거치지 않고 모두 명시적 주입을 씁니다**: 각 진입 클래스가 자체적으로 어댑터 5개를 만들어 `Xhprof::bootstrap($req, $res, $cfg, $cache, $log)` 에 넘깁니다. PSR-7 프레임워크에서는 Request/Response를 요청 파이프라인에서만 얻을 수 있어 인자 없는 `bootstrap()` 은 구조적으로 동작할 수 없고, 덕분에 `autoDetect()` 는 현재의 네 프레임워크에 그대로 고정됩니다.

![아키텍처](docs/images/architecture.svg)

첫 번째 그림은 **구조**입니다. 열 프레임워크 각각의 진입 클래스, 5개 계약, Core의 세 계층, 그리고 남은 결합 두 곳을 보여 줍니다.

![설계 근거](docs/images/design.svg)

두 번째 그림은 **근거**입니다. "신규 6개 프레임워크의 `src/Core/` 변경 = 0" 을 머리에 두고 다섯 가지 트레이드오프를 결정 / 이유 / 비용으로 정리했습니다.

---

## 요청 수명 주기

프로파일링되는 요청 하나:

1. **프로파일링이 시작되기 전에** 진입 클래스가 경로를 확인합니다. 보고서 경로에 맞으면 보고서 페이지를 즉시 반환하고, 리소스 경로에 맞으면 정적 리소스를 즉시 반환합니다. 어느 쪽도 프로파일링되지 않고 아래 흐름에 들어가지 않습니다.
2. `XhprofProfiler::isEnabled()` 가 설정에서 `enable` 을 읽습니다. 프로파일링이 꺼져 있거나 확장이 없으면 블록 전체를 건너뜁니다.
3. `Xhprof::xhprofStart()` → `XhprofProfiler::start()` → `xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY)`.
4. 비즈니스 로직이 실행됩니다.
5. `finally { Xhprof::xhprofStop(); }` → `xhprof_disable()`, 이어서 `XHProfRunsDefault::save_run()` 이 Redis에 기록합니다. 단순 문장이 아니라 `finally` 를 쓰는 이유는, 예외가 던져져도 프로파일링 상태를 정리하고 실행 결과를 저장하기 위해서입니다.
6. 브라우저가 보고서 페이지를 엽니다. `Xhprof::index()` 가 Redis에서 데이터를 다시 읽어 렌더링합니다.

![수명 주기](docs/images/lifecycle.svg)

| 프레임워크 | 프로파일링 시작 | 프로파일링 종료 |
|-----------|-----------------|----------------|
| webman / Laravel / ThinkPHP / Hyperf | 미들웨어 진입 (`process()` / `handle()`) | `finally` |
| Yii3 / Slim 4 | PSR-15 `process()` | `finally` |
| Symfony | `kernel.request` (우선순위 10000) | `kernel.response` (우선순위 -10000), 그리고 shutdown 폴백 |
| WordPress | `plugins_loaded` | `shutdown` |
| Joomla | `onAfterInitialise` | `onAfterRespond`, 그리고 shutdown 폴백 |
| Drupal | `http_middleware` (우선순위 1000, 최외곽) | `finally` |

---

## 프로젝트 구조

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

Drupal을 제외하면 모든 `src/<Fw>/` 디렉터리가 같은 모양입니다:

```
src/<Fw>/
├── Adapter/{Request,Response,Config,Redis,Log}Adapter.php
├── <EntryClass>.php
└── config/xhprof.php             # the same 9 config keys as every other framework
```

`src/Drupal/` 이 유일한 예외입니다. `config/` 디렉터리가 없고, 설정은 모듈 수준의 타입 지정 config(`drupal/xhprof/config/install/xhprof.settings.yml`)에 있습니다.

---

## 검증과 알려진 제한 사항

**기계적으로 입증된 것**

| 항목 | 방법 |
|------|-----|
| 어댑터와 진입 배선 동작 | `tests/Unit/Adapter/*Test.php`: 활성화 → 저장 / 비활성화 → 저장 안 함 / 비즈니스 예외 → `finally` 로 여전히 저장 |
| 열 프레임워크가 하나의 설정 키 집합을 공유 | config parity 테스트(키 집합 기준이며 바이트 단위가 아님, 주석은 달라도 됨) |
| 두 README가 서로 대응 | README parity 테스트: `##` / `###` 제목 순서와 코드 블록 수를 비교 |
| 어댑터가 호출하는 메서드가 실제로 존재 | `tools/contracts/` 검증 루프(별도 CI 잡): 실제 프레임워크 패키지를 설치하고 리플렉션으로 모든 메서드 / 상수 / 전역 함수를 단언 |
| 어댑터의 의미 | 같은 루프가 실제 요청·응답 객체를 만들어 어댑터를 실행하며, 두 가지 불변식(`uri()` 에 스킴/호스트가 없을 것, `file()` 뒤에도 `withHeaders()` 가 적용될 것)까지 확인 |

위 표의 처음 세 줄, 즉 어댑터와 진입 배선 동작, 열 프레임워크가 공유하는 하나의 설정 키 집합, 두 README의 상호 대응은 **앞으로의 산출물**(신규 6개 프레임워크의 배선 케이스, config parity 테스트, README parity 테스트)을 가리키며, 이 문서를 쓰는 시점에는 아직 갖춰지지 않았습니다. 신규 6개 프레임워크에 대해서는 당분간 수동 스모크 체크리스트를 기준으로 삼으십시오.

**자동으로 검증되지 않은 것 ("여섯 개 모두 테스트했다"로 읽지 마십시오)**

| 항목 | 이유 |
|------|---------|
| 각 프레임워크의 **배선**(훅이 실제로 붙는지, 이벤트가 실제로 발생하는지) | 단위 테스트는 스텁을 쓰므로, 배선은 현재 수동 스모크 테스트로만 확인할 수 있습니다 |
| WordPress 종단 간 | 실제 `plugins_loaded` 시점, 치명적 오류에서 `shutdown` 이 발생하는지, mu-plugin이 로드되는지는 모두 실제 WordPress가 필요합니다 |
| Joomla 플러그인 발견과 `$app->close()` | 실제 Joomla 관리자에서 Discover를 실행해야 합니다 |
| Drupal의 우선순위가 실제로 페이지 캐시 바깥에 놓이는지 | 부팅된 Drupal 커널이 필요합니다 |
| Symfony의 `kernel.event_subscriber` 자동 구성 | 실제 컨테이너 컴파일이 필요합니다 |
| 장수명 프로세스에서의 정적 상태 간섭 | 기존 아키텍처에서 물려받은 문제입니다(Webman / Hyperf도 마찬가지). 이번에 바뀌지 않았습니다 |
| 실제 Redis I/O, 브라우저 렌더링, 실제 부하에서의 프로파일링 오버헤드 | 단위 테스트와 검증 루프의 범위 밖입니다 |

**수동 스모크 체크리스트 (프레임워크당 세 단계)**

| 단계 | 작업 | 기대 결과 |
|------|--------|----------|
| 1 | "프레임워크 설정"대로 진입 클래스를 마운트 | 오류 없음 |
| 2 | 애플리케이션 URL 아무거나 요청 | Redis의 `xhprof:run_id` 키 길이가 1 증가 |
| 3 | `/xhprof` 열기 | 보고서 페이지가 스타일과 함께 렌더링되고 `/xhprof-assets/js/xhprof_report.js` 가 200을 반환 |

**알려진 제한: `host()` 에 포트가 없음**

`host()` 계약은 "포트 없이 호스트만"을 뜻하지만, 보고서 목록의 링크는 `host() . uri()` 로 만듭니다(`src/Core/XhprofLib/Utils/XHProfRunsDefault.php`). 그래서 **비표준 포트(예: `:8080`)에서는 목록 링크에서 포트가 빠져 아무 데도 연결되지 않습니다**. 기존 구현에 잠재해 있던 문제로(webman / Laravel / ThinkPHP / Hyperf 모두 해당), 이번에 고치지 않았으며 알려진 제한으로 기록합니다.

**알려진 제한: `assets_url` 은 `/xhprof-assets` 일 때만 동작**

리소스 접두사는 `src/Core/StaticController.php` 에 하드코딩된 상수(`private const URI_PREFIX = '/xhprof-assets'`)인데, 보고서 페이지의 CSS/JS 링크는 `assets_url` 설정 옵션을 읽습니다(`src/Core/Xhprof.php`). 둘이 어긋나면 `getPathFromRequest()` 가 `null` 을 반환하고 `serve()` 가 `withBody('')->withHeaders([])` 를 반환합니다. 즉 **404가 아니라 본문이 빈 200 응답**입니다. 그 결과 `assets_url` 을 다른 값으로 두면 CSS/JS가 소리 없이 비어 버려, 아무런 오류도 없이 보고서 페이지가 스타일 없이 나옵니다. 다시 말해 `assets_url` 은 현재 기본값일 때만 동작하는 가짜 옵션입니다. 이 문제는 이전부터 있었고 이번에 고치지 않았습니다.

**알려진 제한: Drupal이 하위 디렉터리에 있으면 경로 가드가 실패**

Drupal을 하위 디렉터리(예: `/sites/app/xhprof`)에 설치하면 기본 경로가 붙은 URI를 경로 가드가 맞추지 못해, 동작이 "프로파일링은 되지만 저장되지 않음"으로 떨어집니다(기본 설정에서는 `ignore_url_arr` 이 이를 잡아냅니다). 하드코딩된 `assets_url` 접두사와 같은 종류의 제한입니다.

**Symfony 6.4 호환성**

Symfony 6.4 호환성은 실측으로 확인했습니다(7.4에서는 보이지 않던 과적합 두 가지를 이 과정에서 고쳤습니다: 6.4에서는 `Request` 프로퍼티에 네이티브 타입 선언이 없고, `prepare()` 가 붙이는 charset의 대소문자가 다릅니다). 다만 CI 검증 루프는 7.4만 실행합니다.

---

## 작성자

[erik](https://erik.xyz)

## 오픈 소스 후원

<p align="center">
  <img src="./docs/weixinpay.png" alt="WeChat Pay" width="130" height="130" title="WeChat Pay" />
  <img src="./docs/alipay.png" alt="Alipay" width="130" height="130" title="Alipay" />
</p>

---

이 플러그인은 [phacility/xhprof](https://github.com/phacility/xhprof) 와 [phpxxb/xhprof](https://github.com/xiexianbo123/xhprof) 를 참고했습니다.
