# XHProf パフォーマンスプロファイラ

webman / Laravel / ThinkPHP / Hyperf / Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal に対応したコードパフォーマンス計測プラグインです。

xhprof 拡張で計測データを収集し、Redis に保存します。開発者はブラウザからパフォーマンス解析レポートをすばやく確認でき、コードのパフォーマンスボトルネックを特定できます。

## 動作要件

- PHP >= 8.0
- xhprof 拡張
- redis 拡張
- Redis サーバー

## 対応フレームワークと最低バージョン

| フレームワーク | 最低バージョン | 最低 PHP | 入口クラス | 組み込み方 |
|-----------|----------------|-------------|-------------|--------------|
| webman | `workerman/webman ^2.1` | 8.0 | `Webman\XhprofMiddleware` | `config/middleware.php` にグローバルミドルウェアを登録 |
| Laravel | `laravel/framework ^9.0\|^10.0\|^11.0` | 8.0 | `Laravel\Middleware` | `app/Http/Kernel.php` にグローバルミドルウェアを登録 |
| ThinkPHP | `topthink/framework ^6.0\|^8.0` | 8.0 | `Thinkphp\Middleware` | `app/middleware.php` にグローバルミドルウェアを登録 |
| Hyperf | `hyperf/framework ^3.0` | 8.0 | `Hyperf\Middleware` | ConfigProvider 経由で自動登録 |
| Yii3 | `yiisoft/middleware-dispatcher ^5.0` | 8.1 | `Yii3\XhprofMiddleware` | `config/web/di/application.php` に登録。ミドルウェア一覧の先頭に置く必要がある |
| Symfony | `symfony/http-kernel ^6.4\|^7.0` | 8.1 (6.4) / 8.2 (7.x) | `Symfony\XhprofListener` | `config/services.yaml` に `kernel.event_subscriber` タグを追加 |
| Slim 4 | `slim/slim ^4.12` | 8.0 | `Slim\XhprofMiddleware` | `$app->add(...)`。最後に追加する必要がある |
| WordPress | 6.4+ | 8.0 | `Wordpress\XhprofPlugin` | `wp-content/mu-plugins/` にコピー |
| Joomla | 4.4 / 5.x | 8.1 | `Joomla\Extension\Xhprof` | `plugins/system/` にコピーし、Discover でインストール |
| Drupal | 10.x / 11.x | 8.1 (10.x) / 8.3 (11.x) | `xhprof` モジュール（`Drupal\XhprofMiddleware`） | 標準モジュール。有効化するだけ |

入口クラスはすべて `ErikWang2013\Xhprof\` 名前空間プレフィックスの下にあります（上の表では省略）。新規 6 フレームワークのうち Drupal だけが例外で、モジュールのルート経由でレポートページを配信します。残り 5 つの入口クラスは**レポートページを自前で配信**し、コントローラもルート登録も不要です。

本パッケージは `php >= 8.0` を宣言していますが、Yii3 が依存する `yiisoft/*` コンポーネントは **PHP 8.1 以上**を要求するため、**Yii3 は PHP 8.0 では使用できません**。同様に Symfony 7.x と Drupal 11.x もより高い PHP バージョンが必要です。手順の詳細は後述の「フレームワーク設定」にあります。

## インストール

php.ini に xhprof の設定を追加します。

```ini
[xhprof]
extension=xhprof.so
xhprof.output_dir=/tmp/xhprof
```

Composer でインストールします。

```sh
composer require aaron-dev/xhprof-webman
```

---

## フレームワーク設定

### Webman

**1. グローバルミドルウェアを登録** — `config/middleware.php`：

```php
return [
    '' => [
        ErikWang2013\Xhprof\Webman\XhprofMiddleware::class,
    ],
];
```

**2. コントローラを作成**：

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

**3. ルートを登録** — `config/route.php`：

```php
use Webman\Route;
use ErikWang2013\Xhprof\Webman\StaticController;

Route::get('/xhprof', [app\controller\XhprofController::class, 'index']);
Route::get('/xhprof-assets/{path:.+}', [StaticController::class, 'serve']);

```

**4. 設定** — `config/plugin/aaron-dev/xhprof/xhprof.php` を参照してください。

---

### Laravel

**1. ミドルウェアを登録** — `app/Http/Kernel.php`：

```php
protected $middleware = [
    // ...
    \ErikWang2013\Xhprof\Laravel\Middleware::class,
];
```

**2. コントローラを作成**：

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

**3. ルートを登録** — `routes/web.php`：

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

**4. 設定を publish**：

```sh
php artisan vendor:publish --tag=xhprof-config
```

設定ファイルは `config/xhprof.php` に出力されます。Laravel は ServiceProvider の自動検出に対応しています。

---

### ThinkPHP

**1. ミドルウェアを登録** — `app/middleware.php`：

```php
return [
    \ErikWang2013\Xhprof\Thinkphp\Middleware::class,
];
```

**2. コントローラを作成**：

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

**3. ルートを登録** — `route/app.php`：

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

**4. 設定** — `vendor/aaron-dev/xhprof-webman/src/Thinkphp/config/xhprof.php` をプロジェクトの `config/xhprof.php` にコピーしてください。

---

### Hyperf

**1. ミドルウェアの自動登録** — ConfigProvider が HTTP ミドルウェアキューにミドルウェアを自動追加します。

**2. コントローラを作成**：

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

**3. 静的アセットのルート** — `config/routes.php`：

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

**4. 設定を publish**：

```sh
php bin/hyperf.php vendor:publish aaron-dev/xhprof-webman
```

設定は `config/autoload/xhprof.php` に出力されます。

---

### Yii3

**1. ミドルウェアを登録** — `config/web/di/application.php`：

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

ありがちな間違いが 2 つあります（どちらも実測済み）。

- `'__construct()' => ['middlewares' => [...]]` と書いてはいけません。`MiddlewareDispatcher::__construct()` が受け取るのは `MiddlewareFactory` と省略可能な `EventDispatcherInterface` だけで、`middlewares` パラメータは**存在しません**。ミドルウェア一覧を注入できるのは**インスタンスメソッド** `withMiddlewares()` 経由だけです。
- `withMiddlewares()` にインスタンス（`new XhprofMiddleware(...)`）を入れてはいけません。定義が受け取れるのはクラス文字列、配列定義、callable のみです。インスタンスを渡すと登録時は何も報告されず、`dispatch()` で `TypeError` が投げられます（`MiddlewareFactory::create()` の型は `callable|array|string` です）。

**2. レポートページと静的アセット** — **コントローラもルート登録も不要**です。`XhprofMiddleware` は PSR-15 ミドルウェアです。計測開始前にリクエストパスを調べ、レポートパス `/xhprof` に命中すればレポートページを即座に返し、アセットパス（既定プレフィックス `/xhprof-assets`）に命中すれば静的アセットをそのまま返します。レポートページのレスポンスには入口クラスが明示的に `Content-Type: text/html; charset=UTF-8` を付けます。PSR-7 のレスポンスには既定値がなく、Yii3 のレスポンス送信側も付与しないため、これがないとブラウザは HTML レポートをプレーンテキストとして描画してしまいます。

**3. 設定** — 既定値はパッケージ内の `src/Yii3/config/xhprof.php` にあり、項目は「設定リファレンス」を参照してください。上書きするには DI 経由で `$config` を注入します。

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

`redis` サブ配列は Yii3 固有です。`CacheInterface` が注入されていない場合、ミドルウェアはこれを使って phpredis に直接接続します。`assets_url` は既定値のままにしてください。他のプレフィックスにすると、本文が空の 200 が返るだけです（Core 内のハードコード定数です。[検証と既知の制限](#検証と既知の制限)を参照）。

**4. バージョン要件** — Yii3 が依存する `yiisoft/*` コンポーネントは PHP >= 8.1 を要求します。本パッケージは `php >= 8.0` を宣言していますが、Yii3 連携は PHP 8.0 では使えません。

---

### Symfony

**1. イベントサブスクライバを登録** — `config/services.yaml`：

```yaml
services:
    ErikWang2013\Xhprof\Symfony\XhprofListener:
        tags:
            - { name: kernel.event_subscriber }
```

**2. レポートページと静的アセット** — **コントローラもルート登録も不要**です。計測開始前にリスナーがリクエストパスを調べ、レポートパス `/xhprof` に命中すればレポートページを即座に返し、アセットパス（既定プレフィックス `/xhprof-assets`）に命中すれば静的アセットをそのまま返します。

**3. 設定** — 既定値はパッケージ内の `src/Symfony/config/xhprof.php` にあり、項目は「設定リファレンス」を参照してください。

**4. サブリクエストと例外時のフォールバック** — リスナーは `kernel.request`（優先度 10000）と `kernel.response`（優先度 -10000）を購読します。`isMainRequest()` が ESI / フラグメントのサブリクエストを除外します。これがないと計測が早すぎるタイミングで止まってしまいます。またリクエスト開始時に冪等な `register_shutdown_function` も登録します。HttpKernel が例外を再スローすると `kernel.response` が発火せず、このフォールバックがないと計測状態が次のリクエストに漏れ出すためです。

---

### Slim 4

**1. ミドルウェアを登録** — `public/index.php`：

```php
use ErikWang2013\Xhprof\Slim\XhprofMiddleware;

$app->addRoutingMiddleware();

// must be added last: Slim's middleware stack is LIFO, added later = further out = runs first
$app->add(new XhprofMiddleware(
    $app->getResponseFactory()
));
```

残り 3 つのコンストラクタ引数はすべて省略可能で、省略するとパッケージ同梱の既定値が使われます。

- 引数 2 の `array $config`：設定配列。パッケージ同梱の `src/Slim/config/xhprof.php` に対して `array_replace` でマージされます（値ごとの置換であり、`ignore_url_arr` のようなリストのキーが再帰的にマージされることはありません）。
- 引数 3 の `CacheInterface $cache`：省略すると遅延で `new \Redis()` を実行します（コンストラクタは意図的に ext-redis に触れないため、アダプタ構築中に拡張がなくても落ちません）。自前の接続を注入するには `new \ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter($redis)`、または `ErikWang2013\Xhprof\Core\Contract\CacheInterface` を実装した任意のオブジェクトを渡します。
- 引数 4 の `LoggerInterface $logger`：省略すると `new \ErikWang2013\Xhprof\Slim\Adapter\LogAdapter()` になり、**ログは黙って捨てられます**（Slim は PSR-3 ロガーを同梱していません）。記録するには `new LogAdapter($psrLogger)` を渡します。`$psrLogger` は既にお持ちの PSR-3 ロガーです。

**`$app->add(XhprofMiddleware::class)` と書いてはいけません**。Slim の `CallableResolver` はそのクラス文字列を `new XhprofMiddleware($container)` に変換し、コンテナだけを渡したうえで解決をリクエスト時まで遅延させます。結果として `add()` は何も報告せず、最初のリクエストで `TypeError` が投げられます。最も原因を特定しにくい失敗の仕方です。必ず上記のように `new` を明示してください。

**2. レポートページと静的アセット** — **コントローラもルート登録も不要**です。計測開始前にミドルウェアがリクエストパスを調べ、レポートパス `/xhprof` に命中すればレポートページを即座に返し、アセットパス（既定プレフィックス `/xhprof-assets`）に命中すれば静的アセットをそのまま返します。

**3. 設定** — 既定値はパッケージ内の `src/Slim/config/xhprof.php` にあり、項目は「設定リファレンス」を参照してください。

**4. 登録順序** — Slim のミドルウェアスタックは LIFO です（ミドルウェア 2 つで実測したところ、実行順は `B:before → A:before → A:after → B:after`）。`add()` が後であるほど外側に位置し、より早く実行されます。したがって xhprof は**最後に**、かつ **`addRoutingMiddleware()` より後に**追加する必要があります。そうしないと `/xhprof` がルーティングテーブルに存在せず、RoutingMiddleware が先に `HttpNotFoundException` を投げ、リクエストがミドルウェアに到達しません。レポートページのレスポンスには入口クラスが明示的に `Content-Type: text/html; charset=UTF-8` を付けます。PSR-7 のレスポンスには既定値がなく、Slim の `ResponseEmitter` も付与しないため、これがないとブラウザは HTML レポートをプレーンテキストとして描画してしまいます。

---

### WordPress

**1. mu-plugin をインストール** — パッケージのブートストラップファイルを `wp-content/mu-plugins/` にコピーします。

```sh
cp vendor/aaron-dev/xhprof-webman/wordpress/xhprof-webman.php wp-content/mu-plugins/
```

`wordpress/xhprof-webman.php` にはプラグインヘッダが付いており、`Wordpress\XhprofPlugin` を起動します。mu-plugins は自動的に読み込まれるため、wp-admin で有効化する操作は不要です。

**2. レポートページと静的アセット** — **コントローラもルート登録も不要**です。計測開始前に入口クラスがリクエストパスを調べ、レポートパス `/xhprof` に命中すればレポートページを即座に返し、アセットパス（既定プレフィックス `/xhprof-assets`）に命中すれば静的アセットをそのまま返します。

**3. 設定** — 既定値はパッケージ内の `src/Wordpress/config/xhprof.php` にあり、項目は「設定リファレンス」を参照してください。`wp-cron.php` や `admin-ajax.php` のような高頻度パスは `ignore_url_arr` で除外してください。

**4. 計測区間の構造的な限界** — 区間は `plugins_loaded` → `shutdown` で、`wp-settings.php` の起動処理とプラグインの読み込み自体は**含まれません**。これは WordPress の構造的な限界で、この段階で行われる処理は計測できません。

---

### Joomla

**1. プラグインをインストール** — パッケージの `joomla/` ディレクトリをサイトの `plugins/system/xhprof/` にコピーします。

```sh
cp -r vendor/aaron-dev/xhprof-webman/joomla/ plugins/system/xhprof/
```

パッケージの `joomla/` ディレクトリがそのままプラグインです。`xhprof.xml` マニフェスト、`services/provider.php`、`src/Extension/Xhprof.php` が含まれます。入口クラスは `ErikWang2013\Xhprof\Joomla\Extension\Xhprof`（`CMSPlugin`）です。コピー後に「システム → 管理 → 拡張機能 → Discover」で Discover を実行し、インストールして有効化してください。

**2. レポートページと静的アセット** — **コントローラもルート登録も不要**です。計測開始前にプラグインがリクエストパスを調べ、レポートパス `/xhprof` に命中すればレポートページを即座に返し、アセットパス（既定プレフィックス `/xhprof-assets`）に命中すれば静的アセットをそのまま返します。

**3. 設定** — 既定値はパッケージ内の `src/Joomla/config/xhprof.php` にあり、項目は「設定リファレンス」を参照してください。**既知のトレードオフ**：プラグインパラメータではなくパッケージの設定ファイルを読みます。プラグインパラメータの取得にはデータベース読み取りが必要で、設定はリクエストごとに読まれるためです。

**4. 計測の境界** — 区間は `ApplicationEvents::AFTER_INITIALISE` → `ApplicationEvents::AFTER_RESPOND` です。加えて `AFTER_INITIALISE` で冪等な `register_shutdown_function` も登録します。例外経路では `AFTER_RESPOND` に到達することが保証されず、このフォールバックがないと計測状態が次のリクエストに漏れ出すためです。

---

### Drupal

**1. モジュールを有効化** — パッケージの `drupal/xhprof/` は標準的な Drupal モジュールです（`xhprof.info.yml` / `xhprof.routing.yml` / `xhprof.services.yml`）。サイトの `modules/custom/xhprof/` に配置し、「Extend」ページ（または `drush en xhprof`）で有効化してください。

**2. レポートページ** — Drupal は**10 フレームワーク中で唯一、モジュールのルート経由でレポートページを配信します**。`xhprof.routing.yml` がレポートパス `/xhprof` を登録し、モジュールのコントローラが描画します。他の新規 5 フレームワークはレポートページと静的アセットを自前で配信し、ルートを登録しません。

**3. 設定** — 設定はモジュールレベルの typed config です。既定値は `drupal/xhprof/config/install/xhprof.settings.yml` にあり、スキーマは `drupal/xhprof/config/schema/xhprof.schema.yml` にあります。項目は「設定リファレンス」を参照してください。

**4. ミドルウェアの登録** — モジュールの `xhprof.services.yml` にミドルウェアサービスを登録します。

```yaml
services:
  xhprof.http_middleware:
    class: ErikWang2013\Xhprof\Drupal\XhprofMiddleware
    arguments: ['@config.factory', '@logger.factory']
    tags:
      - { name: http_middleware, priority: 1000 }
```

内側のカーネルは Drupal の `StackedKernelPass` が**コンストラクタ引数 0 として自動的に先頭へ挿入します**。**自分で書いてはいけません**。書くと内側カーネルが 2 つになり、Drupal <= 11.2.x（10.x 系を含む）ではコンテナのコンパイル時に失敗し、11.3.0 以降ではリクエストごとに TypeError が投げられます。計測は `finally` で停止します。

**5. 注意点**

- `priority: 1000` はミドルウェアを**ページキャッシュの外側**に置きます（コアに既存の最高優先度は negotiation の 400（D10）/ 500（D11）で、ページキャッシュは 200 です）。したがって **Drupal のページキャッシュから返されるリクエストも計測されます**。計測ツールとしては意図した挙動ですが、利用者は知っておくべきです。
- キャッシュはすぐに動作します。ミドルウェアは既定で本パッケージ同梱の Redis アダプタを使い（本パッケージは ext-redis にハード依存しています）、`services.yml` の `arguments` で省略可能な `CacheInterface` 引数を渡して上書きもできます。キャッシュが使えない場合、保存の失敗は `XhprofProfiler::stop()` が 1 行のログに飲み込み、**エラーは発生しません**。
- レポートページ `/xhprof` と `/xhprof-assets/*` へのリクエストは**計測されません**。ミドルウェアが `xhprofStart()` より前にパスで計測対象外と判断するためです。レスポンス自体は `xhprof.routing.yml` の Controller が生成します（**これは短絡ではありません**）。そのため `ignore_url_arr` を `[]`（何も除外しない）にしても、この 2 つのリクエストがレポートに現れることはありません。

---

## 設定リファレンス

すべてのフレームワークが以下の設定項目を共有します。

| 設定 | 型 | 既定値 | 説明 |
|--------|------|---------|-------------|
| `enable` | bool | `true` | 計測の有効 / 無効 |
| `time_limit` | int | `0` | n 秒を超えたリクエストのみ計測。0 はすべて |
| `log_num` | int | `1000` | 最大記録件数 |
| `view_wtred` | int | `3` | レスポンスタイムが n 秒を超える行を赤で強調 |
| `ignore_url_arr` | array | `["/xhprof"]` | 無視する URL パス |
| `assets_url` | string | `/xhprof-assets` | 静的アセットの URL プレフィックス |
| `auth_token` | string\|null | `null` | 設定するとレポートページに `?token=xxx` が必要。公開環境での利用を推奨 |
| `key_prefix` | string | `xhprof` | Redis のキープレフィックス。1 つの Redis を共有する場合はプロジェクトごとに別の値を設定 |
| `log_ttl` | int | `604800` | データ保持期間（秒、既定 7 日） |

各設定項目のフレームワークごとの既知の制限は[検証と既知の制限](#検証と既知の制限)にまとめています。

---

## 手動初期化

フレームワークの自動判別が失敗する場合は、アダプタを手動で注入できます。

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

**新規 6 フレームワーク（Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal）は、引数なしの `Xhprof::bootstrap()` を呼んではいけません**。引数なしでは `autoDetect()` を通り、そこが知っているのは webman / Laravel / ThinkPHP / Hyperf の分岐だけで、この 6 つでは `Unsupported framework` が投げられます。上の例のように 5 つのアダプタをすべて明示的に渡してください（各フレームワークに同梱の入口クラスが既にそうしています）。

---

## アーキテクチャと設計

Core はフレームワークに、`src/Core/Contract/` にあるちょうど 5 つの契約を通じて到達します。

| 契約 | メソッド | 目的 |
|----------|---------|---------|
| `RequestInterface` | `get()` `all()` `method()` `header()` `host()` `uri()` `url()` `getRealIp()` | リクエストデータの読み取り、レポートパスの判定、レポートページのリンク生成 |
| `ResponseInterface` | `withBody()` `withHeaders()` `withStatus()` `file()` `send()` | レポートページ、静的アセット、400/403 の出力 |
| `ConfigInterface` | `get()` | プラグイン設定の読み取り。`get('xhprof')` でブロック全体、`get('xhprof.assets_url')` で個別の値 |
| `CacheInterface` | `get()` `set()` `mget()` `incr()` `lPush()` `rPop()` `lRange()` `del()` `decr()` | Redis の読み書き |
| `LoggerInterface` | `error()` | 拡張の欠落と保存失敗の警告 |

各フレームワークはこれらの契約を実装した 5 つのアダプタを提供し、`Xhprof::bootstrap()` がそれらを Core に登録します。フレームワーク固有のものはすべて、そのフレームワーク自身の `src/<Fw>/` ディレクトリ内に留まります。

**Core に残っているフレームワークとの結合は 2 箇所だけです**。

1. `Xhprof::autoDetect()` の `class_exists()` 連鎖（`Webman\App` → `Illuminate\Foundation\Application` → `think\App` → `Hyperf\Context\ApplicationContext`）。引数なしの `bootstrap()` からのみ到達します。
2. ハードコードされた Hyperf のコルーチン切り替え：`Xhprof::markHyperfContext()` と `\Hyperf\Context\Context` の存在チェックで、アダプタをプロセス全体の静的プロパティに置くかコルーチンの Context に置くかを決めます。

**新規 6 フレームワークは `autoDetect()` を通らず、すべて明示的注入を使います**。各入口クラスが自分で 5 つのアダプタを構築し、`Xhprof::bootstrap($req, $res, $cfg, $cache, $log)` に渡します。理由は、PSR-7 系フレームワークでは Request / Response がリクエストパイプラインからしか取得できないため、引数なしの `bootstrap()` は構造上動作しえないことです。副次的な利点として、`autoDetect()` は現在の 4 フレームワークのまま凍結できます。

![アーキテクチャ](docs/images/architecture.svg)

1 枚目は**構造**の図です。10 フレームワークそれぞれの入口クラス、5 つの契約、Core の 3 層、そして残った 2 箇所の結合を示します。

![設計のトレードオフ](docs/images/design.svg)

2 枚目は**根拠**の図です。5 つのトレードオフを判断 / 理由 / コストとして並べ、「新規 6 フレームワークによる `src/Core/` の変更 = 0」を掲げています。

---

## リクエストライフサイクル

計測対象リクエスト 1 件の流れ：

1. **計測を開始する前に**、入口クラスがパスを調べます。レポートパスに命中すればレポートページを即座に返し、アセットパスに命中すれば静的アセットを即座に返します。どちらのパスも計測されず、以下の流れにも入りません。
2. `XhprofProfiler::isEnabled()` が設定から `enable` を読みます。計測が無効か拡張が欠けていれば、ブロック全体がスキップされます。
3. `Xhprof::xhprofStart()` → `XhprofProfiler::start()` → `xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY)`。
4. 業務処理が実行されます。
5. `finally { Xhprof::xhprofStop(); }` → `xhprof_disable()`、続いて `XHProfRunsDefault::save_run()` が Redis に書き込みます。単純な文ではなく `finally` なのは、例外が投げられても計測状態が確実に解除され、run が保存されるようにするためです。
6. ブラウザがレポートページを開きます。`Xhprof::index()` が Redis からデータを読み戻して描画します。

![ライフサイクル](docs/images/lifecycle.svg)

| フレームワーク | 計測開始 | 計測終了 |
|-----------|-----------------|----------------|
| webman / Laravel / ThinkPHP / Hyperf | ミドルウェア入口（`process()` / `handle()`） | `finally` |
| Yii3 / Slim 4 | PSR-15 の `process()` | `finally` |
| Symfony | `kernel.request`（優先度 10000） | `kernel.response`（優先度 -10000）、加えて shutdown フォールバック |
| WordPress | `plugins_loaded` | `shutdown` |
| Joomla | `onAfterInitialise` | `onAfterRespond`、加えて shutdown フォールバック |
| Drupal | `http_middleware`（優先度 1000、最外層） | `finally` |

---

## プロジェクト構成

```
xhprof-webman/
├── src/
│   ├── Core/                     # フレームワーク非依存：契約、レポートページ、Redis 保存、アセット
│   │   ├── Contract/             # 5 つの契約インターフェース
│   │   ├── XhprofLib/            # レポート描画と run 保存（phacility/xhprof 由来）
│   │   ├── Xhprof.php            # 静的ファサード：bootstrap() / index()
│   │   ├── XhprofProfiler.php    # xhprof_enable/disable と設定
│   │   ├── StaticController.php  # /xhprof-assets の静的アセット
│   │   ├── MiddlewareTrait.php   # Laravel / ThinkPHP 共通の計測ラッパー
│   │   └── RedisAdapterTrait.php # 各フレームワーク Redis アダプタの共通実装
│   ├── Webman/ Laravel/ Thinkphp/ Hyperf/            # 既存の 4 フレームワーク
│   ├── Yii3/ Symfony/ Slim/ Wordpress/ Joomla/ Drupal/   # 新規 6 フレームワーク
│   └── html/                     # レポートページのアセット（css / js / images）
├── wordpress/                    # mu-plugin ブートストラップファイル（プラグインヘッダ付き）
├── joomla/                       # Joomla プラグイン（CMSPlugin + マニフェスト）
├── drupal/xhprof/                # 標準 Drupal モジュール（info / routing / services + Controller）
├── tools/contracts/              # 独立検証ループ：実フレームワークパッケージに対するシグネチャとセマンティクスの検証
├── tests/                        # PHPUnit：アダプタ、配線、README の一致テスト
└── docs/images/                  # README の図
```

Drupal を除き、すべての `src/<Fw>/` ディレクトリは同じ形です。

```
src/<Fw>/
├── Adapter/{Request,Response,Config,Redis,Log}Adapter.php
├── <EntryClass>.php
└── config/xhprof.php             # 他のフレームワークと同じ 9 つの設定キー
```

`src/Drupal/` だけが例外です。`config/` ディレクトリを持たず、設定はモジュールレベルの typed config（`drupal/xhprof/config/install/xhprof.settings.yml`）にあります。

---

## 検証と既知の制限

**機械的に証明されていること**

| 項目 | 方法 |
|------|-----|
| アダプタと入口の配線の挙動 | `tests/Unit/Adapter/*Test.php`：有効 → 保存 / 無効 → 保存しない / 業務例外 → `finally` 経由で保存される |
| 10 フレームワークが 1 つの設定キー集合を共有 | 設定パリティテスト（キー集合のみで、バイト単位の一致は見ない。コメントは差異を許容） |
| 2 つの README が対応している | README パリティテスト：`##` / `###` の見出し列とコードブロック数を比較 |
| アダプタが呼ぶメソッドが実在する | `tools/contracts/` の検証ループ（専用の CI ジョブ）：実フレームワークパッケージをインストールし、すべてのメソッド / 定数 / グローバル関数をリフレクションで検証 |
| アダプタのセマンティクス | 同じループが実際の request / response オブジェクトを生成してアダプタを走らせる。`uri()` がスキームとホストを含まないこと、`file()` の後でも `withHeaders()` が適用されることの 2 つの不変条件を含む |

上記の最初の 3 行 —— アダプタと入口の配線の挙動、10 フレームワークが 1 つの設定キー集合を共有すること、2 つの README が対応していること —— は**今後の成果物**（6 フレームワークの配線ケース、設定パリティテスト、README パリティテスト）を指しており、本文書の時点では未整備です。新規 6 フレームワークについては、当面は手動スモークチェックリストを正としてください。

**自動検証されていないこと（「6 つとも検証済み」と読まないでください）**

| 項目 | 理由 |
|------|---------|
| 各フレームワークの**配線**（フックが本当に付いているか、イベントが本当に発火するか） | 単体テストはスタブを使う。配線は現状、手動スモークテストでしか確認できない |
| WordPress のエンドツーエンド | 実際の `plugins_loaded` のタイミング、致命的エラーで `shutdown` が発火するか、mu-plugin が読み込まれるかは、実物の WordPress が必要 |
| Joomla のプラグイン検出と `$app->close()` | 実物の Joomla 管理画面で Discover を実行する必要がある |
| Drupal の優先度が本当にページキャッシュの外側に来るか | 起動済みの Drupal カーネルが必要 |
| Symfony の `kernel.event_subscriber` 自動設定 | 実際のコンテナコンパイルが必要 |
| 長時間稼働プロセスでの静的状態の混線 | 既存アーキテクチャから引き継いだもの（Webman / Hyperf でも同様）。本件では未変更 |
| 実際の Redis I/O、ブラウザ描画、実負荷での計測オーバーヘッド | 単体テストと検証ループの範囲外 |

**手動スモークチェックリスト（フレームワークごとに 3 ステップ）**

| 手順 | 操作 | 期待結果 |
|------|--------|----------|
| 1 | 「フレームワーク設定」のとおりに入口クラスを組み込む | エラーが出ない |
| 2 | アプリの任意の URL にアクセスする | Redis の `xhprof:run_id` キーの長さが 1 増える |
| 3 | `/xhprof` を開く | レポートページがスタイル付きで描画される。`/xhprof-assets/js/xhprof_report.js` が 200 を返す |

**既知の制限：`host()` にポートがない**

`host()` 契約は「ホストのみ、ポートを含まない」という意味ですが、レポート一覧のリンクは `host() . uri()` として組み立てられます（`src/Core/XhprofLib/Utils/XHProfRunsDefault.php`）。そのため**非標準ポート（例：`:8080`）では一覧のリンクがポートを失い、どこにも繋がりません**。これは既存実装に潜在する問題で（webman / Laravel / ThinkPHP / Hyperf にも等しく影響します）、本件では修正せず、既知の制限として記録します。

**既知の制限：`assets_url` は `/xhprof-assets` のときだけ機能する**

アセットのプレフィックスは `src/Core/StaticController.php` のハードコード定数（`private const URI_PREFIX = '/xhprof-assets'`）である一方、レポートページの CSS / JS リンクは `assets_url` 設定を読みます（`src/Core/Xhprof.php`）。両者が一致しなくなると `getPathFromRequest()` が `null` を返し、`serve()` は `withBody('')->withHeaders([])` を返します —— **404 ではなく、本文が空の 200 です**。結果として、`assets_url` を他の値にすると CSS / JS が黙って空になり、レポートページはスタイルを失いますが、エラーは一切出ません。言い換えると `assets_url` は現状、既定値のままのときだけ機能する見せかけのオプションです。これは既存の問題で、本件では修正していません。

**既知の制限：Drupal がサブディレクトリにあるとパス判定が効かない**

Drupal がサブディレクトリ（例：`/sites/app/xhprof`）にインストールされている場合、パス判定がベースパスを含む URI に一致できないため、挙動は「計測するが保存されない」に落ちます（既定設定では `ignore_url_arr` が拾います）。これはハードコードされた `assets_url` プレフィックスと同じ種類の制限です。

**Symfony 6.4 互換性**

Symfony 6.4 互換性は実測済みです（7.4 では見えない 2 つの過剰適合がこれで修正されました：6.4 では `Request` のプロパティにネイティブの型宣言がなく、`prepare()` が付ける charset は大文字小文字が異なります）。ただし CI の検証ループは 7.4 しか実行していません。

---

## 作者

[erik](https://erik.xyz)

## オープンソースを支援

<p align="center">
  <img src="./docs/weixinpay.png" alt="WeChat Pay" width="130" height="130" title="WeChat Pay" />
  <img src="./docs/alipay.png" alt="Alipay" width="130" height="130" title="Alipay" />
</p>

---

本プラグインは [phacility/xhprof](https://github.com/phacility/xhprof) と [phpxxb/xhprof](https://github.com/xiexianbo123/xhprof) を参考にしています。
