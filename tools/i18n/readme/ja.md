# XHProf パフォーマンスプロファイラ

![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.0-777bb4) ![CI](https://github.com/erikwang2013/xhprof-webman/actions/workflows/ci.yml/badge.svg) ![Release](https://img.shields.io/github/v/release/erikwang2013/xhprof-webman) ![License](https://img.shields.io/badge/license-MIT-blue)

webman / Laravel / ThinkPHP / Hyperf / Yii2 / Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal および素の PHP（フレームワークなし）に対応したコードパフォーマンス計測プラグインです。

xhprof 拡張で計測データを収集し、Redis に保存します。開発者はブラウザからパフォーマンス解析レポートをすばやく確認でき、コードのパフォーマンスボトルネックを特定できます。

![プロジェクトのペット：小さな炎](docs/images/pet.svg)

同じ小さな炎は、レポートページのサイトアイコン、左上のブランドアイコン、表の並べ替えアイコンでもあります（`src/html/pet.svg`、`src/html/images/sort_*.svg`、`assets_url` プレフィックスで配信）。

**リクエスト記録**

![リクエスト記録](docs/i18n/ja/images/runs-list.png)

**単一実行のレポート**

![単一実行のレポート](docs/i18n/ja/images/run-report.png)

**2 つの実行の比較** — 「リクエスト記録」の一覧でちょうど 2 行にチェックを入れ（チェックボックスは各行に 1 つ、表ヘッダーで全選択）、「選択項目を比較」をクリックすると diff ビューが開きます。左右は時刻で決まり（run1 = 早いほう、run2 = 遅いほう。一覧の現在の並び順とは関係ありません）、色は「run1 から run2 へ」の改善 / 回帰を意味します。ページ内の「差分レポートを反転」リンクで、いつでも左右を入れ替えられます。

**機械消費向けのエクスポート** — レポートページの操作バーが JSON / CSV エクスポートを提供します（単一実行・比較・集約の 3 ビューすべてで可）；`?format=json` に `run` パラメータを付けなければ**実行一覧の JSON** を返します（各項目に run_id・リクエストメタデータ・一覧口径のヘッダー情報を含みます）。監視スクリプトやダッシュボードは、run_id を取るのに HTML を解析する必要がもうありません。`symbol=` 付きのエクスポート要求は 400 を返します — エクスポートに単一関数ビューはなく、黙って全量のフラットテーブルを返すのはエラーより悪いためです。

## 動作要件

- PHP >= 8.0
- xhprof 拡張
- redis 拡張
- Redis サーバー

## 対応フレームワークと最低バージョン

| フレームワーク | 最低バージョン | 最低 PHP | 入口クラス | 組み込み方 |
|-----------|----------------|-------------|-------------|--------------|
| webman | `workerman/webman ^2.1` | 8.0 | `Webman\XhprofMiddleware` | `config/middleware.php` にグローバルミドルウェアを登録 |
| Laravel | `laravel/framework ^9.0\|^10.0\|^11.0\|^12.0\|^13.0` | 8.0 | `Laravel\Middleware` | 11 以降は `bootstrap/app.php` の `->withMiddleware()` で追加；10 以下は `app/Http/Kernel.php` にグローバルミドルウェアを登録 |
| ThinkPHP | `topthink/framework ^6.0\|^8.0` | 8.0 | `Thinkphp\Middleware` | `app/middleware.php` にグローバルミドルウェアを登録 |
| Hyperf | `hyperf/framework ^3.0` | 8.0 | `Hyperf\Middleware` | ConfigProvider 経由で自動登録 |
| Yii3 | `yiisoft/middleware-dispatcher ^5.0` | 8.1 | `Yii3\XhprofMiddleware` | `config/web/di/application.php` に登録。ミドルウェア一覧の先頭に置く必要がある |
| Symfony | `symfony/http-kernel ^6.4\|^7.0\|^8.0` | 8.1 (6.4) / 8.2 (7.x) / 8.4 (8.x) | `Symfony\XhprofListener` | `config/services.yaml` に `kernel.event_subscriber` タグを追加 |
| Slim 4 | `slim/slim ^4.12` | 8.0 | `Slim\XhprofMiddleware` | `$app->add(...)`。最後に追加する必要がある |
| WordPress | 6.4+ | 8.0 | `Wordpress\XhprofPlugin` | `wp-content/mu-plugins/` にコピー |
| Joomla | 4.4 / 5.x | 8.1 | `Joomla\Extension\Xhprof` | `plugins/system/` にコピーし、Discover でインストール |
| Drupal | 10.x / 11.x | 8.1 (10.x) / 8.3 (11.x) | `xhprof` モジュール（`Drupal\XhprofMiddleware`） | 標準モジュール。有効化するだけ |
| 素の PHP（フレームワークなし） | —（外部パッケージなし） | 8.0 | `Native\XhprofBootstrap` | 入口ファイルの先頭に 1 行 `XhprofBootstrap::start()` を書くだけ。コントローラもルート登録も不要 |
| Yii2 | `yiisoft/yii2 ^2.0` | 8.0 | `Yii2\XhprofBootstrap` | `config/web.php` の `bootstrap` 配列に登録。コントローラもルート登録も不要 |

入口クラスはすべて `ErikWang2013\Xhprof\` 名前空間プレフィックスの下にあります（上の表では省略）。12 のうちどれも、コントローラやルートの登録を求めてきません。レポートページと静的アセットは入口クラス自身が配信します（Drupal の場合はモジュールのルート）。

本パッケージは `php >= 8.0` を宣言していますが、Yii3 が依存する `yiisoft/*` コンポーネントは **PHP 8.1 以上**を要求するため、**Yii3 は PHP 8.0 では使用できません**。同様に Symfony 7.x と Drupal 11.x もより高い PHP バージョンが必要です。手順の詳細は後述の「フレームワーク設定」にあります。

## インストール

xhprof 拡張を PECL からインストールします（PHP 8 時点では 2.3.x）：

```sh
pecl install xhprof
```

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

### クイックスタート

最短の手順は 3 ステップです：

1. **拡張をインストール** — `pecl install xhprof` を実行し、php.ini に `[xhprof]` セクションを追加します（`extension=xhprof.so`、`xhprof.output_dir=/tmp/xhprof`）。
2. **Redis を起動** — `redis-server --daemonize yes` を実行するか、既存のインスタンスを使います（接続設定は各フレームワークの `config/xhprof.php` の `redis` サブ配列にあります）。
3. **接続してレポートページを開く** — `composer require aaron-dev/xhprof-webman` を実行し、いずれか 1 つのフレームワークで「フレームワーク設定」に従って入口クラスを組み込み、業務リクエストを 1 回発生させてから `http://<あなたのサイト>/xhprof` を開きます。

> **環境を入れたくない？** `demo/` には docker compose で即動くデモが入っています（素の PHP 入口で、フレームワーク不要）：`cd demo && docker compose up -d` を実行して `http://127.0.0.1:8080/xhprof` を開けば、実際のレポートページが見られます。説明は `demo/README.md` にあります。

### トラブルシューティング

| 症状 | まず確認すること |
|------|-------------|
| レポートページが白紙で、一覧に記録がない | `enable` が `true` か。`sample_rate` を `0` にしていないか（その場合、`X-Xhprof-Token` ヘッダー付きのリクエストだけがサンプリングされます）。Redis の `<key_prefix>:run_id` が空でないか |
| レポートページが 403 / 401 を返す | 403：`ip_allowlist` が現在の IP を遮断している（またはリクエスト IP が転送ヘッダー由来なのに `trusted_proxies` が空）、もしくは `auth_token` を設定しているのに URL に `?token=` がない；401 でブラウザーの資格情報ダイアログが出る：`auth_basic` を設定していて、入力したユーザー名 / パスワードが設定と一致しない |
| Redis に接続できないというエラー | redis 拡張がインストールされているか（`php -m` の出力に `redis` があるか）、Redis が起動しているか、`redis` サブ配列の host / port / password / database がインスタンスと一致しているか |
| 拡張はインストールしたのに業務リクエストが保存されない | 入口クラスが本当に組み込まれているか（「フレームワーク設定」参照）。リクエストパスが `ignore_url_arr` に該当しないか。`max_runs_per_minute` が上限に達していないか（超えると次の 1 分までサンプリングされません） |
| レポートページは開くのに CSS / JS が 404 になる | `assets_url` のプレフィックスがデプロイ先のパスと一致しているか。リバースプロキシがそのプレフィックスもアプリケーションへ転送しているか |

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

**2. レポートページと静的アセット** — **コントローラもルート登録も不要です**。計測が始まる前にミドルウェアがリクエストパスを判定し、レポートパス `/xhprof` に一致すればレポートページをそのまま返し、アセットパス（プレフィックスは `assets_url` 設定から読み取り、既定は `/xhprof-assets`）に一致すれば静的アセットを直接返します。

**3. 設定** — `config/plugin/aaron-dev/xhprof/xhprof.php` を参照してください。

---

### Laravel

**1. ミドルウェアを登録** — Laravel 11 以降（slim skeleton にはもう `app/Http/Kernel.php` がありません）は `bootstrap/app.php` に：

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\ErikWang2013\Xhprof\Laravel\Middleware::class);
})
```

Laravel 10 以下では従来どおり `app/Http/Kernel.php` の `protected $middleware` 配列に `\ErikWang2013\Xhprof\Laravel\Middleware::class` を追加します。

**2. レポートページと静的アセット** — **コントローラもルート登録も不要です**。計測が始まる前にミドルウェアがリクエストパスを判定し、レポートパス `/xhprof` に一致すればレポートページをそのまま返し、アセットパス（プレフィックスは `assets_url` 設定から読み取り、既定は `/xhprof-assets`）に一致すれば静的アセットを直接返します。

**3. 設定を publish**：

```sh
php artisan vendor:publish --tag=xhprof-config
```

設定ファイルは `config/xhprof.php` に出力されます。Laravel は ServiceProvider の自動検出に対応しています。

**4. CLI とキュー**（必要なときは先に `sample_cli` をオンに。既定はオフ）— HTTP リクエストを持たない 2 種類の入口はどちらもサンプリングウィンドウ内で走ります：

- **キューの worker**：`AppServiceProvider::boot()` で 4 つのイベントをパッケージ内のリスナーに接続し、メッセージごとに開閉します（常駐 worker でも漏れません）：

```php
use ErikWang2013\Xhprof\Laravel\XhprofQueueListener;
use Illuminate\Queue\Events\{JobProcessing, JobProcessed, JobFailed, JobExceptionOccurred};

Event::listen(JobProcessing::class, [XhprofQueueListener::class, 'onJobProcessing']);
Event::listen(JobProcessed::class, [XhprofQueueListener::class, 'onJobProcessed']);
Event::listen(JobFailed::class, [XhprofQueueListener::class, 'onJobFailed']);
Event::listen(JobExceptionOccurred::class, [XhprofQueueListener::class, 'onJobExceptionOccurred']);
```

- **artisan コマンド**：`xhprof:profile` はパッケージが自動登録します（Laravel の ServiceProvider 自動検出）。そのまま使えます：

```sh
php artisan xhprof:profile "migrate --force"
```

- **自前のスクリプト / 定期タスク**：artisan 以外の入口（自作 PHP スクリプト、クロージャタスク）はタスク本体を外側から包みます：

```php
\ErikWang2013\Xhprof\Laravel\XhprofCli::start();
try { /* your logic */ } finally { \ErikWang2013\Xhprof\Laravel\XhprofCli::stop(); }
```

ウィンドウはタスクごとに開閉し（同期ディスパッチされた子タスクはネストするため、最外殻の 1 件だけが記録されます）、保存される実行の `request_uri` は `cli:<スクリプト名>` として記録されます。

---

### ThinkPHP

**1. ミドルウェアを登録** — `app/middleware.php`：

```php
return [
    \ErikWang2013\Xhprof\Thinkphp\Middleware::class,
];
```

**2. レポートページと静的アセット** — **コントローラもルート登録も不要です**。計測が始まる前にミドルウェアがリクエストパスを判定し、レポートパス `/xhprof` に一致すればレポートページをそのまま返し、アセットパス（プレフィックスは `assets_url` 設定から読み取り、既定は `/xhprof-assets`）に一致すれば静的アセットを直接返します。

**3. 設定** — `vendor/aaron-dev/xhprof-webman/src/Thinkphp/config/xhprof.php` をプロジェクトの `config/xhprof.php` にコピーしてください。

---

### Hyperf

**1. ミドルウェアの自動登録** — ConfigProvider が HTTP ミドルウェアキューにミドルウェアを自動追加します。

**2. レポートページと静的アセット** — **コントローラもルート登録も不要です**。計測が始まる前にミドルウェアがリクエストパスを判定し、レポートパス `/xhprof` に一致すればレポートページをそのまま返し、アセットパス（プレフィックスは `assets_url` 設定から読み取り、既定は `/xhprof-assets`）に一致すれば静的アセットを直接返します。

**3. 設定を publish**：

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

`redis` サブ配列は Yii3 固有です。`CacheInterface` が注入されていない場合、ミドルウェアはこれを使って phpredis に直接接続します。`assets_url` は任意のプレフィックスに変更できます。レポートページの CSS / JS リンクと `StaticController` が同じ設定項目を読みます（既定は `/xhprof-assets`）。サブディレクトリ配置での残りの制限は[検証と既知の制限](#検証と既知の制限)を参照してください。

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

設定（Redis アドレス、`auth_token` など）を上書きするには、`wp-config.php` で定数を定義します（mu-plugin の読み込み時点で、この定数は既に利用可能です）：

```php
define('XHPROF_WEBMAN_CONFIG', [
    'auth_token' => 'your-token',
    'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'password' => '', 'database' => 0],
]);
```

別の方法として、（テーマまたはプラグインから）`xhprof_webman_config` フィルターをフックします：これは定数の上に適用され、配列の形は同じです。

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

**2. レポートページと静的アセット** — Drupal は**12 フレームワーク中で唯一「モジュール + ルート」の形をとります**。`xhprof.routing.yml` がレポートパス `/xhprof` とアセットパス `/xhprof-assets` を登録し、既定ではモジュールのコントローラが配信します。残り 11 の入口クラスは計測開始前に自前でショートサーキットしてレポートページと静的アセットを配信し、ルートを登録しません。**`assets_url` を独自プレフィックスにするとアセットはミドルウェアが配信します**: モジュールのアセットルートの path は `xhprof.routing.yml` に固定されており（`/xhprof-assets/{file}`）、別のプレフィックスには決して一致しません。

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

### 素の PHP（フレームワークなし）

フレームワークを使わず、フロントコントローラが 1 つだけのアプリ（`public/index.php` など）向けです。

**1. 入口ファイルの先頭に 1 行追加します**:

```php
\ErikWang2013\Xhprof\Native\XhprofBootstrap::start();
```

設定を変える場合はこの行に配列を渡します（キー集合は他の 11 と同一、既定値は `src/Native/config/xhprof.php`）:

```php
\ErikWang2013\Xhprof\Native\XhprofBootstrap::start([
    'enable' => true,
    'auth_token' => 'xxx',
]);
```

第 2・第 3 引数は任意の注入点です: `CacheInterface $cache` と `LoggerInterface $logger`（既定は本パッケージの Redis アダプタと `error_log`）。戻り値はこのリクエストの入口インスタンス（`stop()` は冪等）で、同一プロセス内で早めに止めたいときに呼びます。

**2. レポートページと静的アセット** — **コントローラもルート登録も不要です**。この行が計測開始前にリクエストパスを判定し、レポートパス `/xhprof` に一致すればレポートページをそのまま返し（`Content-Type: text/html; charset=UTF-8` と `Cache-Control: no-cache, private` 付き、`auth_token` も従来どおり効きます）、アセットパス（プレフィックスは `assets_url` 設定から読み取り、既定は `/xhprof-assets`）に一致すれば静的アセットを直接返します。**代償は明記します**: この 2 つのパスを処理した時点で `exit` するため、そのリクエストの残り（この行より後のルート、コンテナの起動、セッション開始、アプリ自身が登録した終了処理）は実行されません。

**3. 計測ウィンドウ = この行 → プロセス shutdown**（登録されるのは `register_shutdown_function`）。**境界はそのまま書きます**: この行より**前**のコード（composer autoload、フロントコントローラの起動）は含まず、別プロセスや拡張の処理（php-fpm のリクエスト解析、nginx 側の処理）も含みません。正常終了・`exit`・捕捉されなかった Error / 例外はいずれも終点に到達しますが、`SIGKILL` / OOM killer は到達しません — 計測状態はプロセスとともに消え、次のリクエストに残りません。範囲を狭めるには `ignore_url_arr` 設定（`uri()` への部分文字列一致、コード変更なしで有効）を使います。

**4. `php -S` で実際に 1 回動かします**:

```sh
# public/index.php の先頭に XhprofBootstrap::start() があり、このファイルがフロントコントローラです
php -S 127.0.0.1:8000 -t public public/index.php
```

`http://127.0.0.1:8000/` にアクセスしてデータを作り、次に `http://127.0.0.1:8000/xhprof` でレポートページを確認します — どちらも同じプロセス内なので、アセットも併せて検証できます。

---

### Yii2

Yii2（`yiisoft/yii2 ^2.0`、PHP >= 8.0）向けです。**Yii3 とは別のフレームワークです**: Yii3 は PSR-15 への書き直し版で、Yii2 は独自の `yii\web\Request` / `Response` とアプリケーションライフサイクルを持つため、入口クラスも別になっています。

**1. ブートストラップクラスを登録** — `config/web.php`：

```php
'bootstrap' => [
    [
        'class' => \ErikWang2013\Xhprof\Yii2\XhprofBootstrap::class,
        'config' => ['auth_token' => 'xxx'],   // optional; see src/Yii2/config/xhprof.php
    ],
],
```

これは Yii2 公式の拡張ポイント `yii\base\BootstrapInterface` の標準的な登録形状です。配列定義の `config` キーはコンテナによって入口クラスの**公有プロパティ**に代入されます（`cache` / `logger` も同じ注入点で、インスタンスを渡せば既定の Redis アダプタや `error_log` を差し替えられます）。

**2. レポートページと静的アセット** — **コントローラもルートも、`urlManager` の変更も不要です**。ブートストラップクラスが `Application::EVENT_BEFORE_REQUEST` でリクエストパスを調べ、レポートパス `/xhprof` に命中すればレポートページをそのまま返してリクエストを終了し、アセットパス（`assets_url` 設定から読み取り、既定は `/xhprof-assets`）に命中すれば静的アセットを直接返します。どちらのパスも計測が始まる前にショートサーキットします。

**3. 計測ウィンドウ = `EVENT_BEFORE_REQUEST` → `EVENT_AFTER_REQUEST`**。Yii2 の `EVENT_AFTER_REQUEST` はレスポンスが**送られる前に**発火します（`base/Application.php` の `run()`）ので、レスポンス送信そのものはウィンドウ内に入りません。**例外経路は shutdown フォールバック頼みです**: `run()` が捕まえるのは `ExitException` だけなので、業務コードが投げたその他の throwable では `EVENT_AFTER_REQUEST` が永遠に発火しません — そのため入口クラスは計測開始時に `register_shutdown_function` によるフォールバックも登録します（Symfony / Joomla と同形）。

**4. コンソールアプリには何もしません** — `yii\console\Application` は `run()` をオーバーライドしないため、CLI コマンド（cron、マイグレーション、キュー）でも `EVENT_BEFORE_REQUEST` は**発火します**。そこで入口クラスは `bootstrap()` の先頭で `instanceof yii\web\Application` を確認し、コンソールにはフックを一切付けません。

**5. クライアント IP はフレームワーク自身の意味論に従います** — `getRealIp()` は `Request::getUserIP()` に委譲します。Yii2 は既定で `X-Forwarded-For` のような転送ヘッダを `secureHeaders` によって**除外**するため（安全側の既定）、リバースプロキシ配下では `REMOTE_ADDR` が記録されます。実際のクライアント IP を記録するには、アプリケーションの request コンポーネントに `trustedHosts` を設定してください（設定すると Yii2 は右から左へ見て最初の信頼できないアドレスを返します — 常に先頭を取る他のアダプタとは意図的に異なります）。これはフレームワーク自身のセキュリティ判断であり、どのホストを信頼するかを本パッケージが決めることではありません。

**6. 設定** — 既定値はパッケージ内の `src/Yii2/config/xhprof.php` にあり、手順 1 の `config` キーで上書きします。任意の `redis` サブ配列（`cache` を注入しないときに phpredis へ直接接続するために使います）は他のフレームワークと同じキー（`host` / `port` / `password` / `database` / `timeout`）を取ります。

---

## 設定リファレンス

すべてのフレームワークが以下の設定項目を共有します。

| 設定 | 型 | 既定値 | 説明 |
|--------|------|---------|-------------|
| `enable` | bool | `true` | 計測の有効 / 無効 |
| `sample_rate` | float | `1.0` | 比例サンプリング：各リクエストはこの確率で記録されます（例：`0.05` = リクエストの 5%）。`1.0` = すべて記録、`<=0` または `false` = 何も記録しない |
| `trigger_token` | string\|null | `null` | オンデマンドのトリガーサンプリング：設定すると、リクエストヘッダー `X-Xhprof-Token: <値>` を伴うリクエストは**必ずサンプリング**されます（`sample_rate` を無視し、`0` でもサンプリング）。`null` または空文字 = 無効で、このヘッダーは完全に無視されます。受け付けるのはリクエストヘッダーのみで、**クエリ文字列では渡せません**。任意のリクエストを強制的に全サンプリングできるため、キーは十分に長いランダム文字列にして、信頼できる相手にだけ渡してください |
| `auth_basic` | string\|null | `null` | HTTP Basic の資格情報（`user:password`。最初のコロンで分割し、パスワードにはコロンを含められます）。`auth_token` とは **OR** の関係です：どちらか一方を設定すれば有効になり、どちらか一方を通過すれば通れます。どちらも未設定 = 認証なし。**Apache + CGI/FastCGI は既定で `Authorization` ヘッダーを剥がします**（`CGIPassAuth On`、2.4.13+ が必要）。nginx + php-fpm はこの制限を受けません |
| `ip_allowlist` | array | `[]` | レポートページの IP 許可リスト。**バイト単位の完全一致**です：CIDR 表記は非対応で、IPv6 の正規化も行いません（`2001:0db8::1` と `2001:db8::1` は別の文字列です）。空 = 無効。配列でない値 = すべて拒否（fail closed、error ログを 1 件記録）。判定に使う値は `getRealIp()` から取得され、`trusted_proxies` と合わせて理解する必要があります |
| `trusted_proxies` | array | `[]` | **デプロイの宣言であり、技術的な強制ではありません**：「自分の前に信頼できるプロキシがいる」と宣言して初めて、`ip_allowlist` は `X-Forwarded-For`/`X-Real-IP` から取得したクライアント IP を受け入れます。ほとんどのアダプタは転送ヘッダーを無条件に採用します — 宣言しても**偽造された XFF は防げません**。安全なのは、自分で管理するプロキシの背後に置く場合だけです |
| `webhook_url` | string\|null | `null` | 遅い実行（`wt >= view_wtred`）が保存された後、このアドレスに JSON（`run_id`/`uri`/`wt`/`ct`/`ip`/`time`）を POST します。空 = 何も送信しません。**キューではありません**：レスポンスを待たず、リトライもディスクへのフォールバックもありません。エンドポイントが遅い / 落ちている場合は、この通知 1 件が失われるだけです |
| `sample_cli` | bool | `false` | CLI / HTTP 以外のリクエストもサンプリング：`true` の場合、保存される実行の `request_uri` は `cli:<スクリプト名>` として記録されます。`false` = 常に無視（既定。キュー worker や定期実行タスクを含みます）。有効になるかは入口によります：Laravel は組み込み対応（キューのリスナー + `XhprofCli`。Laravel の節を参照）、素の PHP の入口は元々 CLI 形態で動きます（HTTP 依存なし）；その他のフレームワークの入口は HTTP 専用のため、自前で 1 層包む必要があります |
| `symbol_lookup_url` | string\|null | `null` | ソースリンクのテンプレート：レポートページは `<テンプレート>?symbol=<urlencode された関数名>` を描画します。`null` / 空 = リンクを表示しません |
| `max_runs_per_minute` | int\|null | `null` | 適応型の予算：1 分あたりに記録する実行数の上限（分単位のカウンター。超えるとサンプリングされません）。`null` / 非正数 = 無効。キャッシュが利用できない / 例外を投げる場合は fail-open（通常どおり `sample_rate` に従ってサンプリング）。**トリガーサンプリングはこの制限を受けません** |
| `time_limit` | int | `0` | n 秒を超えたリクエストのみ計測。0 はすべて |
| `log_num` | int | `1000` | 最大記録件数 |
| `view_wtred` | int | `3` | レスポンスタイムが n 秒を超える行を赤で強調 |
| `ignore_url_arr` | array | `["/xhprof"]` | 無視する URL パス |
| `assets_url` | string | `/xhprof-assets` | 静的アセットの URL プレフィックス |
| `auth_token` | string\|null | `null` | 設定するとレポートページに `?token=xxx` が必要。**既定の `null` は認証なし**：入口クラスはホストアプリの認証が走る**前に**レポートページとその静的アセットを引き継ぐため（「レポートページと静的アセット」のトレードオフを参照）、トークン未設定ではこのパスに到達できる誰もがすべての run の request URI・送信元 IP・関数名を読めます — 公開環境・マルチテナント環境では**必ず**設定してください。未設定の場合、描画ごとに警告が 1 件ログに記録されます |
| `key_prefix` | string | `xhprof` | Redis のキープレフィックス。1 つの Redis を共有する場合はプロジェクトごとに別の値を設定 |
| `log_ttl` | int | `604800` | データ保持期間（秒、既定 7 日） |
| `locale` | string\|null | `null` | レポートページの言語：`zh_CN`/`en`/`ko`/`ru`/`de`/`fr`/`es`/`pt`/`ar`/`hi`/`bn`/`id`/`ja`。`null` = ブラウザの `Accept-Language` に従い、一致しなければ中国語。`?lang=xx` で 1 リクエストだけ上書きできます |

各設定項目のフレームワークごとの既知の制限は[検証と既知の制限](#検証と既知の制限)にまとめています。

`sample_rate` を下げることが、オーバーヘッドを比例的に削減する唯一の方法です（`0.05` はリクエストの 5% だけを記録）。`ignore_url_arr` は従来どおりパス全体を除外でき、両者は併用できます。判定はリクエストごとにサンプリングの入口で 1 回だけ行われ、既存 run の読み出しや保持には影響しません。不正な値（例：`'5%'`、`'disabled'`）は `1.0` にフォールバックします：過剰に記録するほうが、静かに何も記録されずレポートページが壊れて見えるよりましだからです。

計測データを消去するには：一覧ページだけを空にするなら `DEL <prefix>:run_id` を使います — データキーは `log_ttl` で自然に期限切れになり、インデックスに残った参照先のない id は一覧がスキップします。すべて消すなら `<prefix>:request_log:*` と `<prefix>:xhprof_log:*` をスキャンし、インデックスリストごとまとめて削除してください（`DEL` はワイルドカードを受け付けないため、先に `redis-cli --scan --pattern '<prefix>:*'` でキーを列挙してから削除します。`KEYS` は使わないでください）。インデックスリストに TTL がないのは意図的です：`log_num` で上限が定まっており、データキーを指すポインタにすぎないからです（`<prefix>` はこのプロジェクトに設定した `key_prefix` の値です）。

**トリガーサンプリング（`trigger_token`）**

オンデマンドのトリガーと比例サンプリングは独立した 2 つの軸です — 先にトリガーを判定し、次に抽選します：`trigger_token` を設定すれば、本番環境では `sample_rate` を `0` まで下げられ（通常運用ではまったくサンプリングしない）、調査が必要なときに `X-Xhprof-Token` ヘッダーを付けたリクエストを 1 本送れば、そのリクエストだけが完全にサンプリングされます。キーの比較には定数時間の `hash_equals` を使います。受け付けるのはリクエストヘッダーのみで、クエリ文字列で渡さないでください（クエリはアクセスログ・`Referer`・ブラウザー履歴に残ります）。トリガーは `ignore_url_arr` を迂回しません（レポートページや静的アセットのリクエストは、キー付きでも従来どおりスキップされます）。`enable: false` は引き続き全体のスイッチです。

**レポートページの認証（`auth_token` と `auth_basic`）**

`auth_token`（`?token=xxx`）と `auth_basic`（HTTP Basic）は **OR** の関係です：どちらか一方を設定すれば有効になり、どちらか一方を通過すれば通れます。どちらも未設定 = 認証なし（既定で、描画ごとに警告が 1 件ログに記録されます）。Basic の資格情報は `user:password` の形式です（最初のコロンで分割し、パスワードにはコロンを含められます。ユーザー名とパスワードの両方の部分を `hash_equals` で比較します）。Basic を設定していて検証に失敗した場合の応答は 401 + `WWW-Authenticate` です — ブラウザーの資格情報ダイアログが出るのはこれが唯一の条件です。token のみの失敗は 403 を返します。**既定で認証しないのは意図的な決定です**：レポートページは入口クラスがホストアプリの認証が走る**前に**引き継ぐため、何も設定していなければ、このパスに到達できる誰もがすべての run のリクエスト URI・送信元 IP・関数名を読めます。公開環境・マルチテナント環境では**必ず**どちらかを設定してください。**デプロイの罠**：Apache + CGI/FastCGI は既定で `Authorization` ヘッダーを剥がすため、Basic は永遠に一致しません（ずっと 401 を返し続けます）— `CGIPassAuth On`（2.4.13+）または同等の転送変数が必要です。nginx + php-fpm はこの制限を受けません。

**IP 許可リストと信頼できるプロキシ（`ip_allowlist` / `trusted_proxies`）**

許可リストは**バイト単位の完全一致**です：CIDR 表記は非対応で、IPv6 の正規化も行いません（`2001:0db8::1` と `2001:db8::1` は別々の文字列です）。空 = 無効。配列でない値は**すべて拒否**し、error ログを 1 件記録します（fail closed — 黙って無効化するのは、セキュリティ制御を 1 層こっそり落とすのと同じだからです）。判定に使う値はアダプタの `getRealIp()` から取得しますが、ほとんどのアダプタは `X-Forwarded-For` / `X-Real-IP` があれば転送ヘッダーを**無条件に**採用します：その値をそのまま照合すると、どのクライアントでも自称のアドレスで許可リストをすり抜けられます。そこで `trusted_proxies` です：IP 値が転送ヘッダー由来である場合、`trusted_proxies` が非空であることを要求し、そうでなければリクエストを拒否してログに記録します。**これはデプロイの宣言であり、技術的な強制ではありません**：宣言しても偽造された XFF は防げず、安全なのは実際に自分で管理するプロキシの背後で動かす場合だけです。途中のホップが信頼できるかは、あなたのプロキシ設定の責任です。許可リストのゲートは資格情報の検証より前に走ります（拒否は 403）。

**遅いリクエストの webhook（`webhook_url`）**

応答時間が `wt >= view_wtred` の run が保存された後、このアドレスに JSON（フィールド：`run_id` / `uri` / `wt` / `ct` / `ip` / `time`）が POST されます。空 = 何も送信しません。**これはキューではありません**：fire-and-forget — 接続し、リクエストを書き、ソケットを閉じるだけで、応答を待たずステータスコードも読みません。リトライもディスクへのフォールバックもなく、エンドポイントが遅い / 落ちていればこの通知 1 件が失われるだけです（接続タイムアウトは 200ms に抑えられていますが、DNS 解決はこの制限を受けません）。失敗時は error ログを 1 件記録するだけで、業務リクエストには一切影響しません。一覧ページの赤色表示は厳密な `>`、webhook の条件は `>=` で、境界が 1 段違います。

**適応型の予算（`max_runs_per_minute`）**

1 分あたりに記録する実行数の上限です：数えるのは**サンプリング入口に到達したリクエスト数**（抽選に外れたものも含みます — 判定は抽選より前に行われます）。超えると次の 1 分が始まるまでサンプリングされません。`null` / 非正数 = 無効。カウンターはキャッシュに置かれます：Redis に `<key_prefix>:budget:<YmdHi>` キー（例：`xhprof:budget:202610032316`）が現れ、最初の incr で 120 秒の TTL が付いて自然にゼロへ戻ります — 運用中の確認でこれを見かけても正常です。キャッシュが利用できない / 例外を投げる場合は **fail-open** です：通常どおり `sample_rate` に従ってサンプリングし、予算の仕組みがリクエストを失敗させたりサンプリングを黙って止めたりすることは決してありません。**トリガーサンプリングはこの制限を受けません**：キーを持って調査に来た人が予算で締め出されるべきではないからです（判定順序：トリガー → 予算 → 抽選）。

**レポートページの言語切り替え**

ナビゲーション右側のドロップダウンは 13 の言語を**それぞれの自称**で並べます（各語彙の `_meta.name`。例：한국어、日本語）。各項目のリンクは**現在のページのクエリ文字列**から生成されるため（`XhprofLib::report_url()`）、`?token=`・並び順・`run` などのパラメータもそのまま引き継がれます。言語を切り替えても**現在のビューから離れません** — 実行レポートで切り替えれば同じ実行にとどまります。

**レポートページの診断エリア**

レポート本文のいちばん上のカードが「診断結果」です（実行の説明のすぐ下）。まず「なぜ遅いのか」（最大 3 件の原因）、次に「その他の検出事項」（最大 3 件の確認項目）を並べます。各結論の後ろの「表示」リンクはそのメソッドの詳細ページへ移動します。ただし再帰（R4）には、裸の名前が実際にシンボル表にある場合だけリンクが付きます — xhprof は再帰を `fib@1`/`fib@2` に展開するため、展開後の名前しかなければ詳細ページは `fib` で引いても見つかりません。6 つのルールとしきい値：

- **R1** 自身実時間がリクエストの合計実時間の 10% 以上。
- **R2** 呼び出し回数が 1000 以上。
- **R3** 1 本の呼び出し関係の呼び出し回数が 500 以上、**かつ**呼ばれる側の自身実時間がリクエストの合計実時間の 5% 以上。
- **R4** 同じ名前のシンボルが 2 つ以上の異なる深度に現れる（再帰）。
- **R5** 自身のピークメモリが全体のピークの 30% 以上。
- **R6** 自身実時間 > 合計実時間（`excl_wt > wt`、論理的にありえません）— データ整合性のプローブで、健全なデータでは発火しません。

しきい値は `src/Core/Analysis/Analyzer.php` の定数に固定されており、現時点でこれを変更したり診断エリアを無効化したりできる**設定項目はありません**（`enable` を切るとサンプリング自体が行われないため、診断する対象もありません）。このエリアは**最上位の単一実行ビューにのみ**表示されます。diff 比較ビューとメソッド詳細ページでは描画されません — それらに渡される `$symbol_tab`/`$totals` は単一実行の値ではないからです（diff モードでは run2 − run1 の差分になります）。

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

**`autoDetect()` が知っている 4 フレームワークを除く残り 8 つ（Yii2 / Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal / 素の PHP）は、引数なしの `Xhprof::bootstrap()` を呼んではいけません** — 引数なしでは `autoDetect()` を通り、そこが知っているのは webman / Laravel / ThinkPHP / Hyperf の分岐だけで、この 8 つでは `Unsupported framework` が投げられます。上の例のように 5 つのアダプタをすべて明示的に渡してください（各フレームワークに同梱の入口クラスが既にそうしています）。

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

**残り 8 つは `autoDetect()` を通らず、すべて明示的注入を使います**。各入口クラスが自分で 5 つのアダプタを構築し、`Xhprof::bootstrap($req, $res, $cfg, $cache, $log)` に渡します。理由は、PSR-7 系やフレームワーク固有のリクエストオブジェクトはリクエストパイプラインからしか取得できないため、引数なしの `bootstrap()` は構造上動作しえないことです。副次的な利点として、`autoDetect()` は現在の 4 フレームワークのまま凍結できます。

![アーキテクチャ](docs/images/architecture.svg)

1 枚目は**構造**の図です。12 フレームワークそれぞれの入口クラス、5 つの契約、Core の 3 層、そして残った 2 箇所の結合を示します。

![設計のトレードオフ](docs/images/design.svg)

2 枚目は**根拠**の図です。5 つのトレードオフを判断 / 理由 / コストとして並べ、「後から拡張した 8 フレームワークによる `src/Core/` の変更数 = 0」を掲げています。

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
| 素の PHP（フレームワークなし） | 入口ファイルの先頭に 1 行 `XhprofBootstrap::start()` | プロセス shutdown（`register_shutdown_function`）。`stop()` で早めに止めることも可 |
| Yii2 | `EVENT_BEFORE_REQUEST` | `EVENT_AFTER_REQUEST`（レスポンス送出前に発火）、加えて shutdown フォールバック |

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
│   ├── Yii2/                     # Yii2：BootstrapInterface 入口クラスと 5 つのアダプタ
│   ├── Native/                   # 素の PHP（フレームワークなし）：入口クラスと 5 つのアダプタ
│   └── html/                     # レポートページのアセット（css / js / images / pet.svg サイトアイコンとブランドアイコン）
├── wordpress/                    # mu-plugin ブートストラップファイル（プラグインヘッダ付き）
├── joomla/                       # Joomla プラグイン（CMSPlugin + マニフェスト）
├── drupal/xhprof/                # 標準 Drupal モジュール（info / routing / services + Controller）
├── tools/contracts/              # 独立検証ループ：実フレームワークパッケージに対するシグネチャとセマンティクスの検証（`legacy-symfony64/`、`legacy-symfony8/` は 2 本の旧版レグ）
├── tools/i18n/                   # README と 3 枚の SVG の翻訳ツールチェーン（生成 / 検証 / 自己テスト）
├── docs/i18n/                    # 12 言語の訳文成果物（英語、韓国語、ロシア語、ドイツ語、フランス語、スペイン語、ポルトガル語、アラビア語、ヒンディー語、ベンガル語、インドネシア語、日本語）
├── tests/                        # PHPUnit：アダプタ・配線・Core のテスト、14 の README の構造一致
├── demo/                         # docker compose デモ（素の PHP 入口、環境を入れずにレポートページを見る）
└── docs/images/                  # README の図
```

Drupal を除き、すべての `src/<Fw>/` ディレクトリは同じ形です。

```
src/<Fw>/
├── Adapter/{Request,Response,Config,Redis,Log}Adapter.php
├── <EntryClass>.php
└── config/xhprof.php             # 他のフレームワークと同じ 19 個の設定キー
```

`src/Drupal/` だけが例外です。`config/` ディレクトリを持たず、設定はモジュールレベルの typed config（`drupal/xhprof/config/install/xhprof.settings.yml`）にあります。

---

## 検証と既知の制限

**機械的に証明されていること**

| 項目 | 方法 |
|------|-----|
| アダプタと入口の配線の挙動 | `tests/Unit/Adapter/*Test.php`：有効 → 保存 / 無効 → 保存しない / 業務例外 → `finally` 経由で保存される |
| 12 フレームワークが 1 つの設定キー集合を共有 | 設定パリティテスト（キー集合のみで、バイト単位の一致は見ない。コメントは差異を許容） |
| 2 つの README が対応している | README パリティテスト：`##` / `###` の見出し列とコードブロック数を比較 |
| アダプタが呼ぶメソッドが実在する | `tools/contracts/` の検証ループ（専用の CI ジョブ、**3 本のレグ**: 主レグは各フレームワークの最新パッケージを入れ、別プロジェクト `tools/contracts/legacy-symfony64` が同じ Symfony ケースを 6.4 に対して実行し、3 本目の `tools/contracts/legacy-symfony8` が Symfony 8.1 + Laravel 13（PHP 8.5）を実行します）: 実フレームワークパッケージを導入し（Drupal は実物の `drupal/core`、Joomla は実物の CMS リリースパッケージ 2 本）、すべてのメソッド / 定数 / グローバル関数の存在をリフレクションで検証します — **ループに入っている 10 のフレームワーク**（Slim / Symfony / Yii3 / Yii2 / Joomla / WordPress / Drupal / Laravel / Webman / ThinkPHP）について。素の PHP / Hyperf はループ外です（それぞれ理由が異なります — 下記参照） |
| アダプタのセマンティクス | 同じループが実際の request / response オブジェクトを生成してアダプタを走らせ、2 つの不変条件（`uri()` が scheme/host を含まないこと、`file()` の後でも `withHeaders()` が適用されること）を確認します。ループの SKIP 数は凍結された定数（主レグ 2、6.4 レグ 0、8 レグ 0）で、どちらも Joomla にあります: `#__extensions.params` の実際の読み取り経路とインストーラの形態で、どちらも実行にデータベースかインストーラが要ります；各 case のアサーション数にはさらにレグごとの凍結下限があります（編集による縮小への防御: 早期 return や条件包裹でアサーションの実行数が減っても status が PASS のままだと red になります） |


**自動検証されていないもの（「すべて網羅した」と読まないでください）**

| 項目 | 理由 |
|------|---------|
| 各フレームワークの**配線**（フックが本当に付いているか、イベントが本当に発火するか） | 単体テストはスタブを使う。配線は現状、手動スモークテストでしか確認できない |
| Joomla の残り 2 つのサブ項目 | ループがまだ届かない 2 点で、どちらも理由は同じです（データベースかインストーラが必要）: `#__extensions.params` の実際の読み取り経路（`PluginHelper::getPlugin()` → `bootPlugin()`）とインストーラの形態（namespacemap が書かれ、`bootPlugin()` がクラスを見つけられること） |
| Symfony の `kernel.event_subscriber` 自動設定 | 実際のコンテナコンパイルが必要 |
| 長時間稼働プロセスでの静的状態の混線 | **コルーチン単位で隔離済み**: バックエンドがコルーチンコンテキスト内にある場合、リクエストごとの描画状態はその環境の Context に退避されます（Hyperf / workerman を自動検出。`Xhprof::coroutineContextClass()` を参照）；コルーチン外では従来どおりプロセスレベルの静的（FPM は元々 1 リクエスト 1 プロセスです）。検証: Hyperf は実際に yield するコルーチンのテスト。workerman のコルーチンは検証ループの Webman カードが、実サーバー + 実 TCP の 2 リクエストを交互に走らせて固定しています（A が中断している間に B がページ全体を描画し、A が再開しても自分の run / 言語 / 指標列のままです） |
| Hyperf の並行コルーチンにおける**サンプリング**の混線 | xhprof 拡張とサンプリングスイッチはどちらもプロセスレベルです: 同一 worker 上の 2 つのコルーチンが IO 地点で交錯すると、先に stop したほうがデータを持ち去り（2 回目の stop は冪等な no-op）、後に完了した run は捨てられ、保存された 1 件には両方のコルーチンの実行記録が混ざります。描画状態は隔離済み（上の行）ですが、サンプリング状態は隔離できません（拡張のセマンティクス）。きれいなデータが必要なときは `sample_rate` を絞るか、そのシナリオではサンプリングをオフにしてください |
| Laravel Octane（Swoole）での静的状態の混線 | Octane の依存ツリーに workerman/workerman はなく、`Workerman\Coroutine` は構造上存在しないため、webman 側のバックエンドは再利用できません；Octane に必要なのは 3 つ目のバックエンド `\Swoole\Coroutine::getContext()`（約 10 行 + `class_exists` 分岐 1 つ）で、Swoole 環境が利用できるようになってから着手予定です |
| 実際の Redis I/O、ブラウザ描画、実負荷での計測オーバーヘッド | 実際の Redis I/O は**検証ループに入りました**（`cases/Redis.php`: 実 phpredis + 実 Slim リクエストを端から端まで —— リクエスト → 保存 → 一覧ページ → レポートページ）。ブラウザ描画と実負荷でのオーバーヘッドは従来どおり単体テストと検証ループの範囲外です |
| 素の PHP / Hyperf のアダプタのシグネチャとセマンティクス | この 2 つは検証ループに入っていません（ループが覆うのは 10 のフレームワーク）。理由はそれぞれ異なります：**素の PHP にはインストールすべきサードパーティパッケージがありません** — ループは実フレームワークパッケージと突き合わせる仕組みで、素の PHP にはその実パッケージが存在しないため、アダプタのセマンティクスは `tests/Unit/Adapter/NativeTest.php` が実際のスーパーグローバルと実 `php -S` のラウンドトリップで覆っています（ループの CLI より強い観測面です）。**Hyperf はインストールはできるのにそこで動きません**: 実際に `Context::set()` を呼ぶと `Class "Swoole\Coroutine" not found` が投げられます — ループの CI が入れるのは xhprof+redis だけで、欠けている ext-swoole コルーチンランタイムはインストール可否ではなく*実行前提*です。そのためスタブは引き続きパッケージ内の `tests/Stubs/framework-stubs.php` に手書きで、実パッケージとの突き合わせはありません |

**手動スモークチェックリスト（フレームワークごとに 3 ステップ）**

| 手順 | 操作 | 期待結果 |
|------|--------|----------|
| 1 | 「フレームワーク設定」のとおりに入口クラスを組み込む | エラーが出ない |
| 2 | アプリの任意の URL にアクセスする | Redis の `xhprof:run_id` キーの長さが 1 増える |
| 3 | `/xhprof` を開く | レポートページがスタイル付きで描画される。`/xhprof-assets/js/xhprof_report.js` が 200 を返す |

**素の PHP のスモーク**: `php -S 127.0.0.1:8000 -t public public/index.php` でビルトインサーバーを起動し（「素の PHP」の 4 番目）、3 つの手順をそのまま実行します — レポートページとアセットが業務リクエストと**同じプロセス**にあるため、3 番目はそのまま検証できます。

**既知の制限：一覧に表示される `request_uri` にポートがない**

`host()` 契約は「ホストのみ、ポートを含まない」（R-2）を意味し、12 のフレームワークすべてが従っています。違うのは実装方法だけです。PSR-7 の `getHost()` はポートを含みません。Joomla / WordPress は手動で `parse_url` に一度かけます。Webman と ThinkPHP は厳格引数 `host(true)` を渡す必要があります（既定の引数は `Host` ヘッダーをポートごとそのまま返します）。一覧に表示される `request_uri` は `host() . uri()` で組み立てられるため（`src/Core/XhprofLib/Utils/XHProfRunsDefault.php`）、非標準ポート（例：`:8080`）ではその行の**テキスト**にポートが現れません。**リンク自体は影響を受けません**：一覧とレポート内のリンクはすべて `XhprofLib::report_url()` が生成する相対 URL（パス + クエリのみ）で、正しいページを開き、host に依存しません。

**`assets_url` がカスタムプレフィックスに対応しました**

アセットのプレフィックスはもうハードコード定数ではありません。`src/Core/StaticController.php` が `assets_url` 設定でアセットのパスを照合します（既定 `/xhprof-assets`、末尾スラッシュは有無どちらでも可）。サブディレクトリ配置での残りの制限は下の Drupal の項目です。 **12 フレームワークすべてがこの設定に従います**。11 の入口クラスは計測開始前にアセットパスを自前でショートサーキットして配信し、Drupal は既定プレフィックスをモジュールルート + コントローラで、独自プレフィックスをミドルウェアで配信します。**境界**: Laravel、Hyperf、Webman、ThinkPHP はもうコントローラもルートも不要です — ミドルウェアが先に走るため、旧手順どおりに登録したコントローラと 2 本のルートは覆い隠されるだけ: エラーにはならず、二度と到達しません。

**既知の制限：Drupal がサブディレクトリにあるとパス判定が効かない**

Drupal がサブディレクトリ（例：`/sites/app/xhprof`）にインストールされている場合、パス判定がベースパスを含む URI に一致できないため、挙動は「計測するが保存されない」に落ちます（既定設定では `ignore_url_arr` が拾います）。

**Symfony 6.4 互換性**

Symfony 6.4 互換性は実測済みです（7.4 では見えない 2 つの過剰適合がこれで修正されました: 6.4 では `Request` のプロパティにネイティブの型宣言がなく、`prepare()` が付ける charset は大文字小文字が異なります）。**すべてのレグが CI で走ります**: 主レグの 7.x、別プロジェクト `tools/contracts/legacy-symfony64`（同じ case ファイルを複製せずに実行）、そして `tools/contracts/legacy-symfony8` レグ（Symfony 8.1 + Laravel 13、PHP 8.5）—— そのすべてが tag ゲートにも入っています。

---

## 作者

[erik](https://erik.xyz)

本パッケージは MIT ライセンスで公開しています（`LICENSE` 参照）。`src/Core/XhprofLib/**`、`src/html/js/xhprof_report.js`、`src/html/css/xhprof.css` は [phacility/xhprof](https://github.com/phacility/xhprof)（Apache-2.0）から派生したもので、その条件が引き続き適用されます。サードパーティ製フロントエンドライブラリの一覧は `NOTICE` にあります。

## オープンソースを支援

<p align="center">
  <img src="./docs/weixinpay.png" alt="WeChat Pay" width="130" height="130" title="WeChat Pay" />
  <img src="./docs/alipay.png" alt="Alipay" width="130" height="130" title="Alipay" />
</p>

---

本プラグインは [phacility/xhprof](https://github.com/phacility/xhprof) と [xiexianbo123/xhprof](https://github.com/xiexianbo123/xhprof) を参考にしています。
