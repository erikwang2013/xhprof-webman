# Perfilador de rendimiento XHProf

Un plugin de perfilado de rendimiento de código compatible con webman / Laravel / ThinkPHP / Hyperf / Yii3 / Symfony / Slim 4 / WordPress / Joomla y Drupal.

Recoge datos de perfilado mediante la extensión xhprof y los guarda en Redis. Los desarrolladores pueden consultar rápidamente informes de análisis de rendimiento desde el navegador para localizar cuellos de botella en el código.

**Registro de peticiones**

![Registro de peticiones](docs/images/runs-list.png)

**Informe de una ejecución**

![Informe de una ejecución](docs/images/run-report.png)

## Requisitos

- PHP >= 8.0
- extensión xhprof
- extensión redis
- servidor Redis

## Frameworks compatibles y versiones mínimas

| Framework | Versión mínima | PHP mínimo | Clase de entrada | Cómo montarlo |
|-----------|----------------|-------------|-------------|--------------|
| webman | `workerman/webman ^2.1` | 8.0 | `Webman\XhprofMiddleware` | Registra el middleware global en `config/middleware.php` |
| Laravel | `laravel/framework ^9.0\|^10.0\|^11.0` | 8.0 | `Laravel\Middleware` | Registra el middleware global en `app/Http/Kernel.php` |
| ThinkPHP | `topthink/framework ^6.0\|^8.0` | 8.0 | `Thinkphp\Middleware` | Registra el middleware global en `app/middleware.php` |
| Hyperf | `hyperf/framework ^3.0` | 8.0 | `Hyperf\Middleware` | Se registra solo mediante ConfigProvider |
| Yii3 | `yiisoft/middleware-dispatcher ^5.0` | 8.1 | `Yii3\XhprofMiddleware` | Regístralo en `config/web/di/application.php`; debe ser el primero de la lista de middlewares |
| Symfony | `symfony/http-kernel ^6.4\|^7.0` | 8.1 (6.4) / 8.2 (7.x) | `Symfony\XhprofListener` | Añade la etiqueta `kernel.event_subscriber` en `config/services.yaml` |
| Slim 4 | `slim/slim ^4.12` | 8.0 | `Slim\XhprofMiddleware` | `$app->add(...)`, debe añadirse el último |
| WordPress | 6.4+ | 8.0 | `Wordpress\XhprofPlugin` | Cópialo en `wp-content/mu-plugins/` |
| Joomla | 4.4 / 5.x | 8.1 | `Joomla\Extension\Xhprof` | Cópialo en `plugins/system/` e instálalo con Descubrir |
| Drupal | 10.x / 11.x | 8.1 (10.x) / 8.3 (11.x) | módulo `xhprof` (`Drupal\XhprofMiddleware`) | Módulo estándar: basta con activarlo |

Todas las clases de entrada viven bajo el prefijo de espacio de nombres `ErikWang2013\Xhprof\` (omitido arriba). De los seis frameworks nuevos, Drupal es la excepción — sirve la página de informe mediante rutas de módulo — mientras que las otras cinco clases de entrada **sirven la página de informe ellas mismas**, sin necesidad de controlador ni de registrar rutas.

Este paquete declara `php >= 8.0`, pero los componentes `yiisoft/*` de los que depende Yii3 exigen **PHP 8.1+**, así que **Yii3 no se puede usar en PHP 8.0**; Symfony 7.x y Drupal 11.x también necesitan una versión de PHP superior. La configuración paso a paso está en «Configuración por framework», más abajo.

## Instalación

Añade la configuración de xhprof en php.ini:

```ini
[xhprof]
extension=xhprof.so
xhprof.output_dir=/tmp/xhprof
```

Instala con Composer:

```sh
composer require aaron-dev/xhprof-webman
```

---

## Configuración por framework

### Webman

**1. Registra el middleware global** — `config/middleware.php`:

```php
return [
    '' => [
        ErikWang2013\Xhprof\Webman\XhprofMiddleware::class,
    ],
];
```

**2. Crea el controlador**:

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

**3. Registra las rutas** — `config/route.php`:

```php
use Webman\Route;
use ErikWang2013\Xhprof\Webman\StaticController;

Route::get('/xhprof', [app\controller\XhprofController::class, 'index']);
Route::get('/xhprof-assets/{path:.+}', [StaticController::class, 'serve']);

```

**4. Configuración** — Consulta `config/plugin/aaron-dev/xhprof/xhprof.php`.

---

### Laravel

**1. Registra el middleware** — `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ...
    \ErikWang2013\Xhprof\Laravel\Middleware::class,
];
```

**2. Crea el controlador**:

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

**3. Registra las rutas** — `routes/web.php`:

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

**4. Publica la configuración**:

```sh
php artisan vendor:publish --tag=xhprof-config
```

El archivo de configuración queda en `config/xhprof.php`. Laravel admite el descubrimiento automático del ServiceProvider.

---

### ThinkPHP

**1. Registra el middleware** — `app/middleware.php`:

```php
return [
    \ErikWang2013\Xhprof\Thinkphp\Middleware::class,
];
```

**2. Crea el controlador**:

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

**3. Registra las rutas** — `route/app.php`:

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

**4. Configuración** — Copia `vendor/aaron-dev/xhprof-webman/src/Thinkphp/config/xhprof.php` al `config/xhprof.php` del proyecto.

---

### Hyperf

**1. Registro automático del middleware** — ConfigProvider añade el middleware a la cola de middlewares HTTP automáticamente.

**2. Crea el controlador**:

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

Cuando `Xhprof::index()` devuelve una cadena HTML, **no** la devuelvas directamente con `return`: el `CoreMiddleware::transferToResponse()` de Hyperf añade incondicionalmente `content-type: text/plain` a los valores de retorno de tipo cadena, de modo que el navegador muestra la página de informe como texto plano (verificado idéntico en 3.0.45 / 3.1.69 / 3.2.0). La respuesta explícita de arriba lo evita; si falla la autenticación, `index()` devuelve un objeto de respuesta ya enviado: basta con devolverlo tal cual.

**3. Rutas de recursos estáticos** — `config/routes.php`:

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

**4. Publica la configuración**:

```sh
php bin/hyperf.php vendor:publish aaron-dev/xhprof-webman
```

La configuración se escribe en `config/autoload/xhprof.php`.

---

### Yii3

**1. Registra el middleware** — `config/web/di/application.php`:

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

Dos errores fáciles de cometer (ambos medidos):

- **No** escribas `'__construct()' => ['middlewares' => [...]]`: `MiddlewareDispatcher::__construct()` acepta solo un `MiddlewareFactory` y, opcionalmente, un `EventDispatcherInterface` — **no** existe ningún parámetro `middlewares`. La lista de middlewares solo se puede inyectar mediante el **método de instancia** `withMiddlewares()`.
- **No** pongas una instancia (`new XhprofMiddleware(...)`) dentro de `withMiddlewares()`: las definiciones aceptan solo un class-string, una definición en array o un callable. Con una instancia, el registro no informa de nada y `dispatch()` lanza un `TypeError` (`MiddlewareFactory::create()` está tipado como `callable|array|string`).

**2. Página de informe y recursos estáticos** — **no hace falta controlador ni registrar rutas**: `XhprofMiddleware` es un middleware PSR-15. Antes de empezar a perfilar inspecciona la ruta de la petición: un acierto en la ruta de informe `/xhprof` devuelve la página de informe de inmediato, y un acierto en la ruta de recursos (prefijo por defecto `/xhprof-assets`) devuelve el recurso estático directamente. La respuesta de la página de informe lleva un `Content-Type: text/html; charset=UTF-8` explícito puesto por la clase de entrada: las respuestas PSR-7 no traen ninguno por defecto y el emisor de respuestas de Yii3 no lo añade, así que sin él los navegadores muestran el informe HTML como texto plano.

**3. Configuración** — los valores por defecto están en el paquete, en `src/Yii3/config/xhprof.php`; consulta «Referencia de configuración» para ver los campos. Para sobrescribirlos, inyecta `$config` por DI:

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

El subarray `redis` es específico de Yii3: cuando no se inyecta ningún `CacheInterface`, el middleware lo usa para hablar directamente con phpredis. Deja `assets_url` en su valor por defecto — cualquier otro prefijo solo produce un 200 con el cuerpo vacío (es una constante fija en Core; consulta [Verificación y limitaciones conocidas](#verificación-y-limitaciones-conocidas)).

**4. Requisito de versión** — los componentes `yiisoft/*` de los que depende Yii3 exigen PHP >= 8.1. Aunque este paquete declara `php >= 8.0`, la integración con Yii3 no se puede usar en PHP 8.0.

---

### Symfony

**1. Registra el suscriptor de eventos** — `config/services.yaml`:

```yaml
services:
    ErikWang2013\Xhprof\Symfony\XhprofListener:
        tags:
            - { name: kernel.event_subscriber }
```

**2. Página de informe y recursos estáticos** — **no hace falta controlador ni registrar rutas**: antes de empezar a perfilar, el listener inspecciona la ruta de la petición: un acierto en la ruta de informe `/xhprof` devuelve la página de informe de inmediato, y un acierto en la ruta de recursos (prefijo por defecto `/xhprof-assets`) devuelve el recurso estático directamente.

**3. Configuración** — los valores por defecto están en el paquete, en `src/Symfony/config/xhprof.php`; consulta «Referencia de configuración» para ver los campos.

**4. Subpeticiones y respaldo ante excepciones** — escucha en `kernel.request` (prioridad 10000) y `kernel.response` (prioridad -10000). `isMainRequest()` filtra las subpeticiones ESI/fragment, que de lo contrario detendrían el perfilado demasiado pronto; además se registra un `register_shutdown_function` idempotente al empezar la petición — si HttpKernel relanza una excepción, `kernel.response` nunca se dispara, y sin ese respaldo el estado de perfilado se filtraría a la petición siguiente.

---

### Slim 4

**1. Registra el middleware** — `public/index.php`:

```php
use ErikWang2013\Xhprof\Slim\XhprofMiddleware;

$app->addRoutingMiddleware();

// must be added last: Slim's middleware stack is LIFO, added later = further out = runs first
$app->add(new XhprofMiddleware(
    $app->getResponseFactory()
));
```

Los otros tres argumentos del constructor son todos opcionales; omítelos para usar los valores por defecto del paquete:

- Argumento 2, `array $config`: tu array de configuración, combinado sobre el `src/Slim/config/xhprof.php` del paquete con `array_replace` (reemplazo del valor completo — las claves de lista como `ignore_url_arr` nunca se combinan de forma recursiva).
- Argumento 3, `CacheInterface $cache`: si se omite, hace `new \Redis()` de forma perezosa (el constructor nunca toca ext-redis a propósito, para que una extensión ausente no reviente mientras se construye el adaptador). Para inyectar tu propia conexión pasa `new \ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter($redis)`, o cualquier objeto que implemente `ErikWang2013\Xhprof\Core\Contract\CacheInterface`.
- Argumento 4, `LoggerInterface $logger`: si se omite, es `new \ErikWang2013\Xhprof\Slim\Adapter\LogAdapter()` y **los logs se descartan en silencio** (Slim no trae ningún logger PSR-3). Para registrarlos pasa `new LogAdapter($psrLogger)`, donde `$psrLogger` es un logger PSR-3 que ya tengas.

**No escribas `$app->add(XhprofMiddleware::class)`**: el `CallableResolver` de Slim convierte ese class-string en `new XhprofMiddleware($container)` — pasándole solo el contenedor y aplazando la resolución hasta el momento de la petición. El resultado es que `add()` no informa de nada y la primera petición lanza un `TypeError` — el modo de fallo más difícil de diagnosticar. Usa siempre el `new` explícito de arriba.

**2. Página de informe y recursos estáticos** — **no hace falta controlador ni registrar rutas**: antes de empezar a perfilar el middleware inspecciona la ruta de la petición: un acierto en la ruta de informe `/xhprof` devuelve la página de informe de inmediato, y un acierto en la ruta de recursos (prefijo por defecto `/xhprof-assets`) devuelve el recurso estático directamente.

**3. Configuración** — los valores por defecto están en el paquete, en `src/Slim/config/xhprof.php`; consulta «Referencia de configuración» para ver los campos.

**4. Orden de montaje** — la pila de middlewares de Slim es LIFO (medido con dos middlewares, el orden de ejecución es `B:before → A:before → A:after → B:after`): cuanto más tarde hagas `add()`, más por fuera queda y antes se ejecuta. Así que xhprof debe añadirse **el último**, y **después de `addRoutingMiddleware()`** — de lo contrario `/xhprof` no está en la tabla de rutas, RoutingMiddleware lanza `HttpNotFoundException` primero y la petición nunca llega al middleware. La respuesta de la página de informe lleva un `Content-Type: text/html; charset=UTF-8` explícito puesto por la clase de entrada: las respuestas PSR-7 no traen ninguno por defecto y el `ResponseEmitter` de Slim no lo añade, así que sin él los navegadores muestran el informe HTML como texto plano.

---

### WordPress

**1. Instala el mu-plugin** — copia el archivo de arranque del paquete en `wp-content/mu-plugins/`:

```sh
cp vendor/aaron-dev/xhprof-webman/wordpress/xhprof-webman.php wp-content/mu-plugins/
```

`wordpress/xhprof-webman.php` lleva una cabecera de plugin y arranca `Wordpress\XhprofPlugin`. Los mu-plugins se cargan automáticamente — no hay nada que activar en wp-admin.

**2. Página de informe y recursos estáticos** — **no hace falta controlador ni registrar rutas**: antes de empezar a perfilar la clase de entrada inspecciona la ruta de la petición: un acierto en la ruta de informe `/xhprof` devuelve la página de informe de inmediato, y un acierto en la ruta de recursos (prefijo por defecto `/xhprof-assets`) devuelve el recurso estático directamente.

**3. Configuración** — los valores por defecto están en el paquete, en `src/Wordpress/config/xhprof.php`; consulta «Referencia de configuración» para ver los campos. Usa `ignore_url_arr` para excluir rutas de alta frecuencia como `wp-cron.php` y `admin-ajax.php`.

**4. Un límite estructural de la ventana de perfilado** — la ventana va de `plugins_loaded` a `shutdown`, lo que **no incluye** el arranque de `wp-settings.php` ni la carga de plugins en sí. Es un límite estructural de WordPress: el trabajo hecho en esa fase no se puede perfilar.

---

### Joomla

**1. Instala el plugin** — copia el directorio `joomla/` del paquete en el `plugins/system/xhprof/` del sitio:

```sh
cp -r vendor/aaron-dev/xhprof-webman/joomla/ plugins/system/xhprof/
```

El directorio `joomla/` del paquete *es* el plugin: el manifiesto `xhprof.xml`, `services/provider.php` y `src/Extension/Xhprof.php`. La clase de entrada es `ErikWang2013\Xhprof\Joomla\Extension\Xhprof` (un `CMSPlugin`). Después de copiar, ejecuta Descubrir en «Sistema → Gestionar → Extensiones → Descubrir» e instálalo/actívalo.

**2. Página de informe y recursos estáticos** — **no hace falta controlador ni registrar rutas**: antes de empezar a perfilar el plugin inspecciona la ruta de la petición: un acierto en la ruta de informe `/xhprof` devuelve la página de informe de inmediato, y un acierto en la ruta de recursos (prefijo por defecto `/xhprof-assets`) devuelve el recurso estático directamente.

**3. Configuración** — los valores por defecto están en el paquete, en `src/Joomla/config/xhprof.php`; consulta «Referencia de configuración» para ver los campos. **Compensación conocida**: esto lee el archivo de configuración del paquete en lugar de los parámetros del plugin — los parámetros del plugin requieren una lectura de base de datos, y la configuración se lee en cada petición.

**4. Fronteras del perfilado** — la ventana va de `ApplicationEvents::AFTER_INITIALISE` a `ApplicationEvents::AFTER_RESPOND`; además se registra un `register_shutdown_function` idempotente en `AFTER_INITIALISE`, porque no está garantizado que se alcance `AFTER_RESPOND` en la ruta de excepción — sin el respaldo, el estado de perfilado se filtraría a la petición siguiente.

---

### Drupal

**1. Activa el módulo** — `drupal/xhprof/` en el paquete es un módulo estándar de Drupal (`xhprof.info.yml` / `xhprof.routing.yml` / `xhprof.services.yml`). Colócalo en `modules/custom/xhprof/` de tu sitio y actívalo en la página «Ampliar» (o con `drush en xhprof`).

**2. Página de informe** — Drupal es **el único de los diez frameworks que sirve la página de informe mediante rutas de módulo**: `xhprof.routing.yml` registra la ruta de informe `/xhprof` y un controlador del módulo la renderiza. Las otras cinco clases de entrada nuevas sirven la página de informe y los recursos estáticos ellas mismas y no registran ninguna ruta.

**3. Configuración** — la configuración es configuración tipada a nivel de módulo: los valores por defecto están en `drupal/xhprof/config/install/xhprof.settings.yml`, con el esquema en `drupal/xhprof/config/schema/xhprof.schema.yml`. Consulta «Referencia de configuración» para ver los campos.

**4. Registro del middleware** — registra el servicio del middleware en el `xhprof.services.yml` del módulo:

```yaml
services:
  xhprof.http_middleware:
    class: ErikWang2013\Xhprof\Drupal\XhprofMiddleware
    arguments: ['@config.factory', '@logger.factory']
    tags:
      - { name: http_middleware, priority: 1000 }
```

El kernel interno lo **antepone automáticamente `StackedKernelPass` de Drupal como argumento 0 del constructor** — **no lo escribas tú**: hacerlo produce dos kernels internos, lo que falla en tiempo de compilación del contenedor en Drupal <= 11.2.x (incluido todo 10.x) y lanza un TypeError en cada petición en 11.3.0 y superiores. El perfilado se detiene en `finally`.

**5. Notas**

- `priority: 1000` coloca el middleware **fuera de la caché de página** (la prioridad más alta que ya existe en el core es negotiation: 400 en D10, 500 en D11, mientras que la caché de página es 200), así que **las peticiones servidas desde la caché de página de Drupal también se perfilan**. Para una herramienta de perfilado ese es el comportamiento buscado, pero los usuarios deberían saberlo.
- La caché funciona desde el primer momento: el middleware usa por defecto el adaptador Redis que viene con este paquete (el paquete depende obligatoriamente de ext-redis), y también acepta un argumento `CacheInterface` opcional mediante `arguments` en `services.yml` para sobrescribirlo. Si la caché no está disponible, un guardado fallido se traga dentro de `XhprofProfiler::stop()` en una sola línea de log — **no se lanza ningún error**.
- Las peticiones a la página de informe `/xhprof` y a `/xhprof-assets/*` **no se perfilan**: el middleware omite el perfilado por ruta antes de `xhprofStart()`. La respuesta la sigue produciendo el Controller de `xhprof.routing.yml` (**esto no es un cortocircuito**). Así que incluso con `ignore_url_arr` a `[]` (sin filtrar nada), estas dos peticiones nunca aparecen en el informe.

---

## Referencia de configuración

Todos los frameworks comparten estas opciones de configuración:

| Configuración | Tipo | Por defecto | Descripción |
|--------|------|---------|-------------|
| `enable` | bool | `true` | Activa/desactiva el perfilado |
| `time_limit` | int | `0` | Perfila solo las peticiones que superen n segundos; 0 significa todas |
| `log_num` | int | `1000` | Número máximo de registros |
| `view_wtred` | int | `3` | Resalta en rojo las filas con tiempo de respuesta > n segundos |
| `ignore_url_arr` | array | `["/xhprof"]` | Rutas de URL que se ignoran |
| `assets_url` | string | `/xhprof-assets` | Prefijo de URL de los recursos estáticos |
| `auth_token` | string\|null | `null` | Si se define, la página de informe exige `?token=xxx`; recomendado en despliegues públicos |
| `key_prefix` | string | `xhprof` | Prefijo de las claves de Redis; usa valores distintos por proyecto si compartes un mismo Redis |
| `log_ttl` | int | `604800` | Retención de datos en segundos (por defecto 7 días) |
| `locale` | string\|null | `null` | Idioma de la página de informe: `zh_CN`/`en`/`ko`/`ru`/`de`/`fr`/`es`/`pt`/`ar`/`hi`/`bn`/`id`/`ja`; `null` = seguir el `Accept-Language` del navegador y, si no coincide, usar chino; `?lang=xx` lo sobrescribe en una petición |

Las limitaciones conocidas de estas opciones en cada framework están en [Verificación y limitaciones conocidas](#verificación-y-limitaciones-conocidas).

---

## Inicialización manual

Si la detección automática de framework falla, puedes inyectar los adaptadores a mano:

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

**Los seis frameworks nuevos (Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal) no deben llamar a `Xhprof::bootstrap()` sin argumentos** — sin argumentos pasa por `autoDetect()`, que solo conoce las ramas de webman / Laravel / ThinkPHP / Hyperf y lanza `Unsupported framework` en estos seis. Pasa los 5 adaptadores explícitamente como en el ejemplo de arriba (la clase de entrada que viene con cada framework ya lo hace por ti).

---

## Arquitectura y diseño

Core llega al framework a través de exactamente 5 contratos, todos en `src/Core/Contract/`:

| Contrato | Métodos | Propósito |
|----------|---------|---------|
| `RequestInterface` | `get()` `all()` `method()` `header()` `host()` `uri()` `url()` `getRealIp()` | Leer los datos de la petición, casar la ruta del informe, construir los enlaces de la página de informe |
| `ResponseInterface` | `withBody()` `withHeaders()` `withStatus()` `file()` `send()` | Emitir la página de informe, los recursos estáticos y los 400/403 |
| `ConfigInterface` | `get()` | Leer la configuración del plugin: `get('xhprof')` para el bloque entero, `get('xhprof.assets_url')` para una hoja |
| `CacheInterface` | `get()` `set()` `mget()` `incr()` `lPush()` `rPop()` `lRange()` `del()` `decr()` | Lecturas y escrituras en Redis |
| `LoggerInterface` | `error()` | Avisos por extensiones ausentes y guardados fallidos |

Cada framework aporta 5 adaptadores que implementan estos contratos, registrados en Core por `Xhprof::bootstrap()`. Todo lo específico de un framework se queda dentro del directorio `src/<Fw>/` de ese framework.

**En Core solo quedan dos acoplamientos a frameworks**:

1. La cadena de `class_exists()` en `Xhprof::autoDetect()` (`Webman\App` → `Illuminate\Foundation\Application` → `think\App` → `Hyperf\Context\ApplicationContext`), a la que solo se llega con el `bootstrap()` sin argumentos.
2. El conmutador de corrutinas de Hyperf hardcodeado: `Xhprof::markHyperfContext()` más las comprobaciones de existencia de `\Hyperf\Context\Context`, que deciden si los adaptadores van a propiedades estáticas de todo el proceso o al Context de la corrutina.

**Los seis frameworks nuevos nunca pasan por `autoDetect()` — todos usan inyección explícita**: cada clase de entrada construye sus propios 5 adaptadores y los pasa a `Xhprof::bootstrap($req, $res, $cfg, $cache, $log)`. El motivo es que en los frameworks PSR-7 la Request/Response solo se pueden obtener del pipeline de la petición, así que un `bootstrap()` sin argumentos no puede funcionar por construcción; un segundo beneficio es que `autoDetect()` se queda congelado en sus cuatro frameworks actuales.

![Arquitectura](docs/images/architecture.svg)

El primer diagrama es la **estructura**: la clase de entrada de cada uno de los diez frameworks, los 5 contratos, las tres capas de Core y los dos únicos acoplamientos que quedan.

![Razonamiento de diseño](docs/images/design.svg)

El segundo diagrama es el **razonamiento**: cinco compensaciones ordenadas como decisión / motivo / coste, encabezadas por «cambios en `src/Core/` por los seis frameworks nuevos = 0».

---

## Ciclo de vida de la petición

Una petición perfilada:

1. **Antes de que empiece el perfilado**, la clase de entrada inspecciona la ruta: un acierto en la ruta de informe devuelve la página de informe de inmediato; un acierto en la ruta de recursos devuelve el recurso estático de inmediato. Ninguna de las dos rutas se perfila, y ninguna entra en el flujo de abajo.
2. `XhprofProfiler::isEnabled()` lee `enable` de la configuración; si el perfilado está apagado o falta una extensión, se salta el bloque entero.
3. `Xhprof::xhprofStart()` → `XhprofProfiler::start()` → `xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY)`.
4. Se ejecuta la lógica de negocio.
5. `finally { Xhprof::xhprofStop(); }` → `xhprof_disable()`, y después `XHProfRunsDefault::save_run()` escribe en Redis. `finally` en lugar de una sentencia normal, para que una excepción lanzada siga limpiando el estado de perfilado y guardando el run.
6. El navegador abre la página de informe; `Xhprof::index()` vuelve a leer los datos de Redis y los renderiza.

![Ciclo de vida](docs/images/lifecycle.svg)

| Framework | Empieza el perfilado | Termina el perfilado |
|-----------|-----------------|----------------|
| webman / Laravel / ThinkPHP / Hyperf | Entrada del middleware (`process()` / `handle()`) | `finally` |
| Yii3 / Slim 4 | `process()` de PSR-15 | `finally` |
| Symfony | `kernel.request` (prioridad 10000) | `kernel.response` (prioridad -10000), más un respaldo de shutdown |
| WordPress | `plugins_loaded` | `shutdown` |
| Joomla | `onAfterInitialise` | `onAfterRespond`, más un respaldo de shutdown |
| Drupal | `http_middleware` (prioridad 1000, el más externo) | `finally` |

---

## Estructura del proyecto

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
├── tools/i18n/                   # translation toolchain for the README and the three SVGs (generate / check / selftest)
├── docs/i18n/                    # the 12 translated deliverables (English, Korean, Russian, German, French, Spanish, Portuguese, Arabic, Hindi, Bengali, Indonesian, Japanese)
├── tests/                        # PHPUnit: adapter tests, wiring tests, Core tests, structural parity across all 14 READMEs
└── docs/images/                  # README diagrams
```

Salvo Drupal, todos los directorios `src/<Fw>/` tienen la misma forma:

```
src/<Fw>/
├── Adapter/{Request,Response,Config,Redis,Log}Adapter.php
├── <EntryClass>.php
└── config/xhprof.php             # the same 10 config keys as every other framework
```

`src/Drupal/` es la única excepción: no tiene directorio `config/` — su configuración vive en configuración tipada a nivel de módulo (`drupal/xhprof/config/install/xhprof.settings.yml`).

---

## Verificación y limitaciones conocidas

**Qué está demostrado mecánicamente**

| Elemento | Cómo |
|------|-----|
| Comportamiento de los adaptadores y del cableado de entrada | `tests/Unit/Adapter/*Test.php`: activado → guardado / desactivado → no guardado / excepción de negocio → guardado igualmente vía `finally` |
| Los diez frameworks comparten un mismo conjunto de claves de configuración | test de paridad de configuración (conjuntos de claves, no byte a byte; los comentarios pueden diferir) |
| Los dos README se reflejan mutuamente | test de paridad de README: compara la secuencia de encabezados `##` / `###` y el número de bloques de código |
| Los métodos que llaman los adaptadores existen de verdad | bucle de verificación de `tools/contracts/` (con su propio job de CI): instala paquetes reales de framework y comprueba por reflexión que cada método / constante / función global existe **para los seis frameworks del bucle** (Slim / Symfony / Yii3 / Joomla / WordPress / Drupal); Webman / Laravel / ThinkPHP / Hyperf no están en el bucle, véase más abajo |
| Semántica de los adaptadores | El mismo bucle instancia objetos reales de petición y respuesta y ejecuta los adaptadores, incluidas dos invariantes: `uri()` no lleva esquema ni host, y `withHeaders()` sigue aplicándose después de `file()` |


**No verificado automáticamente (no lo leas como «se probaron los seis»)**

| Elemento | Por qué no |
|------|---------|
| El **cableado** de cada framework (si el hook está realmente enganchado, si el evento se dispara de verdad) | Los tests unitarios usan stubs; el cableado hoy solo se puede confirmar con pruebas de humo manuales |
| WordPress de principio a fin | El momento real de `plugins_loaded`, si `shutdown` se dispara ante un error fatal, y si el mu-plugin se carga, exigen un WordPress real |
| El descubrimiento del plugin de Joomla y `$app->close()` | Requiere ejecutar Descubrir en un admin de Joomla real |
| Si la prioridad de Drupal cae de verdad fuera de la caché de página | Requiere un kernel de Drupal arrancado |
| La autoconfiguración de `kernel.event_subscriber` de Symfony | Requiere una compilación real del contenedor |
| Interferencia de estado estático en procesos de larga duración | Heredada de la arquitectura existente (lo mismo ocurre en Webman / Hyperf); sin cambios aquí |
| E/S real con Redis, renderizado en navegador, sobrecarga del perfilado bajo carga real | Fuera del alcance de los tests unitarios y del bucle de verificación |
| Firmas y semántica de los adaptadores de Webman / Laravel / ThinkPHP / Hyperf | estos cuatro no están en el bucle de verificación (que cubre seis frameworks); sus stubs están escritos a mano en `tests/Stubs/framework-stubs.php`, sin comparación con paquetes reales |

**Lista de comprobación manual (tres pasos por framework)**

| Paso | Acción | Esperado |
|------|--------|----------|
| 1 | Monta la clase de entrada como se describe en «Configuración por framework» | Ningún error |
| 2 | Llama a cualquier URL de la aplicación | La longitud de la clave `xhprof:run_id` en Redis sube en 1 |
| 3 | Abre `/xhprof` | La página de informe se renderiza con sus estilos; `/xhprof-assets/js/xhprof_report.js` devuelve 200 |

**Limitación conocida: `host()` no lleva puerto**

El contrato `host()` significa «solo host, sin puerto», pero los enlaces de la lista del informe se construyen como `host() . uri()` (`src/Core/XhprofLib/Utils/XHProfRunsDefault.php`). Así que **en un puerto no estándar (por ejemplo `:8080`) los enlaces de la lista pierden el puerto y no llevan a ninguna parte**. Es un problema latente de la implementación existente (afecta por igual a webman / Laravel / ThinkPHP / Hyperf), no se corrige aquí y queda registrado como limitación conocida.

**Limitación conocida: `assets_url` solo funciona cuando vale `/xhprof-assets`**

El prefijo de recursos es una constante fija en `src/Core/StaticController.php` (`private const URI_PREFIX = '/xhprof-assets'`), mientras que los enlaces CSS/JS de la página de informe leen la opción de configuración `assets_url` (`src/Core/Xhprof.php`). En cuanto los dos discrepan, `getPathFromRequest()` devuelve `null` y `serve()` devuelve `withBody('')->withHeaders([])` — **una respuesta 200 vacía, no un 404**. La consecuencia: pon `assets_url` con cualquier otro valor y el CSS/JS se queda vacío en silencio, dejando la página de informe sin estilos y sin ningún tipo de error. Dicho de otro modo, `assets_url` es hoy una opción falsa que solo funciona si se deja en su valor por defecto. Es un problema preexistente y no se corrige aquí.

**Limitación conocida: la guarda de ruta falla cuando Drupal vive en un subdirectorio**

Cuando Drupal está instalado bajo un subdirectorio (por ejemplo `/sites/app/xhprof`), la guarda de ruta no puede casar una URI que lleve la ruta base, así que el comportamiento cae a «perfilado pero no guardado» (con la configuración por defecto, `ignore_url_arr` lo atrapa). Es la misma clase de limitación que el prefijo `assets_url` hardcodeado.

**Compatibilidad con Symfony 6.4**

La compatibilidad con Symfony 6.4 se ha medido (así se corrigieron dos sobreajustes invisibles en 7.4: las propiedades de `Request` no llevan declaración de tipo nativa en 6.4, y el charset que añade `prepare()` difiere en mayúsculas/minúsculas), pero el bucle de verificación de CI solo ejecuta 7.4.

---

## Autor

[erik](https://erik.xyz)

## Apoya el código abierto

<p align="center">
  <img src="./docs/weixinpay.png" alt="WeChat Pay" width="130" height="130" title="WeChat Pay" />
  <img src="./docs/alipay.png" alt="Alipay" width="130" height="130" title="Alipay" />
</p>

---

Este plugin hace referencia a [phacility/xhprof](https://github.com/phacility/xhprof) y [xiexianbo123/xhprof](https://github.com/xiexianbo123/xhprof).
