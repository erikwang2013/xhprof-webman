# XHProf Profiler Performa

Plugin profiling performa kode yang kompatibel dengan webman / Laravel / ThinkPHP / Hyperf / Yii3 / Symfony / Slim 4 / WordPress / Joomla, dan Drupal.

Mengumpulkan data profiling lewat ekstensi xhprof dan menyimpannya di Redis. Pengembang dapat dengan cepat membuka laporan analisis performa melalui browser untuk menemukan hambatan performa kode.

**Riwayat Request**

![Riwayat Request](docs/images/runs-list.png)

**Laporan satu eksekusi**

![Laporan satu eksekusi](docs/images/run-report.png)

## Persyaratan

- PHP >= 8.0
- ekstensi xhprof
- ekstensi redis
- server Redis

## Framework yang Kompatibel dan Versi Minimum

| Framework | Versi minimum | PHP minimum | Kelas entri | Cara memasang |
|-----------|----------------|-------------|-------------|--------------|
| webman | `workerman/webman ^2.1` | 8.0 | `Webman\XhprofMiddleware` | Daftarkan middleware global di `config/middleware.php` |
| Laravel | `laravel/framework ^9.0\|^10.0\|^11.0` | 8.0 | `Laravel\Middleware` | Daftarkan middleware global di `app/Http/Kernel.php` |
| ThinkPHP | `topthink/framework ^6.0\|^8.0` | 8.0 | `Thinkphp\Middleware` | Daftarkan middleware global di `app/middleware.php` |
| Hyperf | `hyperf/framework ^3.0` | 8.0 | `Hyperf\Middleware` | Terdaftar otomatis lewat ConfigProvider |
| Yii3 | `yiisoft/middleware-dispatcher ^5.0` | 8.1 | `Yii3\XhprofMiddleware` | Daftarkan di `config/web/di/application.php`, harus paling depan di daftar middleware |
| Symfony | `symfony/http-kernel ^6.4\|^7.0` | 8.1 (6.4) / 8.2 (7.x) | `Symfony\XhprofListener` | Tambahkan tag `kernel.event_subscriber` di `config/services.yaml` |
| Slim 4 | `slim/slim ^4.12` | 8.0 | `Slim\XhprofMiddleware` | `$app->add(...)`, harus ditambahkan paling akhir |
| WordPress | 6.4+ | 8.0 | `Wordpress\XhprofPlugin` | Salin ke `wp-content/mu-plugins/` |
| Joomla | 4.4 / 5.x | 8.1 | `Joomla\Extension\Xhprof` | Salin ke `plugins/system/`, pasang lewat Discover |
| Drupal | 10.x / 11.x | 8.1 (10.x) / 8.3 (11.x) | modul `xhprof` (`Drupal\XhprofMiddleware`) | Modul standar, cukup diaktifkan |

Semua kelas entri berada di bawah prefiks namespace `ErikWang2013\Xhprof\` (dihilangkan di atas). Dari keenam framework baru, Drupal adalah pengecualian — ia melayani halaman report lewat route modul — sedangkan lima kelas entri lainnya **melayani halaman report sendiri**, tanpa perlu controller atau pendaftaran route.

Paket ini mendeklarasikan `php >= 8.0`, tetapi komponen `yiisoft/*` yang diandalkan Yii3 mensyaratkan **PHP 8.1+**, jadi **Yii3 tidak bisa dipakai di PHP 8.0**; Symfony 7.x dan Drupal 11.x juga butuh versi PHP yang lebih tinggi. Langkah pemasangan terperinci ada di "Konfigurasi Framework" di bawah.

## Instalasi

Tambahkan konfigurasi xhprof di php.ini:

```ini
[xhprof]
extension=xhprof.so
xhprof.output_dir=/tmp/xhprof
```

Pasang lewat Composer:

```sh
composer require aaron-dev/xhprof-webman
```

---

## Konfigurasi Framework

### Webman

**1. Daftarkan middleware global** — `config/middleware.php`:

```php
return [
    '' => [
        ErikWang2013\Xhprof\Webman\XhprofMiddleware::class,
    ],
];
```

**2. Buat controller**:

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

**3. Daftarkan route** — `config/route.php`:

```php
use Webman\Route;
use ErikWang2013\Xhprof\Webman\StaticController;

Route::get('/xhprof', [app\controller\XhprofController::class, 'index']);
Route::get('/xhprof-assets/{path:.+}', [StaticController::class, 'serve']);

```

**4. Konfigurasi** — Lihat `config/plugin/aaron-dev/xhprof/xhprof.php`.

---

### Laravel

**1. Daftarkan middleware** — `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ...
    \ErikWang2013\Xhprof\Laravel\Middleware::class,
];
```

**2. Buat controller**:

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

**3. Daftarkan route** — `routes/web.php`:

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

**4. Publikasikan konfigurasi**:

```sh
php artisan vendor:publish --tag=xhprof-config
```

Berkas konfigurasi ada di `config/xhprof.php`. Laravel mendukung penemuan otomatis ServiceProvider.

---

### ThinkPHP

**1. Daftarkan middleware** — `app/middleware.php`:

```php
return [
    \ErikWang2013\Xhprof\Thinkphp\Middleware::class,
];
```

**2. Buat controller**:

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

**3. Daftarkan route** — `route/app.php`:

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

**4. Konfigurasi** — Salin `vendor/aaron-dev/xhprof-webman/src/Thinkphp/config/xhprof.php` ke `config/xhprof.php` proyek.

---

### Hyperf

**1. Pendaftaran middleware otomatis** — ConfigProvider otomatis menambahkan middleware ke antrean middleware HTTP.

**2. Buat controller**:

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
        $html = Xhprof::index();
        if (!is_string($html)) {
            return $html;
        }
        return $this->response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream($html));
    }
}
```

Saat `Xhprof::index()` mengembalikan string HTML, **jangan** langsung `return`: `CoreMiddleware::transferToResponse()` milik Hyperf tanpa syarat menambahkan `content-type: text/plain` pada nilai kembalian berupa string, sehingga browser menampilkan halaman report sebagai teks biasa (terukur sama pada 3.0.45 / 3.1.69 / 3.2.0). Respons eksplisit di atas menghindarinya; bila autentikasi gagal, `index()` mengembalikan objek respons yang sudah terkirim — kembalikan saja apa adanya.

**3. Route aset statis** — `config/routes.php`:

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

**4. Publikasikan konfigurasi**:

```sh
php bin/hyperf.php vendor:publish aaron-dev/xhprof-webman
```

Hasil konfigurasi ada di `config/autoload/xhprof.php`.

---

### Yii3

**1. Daftarkan middleware** — `config/web/di/application.php`:

```php
use ErikWang2013\Xhprof\Yii3\XhprofMiddleware;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;

return [
    MiddlewareDispatcher::class => [
        'class' => MiddlewareDispatcher::class,
        // elemen pertama adalah middleware paling luar (jalan paling awal, selesai paling akhir)
        'withMiddlewares()' => [[
            XhprofMiddleware::class,
            // ... middleware lain
        ]],
    ],
];
```

Dua kesalahan yang mudah terjadi (keduanya sudah diukur):

- **Jangan** tulis `'__construct()' => ['middlewares' => [...]]`: `MiddlewareDispatcher::__construct()` hanya menerima `MiddlewareFactory` dan `EventDispatcherInterface` opsional — **tidak ada** parameter `middlewares`. Daftar middleware hanya bisa disuntikkan lewat **metode instance** `withMiddlewares()`.
- **Jangan** taruh instance (`new XhprofMiddleware(...)`) ke dalam `withMiddlewares()`: definisi hanya menerima class-string, definisi array, atau callable. Kalau diberi instance, pendaftaran tidak melaporkan apa pun dan `dispatch()` melempar `TypeError` (`MiddlewareFactory::create()` bertipe `callable|array|string`).

**2. Halaman report dan aset statis** — **tidak perlu controller atau pendaftaran route**: `XhprofMiddleware` adalah middleware PSR-15. Sebelum profiling dimulai ia memeriksa path request: jika kena path report `/xhprof` ia langsung mengembalikan halaman report, dan jika kena path aset (prefiks default `/xhprof-assets`) ia langsung mengembalikan aset statisnya. Respons halaman report membawa `Content-Type: text/html; charset=UTF-8` eksplisit dari kelas entri: respons PSR-7 tidak punya nilai default dan pengirim respons Yii3 tidak menambahkannya, jadi tanpa itu browser menampilkan laporan HTML sebagai teks biasa.

**3. Konfigurasi** — nilai default ada di dalam paket pada `src/Yii3/config/xhprof.php`; lihat "Referensi Konfigurasi" untuk daftar kolomnya. Untuk menimpanya, suntikkan `$config` lewat DI:

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

Sub-array `redis` khusus untuk Yii3: kalau tidak ada `CacheInterface` yang disuntikkan, middleware memakainya untuk berbicara langsung dengan phpredis. Biarkan `assets_url` pada nilai defaultnya — prefiks lain hanya menghasilkan 200 dengan body kosong (nilai itu konstanta yang di-hardcode di Core; lihat [Verifikasi dan Keterbatasan yang Diketahui](#verifikasi-dan-keterbatasan-yang-diketahui)).

**4. Syarat versi** — komponen `yiisoft/*` yang diandalkan Yii3 mensyaratkan PHP >= 8.1. Walaupun paket ini mendeklarasikan `php >= 8.0`, integrasi Yii3 tidak bisa dipakai di PHP 8.0.

---

### Symfony

**1. Daftarkan event subscriber** — `config/services.yaml`:

```yaml
services:
    ErikWang2013\Xhprof\Symfony\XhprofListener:
        tags:
            - { name: kernel.event_subscriber }
```

**2. Halaman report dan aset statis** — **tidak perlu controller atau pendaftaran route**: sebelum profiling dimulai listener memeriksa path request: jika kena path report `/xhprof` ia langsung mengembalikan halaman report, dan jika kena path aset (prefiks default `/xhprof-assets`) ia langsung mengembalikan aset statisnya.

**3. Konfigurasi** — nilai default ada di dalam paket pada `src/Symfony/config/xhprof.php`; lihat "Referensi Konfigurasi" untuk daftar kolomnya.

**4. Sub-request dan cadangan exception** — ia mendengarkan `kernel.request` (prioritas 10000) dan `kernel.response` (prioritas -10000). `isMainRequest()` menyaring sub-request ESI/fragment, yang kalau tidak akan menghentikan profiling terlalu dini; `register_shutdown_function` yang idempoten juga didaftarkan saat request dimulai — kalau HttpKernel melempar ulang exception, `kernel.response` tidak pernah menyala, dan tanpa cadangan itu status profiling akan bocor ke request berikutnya.

---

### Slim 4

**1. Daftarkan middleware** — `public/index.php`:

```php
use ErikWang2013\Xhprof\Slim\XhprofMiddleware;

$app->addRoutingMiddleware();

// harus ditambahkan paling akhir: tumpukan middleware Slim bersifat LIFO, makin akhir ditambahkan = makin luar = makin awal jalan
$app->add(new XhprofMiddleware(
    $app->getResponseFactory()
));
```

Tiga argumen konstruktor lainnya semuanya opsional; hilangkan saja untuk memakai nilai default bawaan paket:

- Argumen 2, `array $config`: array konfigurasi Anda, digabungkan di atas `src/Slim/config/xhprof.php` bawaan paket memakai `array_replace` (penggantian utuh — key berupa list seperti `ignore_url_arr` tidak pernah digabung secara rekursif).
- Argumen 3, `CacheInterface $cache`: kalau dihilangkan, ia malas melakukan `new \Redis()` (konstruktornya sengaja tidak pernah menyentuh ext-redis, agar ekstensi yang hilang tidak meledak saat adapter sedang dibangun). Untuk menyuntikkan koneksi sendiri, kirim `new \ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter($redis)`, atau objek apa pun yang mengimplementasikan `ErikWang2013\Xhprof\Core\Contract\CacheInterface`.
- Argumen 4, `LoggerInterface $logger`: kalau dihilangkan, nilainya `new \ErikWang2013\Xhprof\Slim\Adapter\LogAdapter()` dan **log dibuang diam-diam** (Slim tidak membawa logger PSR-3). Untuk merekamnya, kirim `new LogAdapter($psrLogger)`, dengan `$psrLogger` adalah logger PSR-3 yang sudah Anda punya.

**Jangan** tulis `$app->add(XhprofMiddleware::class)`: `CallableResolver` milik Slim mengubah class string itu menjadi `new XhprofMiddleware($container)` — hanya memberikan container, dan menunda penyelesaiannya sampai waktu request. Akibatnya `add()` tidak melaporkan apa pun dan request pertama melempar `TypeError` — mode kegagalan yang paling sulit didiagnosis. Selalu pakai `new` eksplisit seperti di atas.

**2. Halaman report dan aset statis** — **tidak perlu controller atau pendaftaran route**: sebelum profiling dimulai middleware memeriksa path request: jika kena path report `/xhprof` ia langsung mengembalikan halaman report, dan jika kena path aset (prefiks default `/xhprof-assets`) ia langsung mengembalikan aset statisnya.

**3. Konfigurasi** — nilai default ada di dalam paket pada `src/Slim/config/xhprof.php`; lihat "Referensi Konfigurasi" untuk daftar kolomnya.

**4. Urutan pemasangan** — tumpukan middleware Slim bersifat LIFO (diukur dengan dua middleware, urutan eksekusinya `B:before → A:before → A:after → B:after`): makin akhir Anda `add()`, makin luar posisinya dan makin awal ia jalan. Jadi xhprof harus ditambahkan **paling akhir**, dan **setelah `addRoutingMiddleware()`** — kalau tidak, `/xhprof` tidak ada di tabel routing, RoutingMiddleware lebih dulu melempar `HttpNotFoundException`, dan request tidak pernah sampai ke middleware. Respons halaman report membawa `Content-Type: text/html; charset=UTF-8` eksplisit dari kelas entri: respons PSR-7 tidak punya nilai default dan `ResponseEmitter` milik Slim tidak menambahkannya, jadi tanpa itu browser menampilkan laporan HTML sebagai teks biasa.

---

### WordPress

**1. Pasang mu-plugin** — salin berkas bootstrap dari paket ke `wp-content/mu-plugins/`:

```sh
cp vendor/aaron-dev/xhprof-webman/wordpress/xhprof-webman.php wp-content/mu-plugins/
```

`wordpress/xhprof-webman.php` membawa header plugin dan mem-boot `Wordpress\XhprofPlugin`. mu-plugin dimuat otomatis — tidak ada yang perlu diaktifkan di wp-admin.

**2. Halaman report dan aset statis** — **tidak perlu controller atau pendaftaran route**: sebelum profiling dimulai kelas entri memeriksa path request: jika kena path report `/xhprof` ia langsung mengembalikan halaman report, dan jika kena path aset (prefiks default `/xhprof-assets`) ia langsung mengembalikan aset statisnya.

**3. Konfigurasi** — nilai default ada di dalam paket pada `src/Wordpress/config/xhprof.php`; lihat "Referensi Konfigurasi" untuk daftar kolomnya. Pakai `ignore_url_arr` untuk mengecualikan path berfrekuensi tinggi seperti `wp-cron.php` dan `admin-ajax.php`.

**4. Batas struktural jendela profiling** — jendelanya adalah `plugins_loaded` → `shutdown`, yang **tidak mencakup** bootstrap `wp-settings.php` maupun pemuatan plugin itu sendiri. Itu batas struktural WordPress: pekerjaan yang dilakukan pada fase tersebut tidak bisa diprofilkan.

---

### Joomla

**1. Pasang plugin** — salin direktori `joomla/` dari paket ke `plugins/system/xhprof/` situs:

```sh
cp -r vendor/aaron-dev/xhprof-webman/joomla/ plugins/system/xhprof/
```

Direktori `joomla/` di paket *adalah* pluginnya: manifes `xhprof.xml`, `services/provider.php`, dan `src/Extension/Xhprof.php`. Kelas entrinya adalah `ErikWang2013\Xhprof\Joomla\Extension\Xhprof` (sebuah `CMSPlugin`). Setelah menyalin, jalankan Discover di "System → Manage → Extensions → Discover" lalu pasang/aktifkan.

**2. Halaman report dan aset statis** — **tidak perlu controller atau pendaftaran route**: sebelum profiling dimulai plugin memeriksa path request: jika kena path report `/xhprof` ia langsung mengembalikan halaman report, dan jika kena path aset (prefiks default `/xhprof-assets`) ia langsung mengembalikan aset statisnya.

**3. Konfigurasi** — nilai default ada di dalam paket pada `src/Joomla/config/xhprof.php`; lihat "Referensi Konfigurasi" untuk daftar kolomnya. **Trade-off yang diketahui**: konfigurasi dibaca dari berkas config paket, bukan dari parameter plugin — parameter plugin butuh pembacaan database, sedangkan konfigurasi dibaca pada setiap request.

**4. Batas profiling** — jendelanya adalah `ApplicationEvents::AFTER_INITIALISE` → `ApplicationEvents::AFTER_RESPOND`; `register_shutdown_function` yang idempoten juga didaftarkan di `AFTER_INITIALISE`, karena `AFTER_RESPOND` tidak dijamin tercapai pada jalur exception — tanpa cadangan itu status profiling akan bocor ke request berikutnya.

---

### Drupal

**1. Aktifkan modul** — `drupal/xhprof/` di dalam paket adalah modul Drupal standar (`xhprof.info.yml` / `xhprof.routing.yml` / `xhprof.services.yml`). Letakkan di `modules/custom/xhprof/` situs Anda, lalu aktifkan di halaman "Extend" (atau dengan `drush en xhprof`).

**2. Halaman report** — Drupal adalah **satu-satunya dari sepuluh framework yang melayani halaman report lewat route modul**: `xhprof.routing.yml` mendaftarkan path report `/xhprof` dan sebuah controller modul yang merendernya. Lima framework baru lainnya melayani halaman report dan aset statisnya sendiri dan tidak mendaftarkan route apa pun.

**3. Konfigurasi** — konfigurasinya adalah config bertipe tingkat modul: nilai default ada di `drupal/xhprof/config/install/xhprof.settings.yml`, dengan skemanya di `drupal/xhprof/config/schema/xhprof.schema.yml`. Lihat "Referensi Konfigurasi" untuk daftar kolomnya.

**4. Pendaftaran middleware** — daftarkan layanan middleware di `xhprof.services.yml` milik modul:

```yaml
services:
  xhprof.http_middleware:
    class: ErikWang2013\Xhprof\Drupal\XhprofMiddleware
    arguments: ['@config.factory', '@logger.factory']
    tags:
      - { name: http_middleware, priority: 1000 }
```

Kernel dalam **diberikan otomatis sebagai argumen konstruktor ke-0** oleh `StackedKernelPass` milik Drupal — **jangan menulisnya sendiri**: kalau ditulis, akan ada dua kernel dalam, yang gagal saat kompilasi container di Drupal <= 11.2.x (termasuk seluruh 10.x) dan melempar TypeError pada setiap request di 11.3.0 ke atas. Profiling berhenti di dalam `finally`.

**5. Catatan**

- `priority: 1000` menempatkan middleware **di luar page cache** (prioritas tertinggi yang sudah ada di core adalah negotiation: 400 di D10, 500 di D11, sedangkan page cache 200), jadi **request yang dilayani dari page cache Drupal tetap diprofilkan**. Untuk alat profiling, itu perilaku yang diinginkan, tetapi pengguna perlu mengetahuinya.
- Cache-nya bekerja langsung tanpa penyetelan: middleware memakai adapter Redis bawaan paket ini (paket ini bergantung keras pada ext-redis), dan ia juga menerima argumen `CacheInterface` opsional lewat `arguments` di `services.yml` untuk menimpanya. Kalau cache tidak tersedia, penyimpanan yang gagal ditelan oleh `XhprofProfiler::stop()` menjadi satu baris log — **tidak ada error yang dilempar**.
- Request ke halaman report `/xhprof` dan ke `/xhprof-assets/*` **tidak diprofilkan**: middleware melewati profiling berdasarkan path sebelum `xhprofStart()`. Responsnya tetap dihasilkan oleh Controller di `xhprof.routing.yml` (**ini bukan short-circuit**). Jadi meskipun `ignore_url_arr` diisi `[]` (tidak menyaring apa pun), kedua request itu tidak pernah muncul di laporan.

---

## Referensi Konfigurasi

Semua framework berbagi opsi konfigurasi berikut:

| Konfigurasi | Tipe | Default | Deskripsi |
|--------|------|---------|-------------|
| `enable` | bool | `true` | Aktifkan/nonaktifkan profiling |
| `time_limit` | int | `0` | Hanya profilkan request yang melebihi n detik, 0 berarti semua |
| `log_num` | int | `1000` | Jumlah maksimum rekaman |
| `view_wtred` | int | `3` | Sorot merah baris dengan waktu respons > n detik |
| `ignore_url_arr` | array | `["/xhprof"]` | Path URL yang diabaikan |
| `assets_url` | string | `/xhprof-assets` | Prefiks URL aset statis |
| `auth_token` | string\|null | `null` | Kalau diisi, halaman report mensyaratkan `?token=xxx`; disarankan untuk deployment publik |
| `key_prefix` | string | `xhprof` | Prefiks key Redis; isi nilai berbeda per proyek bila berbagi satu Redis |
| `log_ttl` | int | `604800` | Masa simpan data dalam detik (default 7 hari) |
| `locale` | string\|null | `null` | Bahasa halaman report: `zh_CN`/`en`/`ko`/`ru`/`de`/`fr`/`es`/`pt`/`ar`/`hi`/`bn`/`id`/`ja`; `null` = ikuti `Accept-Language` peramban, kalau tidak ada yang cocok pakai bahasa Mandarin; `?lang=xx` menimpanya untuk satu permintaan |

Keterbatasan yang diketahui dari opsi-opsi ini pada tiap framework tercantum di [Verifikasi dan Keterbatasan yang Diketahui](#verifikasi-dan-keterbatasan-yang-diketahui).

---

## Inisialisasi Manual

Kalau deteksi framework otomatis gagal, Anda bisa menyuntikkan adapter secara manual:

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

**Keenam framework baru (Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal) tidak boleh memanggil `Xhprof::bootstrap()` tanpa argumen** — tanpa argumen ia melewati `autoDetect()`, yang hanya mengenal cabang webman / Laravel / ThinkPHP / Hyperf dan melempar `Unsupported framework` untuk keenam framework ini. Kirimkan kelima adapter secara eksplisit seperti pada contoh di atas (kelas entri yang disertakan tiap framework sudah melakukannya untuk Anda).

---

## Arsitektur dan Desain

Core menjangkau framework lewat tepat 5 kontrak, semuanya di `src/Core/Contract/`:

| Kontrak | Metode | Tujuan |
|----------|---------|---------|
| `RequestInterface` | `get()` `all()` `method()` `header()` `host()` `uri()` `url()` `getRealIp()` | Membaca data request, mencocokkan path report, menyusun tautan halaman report |
| `ResponseInterface` | `withBody()` `withHeaders()` `withStatus()` `file()` `send()` | Mengeluarkan halaman report, aset statis, dan 400/403 |
| `ConfigInterface` | `get()` | Membaca konfigurasi plugin: `get('xhprof')` untuk seluruh blok, `get('xhprof.assets_url')` untuk satu daun |
| `CacheInterface` | `get()` `set()` `mget()` `incr()` `lPush()` `rPop()` `lRange()` `del()` `decr()` | Baca dan tulis Redis |
| `LoggerInterface` | `error()` | Peringatan untuk ekstensi yang hilang dan penyimpanan yang gagal |

Setiap framework menyediakan 5 adapter yang mengimplementasikan kontrak-kontrak ini, didaftarkan ke Core oleh `Xhprof::bootstrap()`. Semua yang spesifik framework tetap berada di dalam direktori `src/<Fw>/` milik framework itu sendiri.

**Hanya dua coupling ke framework yang tersisa di Core**:

1. Rantai `class_exists()` di `Xhprof::autoDetect()` (`Webman\App` → `Illuminate\Foundation\Application` → `think\App` → `Hyperf\Context\ApplicationContext`), yang hanya dicapai oleh `bootstrap()` tanpa argumen.
2. Sakelar korutin Hyperf yang di-hardcode: `Xhprof::markHyperfContext()` plus pemeriksaan keberadaan `\Hyperf\Context\Context`, yang menentukan apakah adapter masuk ke properti statis seluruh proses atau ke Context korutin.

**Keenam framework baru tidak pernah lewat `autoDetect()` — semuanya memakai injeksi eksplisit**: setiap kelas entri membangun sendiri 5 adapter-nya dan mengirimkannya ke `Xhprof::bootstrap($req, $res, $cfg, $cache, $log)`. Alasannya, pada framework PSR-7 Request/Response hanya bisa diperoleh dari pipeline request, jadi `bootstrap()` tanpa argumen memang tidak mungkin bekerja; manfaat keduanya, `autoDetect()` tetap beku pada empat framework yang sekarang.

![Arsitektur](docs/images/architecture.svg)

Diagram pertama adalah **strukturnya**: kelas entri dari kesepuluh framework, 5 kontraknya, tiga lapisan Core, dan satu-satunya dua coupling yang tersisa.

![Alasan desain](docs/images/design.svg)

Diagram kedua adalah **penalarannya**: lima trade-off yang disusun sebagai keputusan / alasan / biaya, dengan judul "perubahan pada `src/Core/` dari keenam framework baru = 0".

---

## Siklus Hidup Request

Satu request yang diprofilkan:

1. **Sebelum profiling dimulai**, kelas entri memeriksa path: jika kena path report, halaman report langsung dikembalikan; jika kena path aset, aset statisnya langsung dikembalikan. Kedua path ini tidak diprofilkan, dan keduanya tidak masuk ke alur di bawah.
2. `XhprofProfiler::isEnabled()` membaca `enable` dari konfigurasi; kalau profiling mati atau ada ekstensi yang hilang, seluruh blok dilewati.
3. `Xhprof::xhprofStart()` → `XhprofProfiler::start()` → `xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY)`.
4. Logika bisnis berjalan.
5. `finally { Xhprof::xhprofStop(); }` → `xhprof_disable()`, lalu `XHProfRunsDefault::save_run()` menulis ke Redis. Memakai `finally` dan bukan pernyataan biasa, supaya exception yang dilempar tetap membersihkan status profiling dan menyimpan run-nya.
6. Browser membuka halaman report; `Xhprof::index()` membaca kembali datanya dari Redis dan merendernya.

![Siklus hidup](docs/images/lifecycle.svg)

| Framework | Profiling mulai | Profiling berakhir |
|-----------|-----------------|----------------|
| webman / Laravel / ThinkPHP / Hyperf | Masuk middleware (`process()` / `handle()`) | `finally` |
| Yii3 / Slim 4 | PSR-15 `process()` | `finally` |
| Symfony | `kernel.request` (prioritas 10000) | `kernel.response` (prioritas -10000), plus cadangan shutdown |
| WordPress | `plugins_loaded` | `shutdown` |
| Joomla | `onAfterInitialise` | `onAfterRespond`, plus cadangan shutdown |
| Drupal | `http_middleware` (prioritas 1000, terluar) | `finally` |

---

## Struktur Proyek

```
xhprof-webman/
├── src/
│   ├── Core/                     # Tanpa ketergantungan framework: kontrak, halaman report, Redis, aset
│   │   ├── Contract/             # 5 antarmuka kontrak
│   │   ├── XhprofLib/            # rendering laporan dan penyimpanan run (dari phacility/xhprof)
│   │   ├── Xhprof.php            # fasad statis: bootstrap() / index()
│   │   ├── XhprofProfiler.php    # xhprof_enable/disable dan konfigurasi
│   │   ├── StaticController.php  # aset statis /xhprof-assets
│   │   ├── MiddlewareTrait.php   # pembungkus profiling bersama untuk Laravel / ThinkPHP
│   │   └── RedisAdapterTrait.php # implementasi adapter Redis bersama
│   ├── Webman/ Laravel/ Thinkphp/ Hyperf/            # 4 framework yang sudah ada
│   ├── Yii3/ Symfony/ Slim/ Wordpress/ Joomla/ Drupal/   # 6 framework baru
│   └── html/                     # aset halaman report (css / js / images)
├── wordpress/                    # berkas bootstrap mu-plugin (dengan header plugin)
├── joomla/                       # plugin Joomla (CMSPlugin + manifes)
├── drupal/xhprof/                # modul Drupal standar (info / routing / services + controller)
├── tools/contracts/              # loop verifikasi mandiri: signature dan semantik terhadap paket framework asli
├── tools/i18n/                   # rantai alat terjemahan untuk README dan ketiga SVG (generate / check / selftest)
├── docs/i18n/                    # 12 hasil terjemahan (Inggris, Korea, Rusia, Jerman, Prancis, Spanyol, Portugis, Arab, Hindi, Bengali, Indonesia, Jepang)
├── tests/                        # PHPUnit: test adapter, test wiring, test Core, paritas struktural di 14 README
└── docs/images/                  # diagram README
```

Kecuali Drupal, setiap direktori `src/<Fw>/` memiliki bentuk yang sama:

```
src/<Fw>/
├── Adapter/{Request,Response,Config,Redis,Log}Adapter.php
├── <EntryClass>.php
└── config/xhprof.php             # 10 key konfigurasi yang sama seperti framework lain
```

`src/Drupal/` adalah satu-satunya pengecualian: ia tidak punya direktori `config/` — konfigurasinya berada di config bertipe tingkat modul (`drupal/xhprof/config/install/xhprof.settings.yml`).

---

## Verifikasi dan Keterbatasan yang Diketahui

**Apa yang sudah dibuktikan secara mekanis**

| Butir | Caranya |
|------|-----|
| Perilaku adapter dan wiring kelas entri | `tests/Unit/Adapter/*Test.php`: aktif → tersimpan / nonaktif → tidak tersimpan / exception bisnis → tetap tersimpan lewat `finally` |
| Kesepuluh framework berbagi satu set key konfigurasi | tes paritas konfigurasi (set key, bukan byte per byte; komentar boleh berbeda) |
| Kedua README saling mencerminkan | tes paritas README: membandingkan urutan judul `##` / `###` dan jumlah blok kode |
| Metode yang dipanggil adapter benar-benar ada | loop verifikasi `tools/contracts/` (job CI tersendiri): memasang paket framework asli dan memastikan lewat reflection bahwa setiap metode / konstanta / fungsi global ada **untuk enam framework yang masuk loop** (Slim / Symfony / Yii3 / Joomla / WordPress / Drupal); Webman / Laravel / ThinkPHP / Hyperf tidak masuk loop — lihat di bawah |
| Semantik adapter | Loop yang sama menginstansiasi objek request dan response asli lalu menjalankan adapter-nya, termasuk dua invarian: `uri()` tidak membawa skema/host, dan `withHeaders()` tetap berlaku setelah `file()` |


**Tidak diverifikasi secara otomatis (jangan dibaca sebagai "keenamnya sudah diuji")**

| Butir | Kenapa tidak |
|------|---------|
| **Wiring** setiap framework (apakah hook-nya benar-benar terpasang, apakah event-nya benar-benar menyala) | Unit test memakai stub; wiring saat ini hanya bisa dipastikan lewat smoke test manual |
| WordPress secara menyeluruh | Waktu `plugins_loaded` yang sebenarnya, apakah `shutdown` menyala pada fatal error, dan apakah mu-plugin-nya dimuat, semuanya membutuhkan WordPress asli |
| Penemuan plugin Joomla dan `$app->close()` | Membutuhkan menjalankan Discover di admin Joomla asli |
| Apakah prioritas Drupal benar-benar mendarat di luar page cache | Membutuhkan kernel Drupal yang sudah di-boot |
| Konfigurasi otomatis `kernel.event_subscriber` Symfony | Membutuhkan kompilasi container asli |
| Cakap-silang state statis di proses berjalan lama | Diwarisi dari arsitektur yang ada (hal yang sama juga berlaku untuk Webman / Hyperf); tidak diubah di sini |
| I/O Redis asli, rendering browser, overhead profiling di bawah beban nyata | Di luar cakupan unit test dan loop verifikasi |
| Signature dan semantik adapter untuk Webman / Laravel / ThinkPHP / Hyperf | keempatnya tidak masuk loop verifikasi (loop hanya mencakup enam framework); stub-nya ditulis tangan di dalam paket, di `tests/Stubs/framework-stubs.php`, tanpa pembandingan dengan paket asli |

**Daftar periksa smoke manual (tiga langkah per framework)**

| Langkah | Tindakan | Yang diharapkan |
|------|--------|----------|
| 1 | Pasang kelas entri seperti dijelaskan di "Konfigurasi Framework" | Tidak ada error |
| 2 | Buka URL aplikasi mana pun | Panjang key `xhprof:run_id` di Redis naik 1 |
| 3 | Buka `/xhprof` | Halaman report ter-render dengan gayanya; `/xhprof-assets/js/xhprof_report.js` mengembalikan 200 |

**Keterbatasan yang diketahui: `host()` tidak punya port**

Kontrak `host()` berarti "hanya host, tanpa port", tetapi tautan di daftar laporan disusun sebagai `host() . uri()` (`src/Core/XhprofLib/Utils/XHProfRunsDefault.php`). Jadi **pada port non-standar (misalnya `:8080`) tautan daftarnya kehilangan port dan tidak menuju ke mana-mana**. Ini masalah laten pada implementasi yang ada (menimpa webman / Laravel / ThinkPHP / Hyperf secara setara), tidak diperbaiki di sini, dan dicatat sebagai keterbatasan yang diketahui.

**Keterbatasan yang diketahui: `assets_url` hanya bekerja kalau nilainya `/xhprof-assets`**

Prefiks aset adalah konstanta yang di-hardcode di `src/Core/StaticController.php` (`private const URI_PREFIX = '/xhprof-assets'`), sedangkan tautan CSS/JS halaman report membaca opsi konfigurasi `assets_url` (`src/Core/Xhprof.php`). Begitu keduanya tidak cocok, `getPathFromRequest()` mengembalikan `null` dan `serve()` mengembalikan `withBody('')->withHeaders([])` — **respons 200 yang kosong, bukan 404**. Akibatnya: isi `assets_url` dengan apa pun selain nilai default dan CSS/JS-nya diam-diam jadi kosong, sehingga halaman report tampil tanpa gaya tanpa error apa pun. Dengan kata lain, `assets_url` saat ini adalah opsi palsu yang hanya bekerja bila dibiarkan pada nilai defaultnya. Ini masalah yang sudah ada sebelumnya dan tidak diperbaiki di sini.

**Keterbatasan yang diketahui: penjaga path gagal kalau Drupal berada di subdirektori**

Kalau Drupal dipasang di bawah subdirektori (misalnya `/sites/app/xhprof`), penjaga path tidak bisa mencocokkan URI yang membawa base path, sehingga perilakunya jatuh kembali ke "diprofilkan tetapi tidak disimpan" (dengan konfigurasi default, `ignore_url_arr` yang menangkapnya). Ini kelas keterbatasan yang sama dengan prefiks `assets_url` yang di-hardcode.

**Kompatibilitas Symfony 6.4**

Kompatibilitas Symfony 6.4 sudah diukur (dari situlah dua over-fit yang tak terlihat di 7.4 diperbaiki: properti `Request` tidak membawa deklarasi tipe native di 6.4, dan charset yang ditambahkan `prepare()` berbeda huruf besar-kecilnya), tetapi loop verifikasi CI hanya menjalankan 7.4.

---

## Penulis

[erik](https://erik.xyz)

## Dukung Open Source

<p align="center">
  <img src="./docs/weixinpay.png" alt="WeChat Pay" width="130" height="130" title="WeChat Pay" />
  <img src="./docs/alipay.png" alt="Alipay" width="130" height="130" title="Alipay" />
</p>

---

Plugin ini merujuk pada [phacility/xhprof](https://github.com/phacility/xhprof) dan [xiexianbo123/xhprof](https://github.com/xiexianbo123/xhprof).
