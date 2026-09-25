# Perfilador de rendimiento XHProf

Un plugin de perfilado de rendimiento de código compatible con webman / Laravel / ThinkPHP / Hyperf / Yii3 / Symfony / Slim 4 / WordPress / Joomla y Drupal.

Recoge datos de perfilado mediante la extensión xhprof y los guarda en Redis. Los desarrolladores pueden consultar rápidamente informes de análisis de rendimiento desde el navegador para localizar cuellos de botella en el código.

![Mascota del proyecto: pequeña llama](docs/images/pet.svg)

Esa misma llamita es también el icono del sitio y el icono de marca de la esquina superior izquierda de la página de informe (`src/html/pet.svg`, servido bajo el prefijo `assets_url`).

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
| PHP puro (sin framework) | — (sin paquete externo) | 8.0 | `Native\XhprofBootstrap` | Una línea al principio del archivo de entrada, `XhprofBootstrap::start()`, sin registrar controlador ni rutas |

Todas las clases de entrada viven bajo el prefijo de espacio de nombres `ErikWang2013\Xhprof\` (omitido arriba).  Ninguno de los once necesita que registres un controlador o una ruta: la página de informe y los recursos estáticos los sirve la propia clase de entrada (en el caso de Drupal, la ruta del módulo).

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

**2. Página de informe y recursos estáticos** — **no hace falta controlador ni registrar rutas**: antes de que empiece el perfilado, el middleware inspecciona la ruta de la petición: un acierto en la ruta de informe `/xhprof` devuelve la página de informe al momento, y un acierto en la ruta de recursos (prefijo leído de la opción `assets_url`, por defecto `/xhprof-assets`) devuelve directamente el recurso estático.

**3. Configuración** — Consulta `config/plugin/aaron-dev/xhprof/xhprof.php`.

---

### Laravel

**1. Registra el middleware** — `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ...
    \ErikWang2013\Xhprof\Laravel\Middleware::class,
];
```

**2. Página de informe y recursos estáticos** — **no hace falta controlador ni registrar rutas**: antes de que empiece el perfilado, el middleware inspecciona la ruta de la petición: un acierto en la ruta de informe `/xhprof` devuelve la página de informe al momento, y un acierto en la ruta de recursos (prefijo leído de la opción `assets_url`, por defecto `/xhprof-assets`) devuelve directamente el recurso estático.

**3. Publica la configuración**:

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

**2. Página de informe y recursos estáticos** — **no hace falta controlador ni registrar rutas**: antes de que empiece el perfilado, el middleware inspecciona la ruta de la petición: un acierto en la ruta de informe `/xhprof` devuelve la página de informe al momento, y un acierto en la ruta de recursos (prefijo leído de la opción `assets_url`, por defecto `/xhprof-assets`) devuelve directamente el recurso estático.

**3. Configuración** — Copia `vendor/aaron-dev/xhprof-webman/src/Thinkphp/config/xhprof.php` al `config/xhprof.php` del proyecto.

---

### Hyperf

**1. Registro automático del middleware** — ConfigProvider añade el middleware a la cola de middlewares HTTP automáticamente.

**2. Página de informe y recursos estáticos** — **no hace falta controlador ni registrar rutas**: antes de que empiece el perfilado, el middleware inspecciona la ruta de la petición: un acierto en la ruta de informe `/xhprof` devuelve la página de informe al momento, y un acierto en la ruta de recursos (prefijo leído de la opción `assets_url`, por defecto `/xhprof-assets`) devuelve directamente el recurso estático.

**3. Publica la configuración**:

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

El subarray `redis` es específico de Yii3: cuando no se inyecta ningún `CacheInterface`, el middleware lo usa para hablar directamente con phpredis. Deja `assets_url` puede ser cualquier prefijo: los enlaces CSS/JS de la página de informe y `StaticController` leen la misma opción (por defecto `/xhprof-assets`). Las limitaciones restantes en un despliegue en subdirectorio están en [Verificación y limitaciones conocidas](#verificación-y-limitaciones-conocidas).

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

**2. Página de informe y recursos estáticos** — Drupal es **el único de los once frameworks que va por «módulo + rutas»**: `xhprof.routing.yml` registra la ruta de informe `/xhprof` y la de recursos `/xhprof-assets`, servidas por defecto por el controlador del módulo; las otras diez clases de entrada cortocircuitan antes de empezar el perfilado y sirven la página de informe y los recursos estáticos sin registrar rutas. **Con un prefijo `assets_url` propio, los recursos pasan al middleware**: la ruta de recursos del módulo está fijada en `xhprof.routing.yml` (`/xhprof-assets/{file}`) y nunca casa con otro prefijo.

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

### PHP puro (sin framework)

Para aplicaciones sin framework, con un solo controlador frontal (como `public/index.php`).

**1. Añade una línea al principio del archivo de entrada**:

```php
\ErikWang2013\Xhprof\Native\XhprofBootstrap::start();
```

Para cambiar la configuración, pasa el array a esa misma línea (el conjunto de claves es el mismo que en los otros diez; los valores por defecto están en `src/Native/config/xhprof.php`):

```php
\ErikWang2013\Xhprof\Native\XhprofBootstrap::start([
    'enable' => true,
    'auth_token' => 'xxx',
]);
```

El segundo y el tercer argumento son puntos de inyección opcionales: `CacheInterface $cache` y `LoggerInterface $logger` (por defecto, el adaptador Redis de este paquete y `error_log`). El valor devuelto es la instancia de entrada de esta petición (`stop()` es idempotente); llámalo para detener la medición antes de tiempo en el mismo proceso.

**2. Página de informe y recursos estáticos** — **no hace falta controlador ni registrar rutas**: esta línea inspecciona la ruta de la petición antes de que empiece el perfilado: un acierto en la ruta de informe `/xhprof` devuelve la página de informe al momento (con `Content-Type: text/html; charset=UTF-8` y `Cache-Control: no-cache, private`; `auth_token` sigue vigente), y un acierto en la ruta de recursos (el prefijo se lee de la opción `assets_url`, por defecto `/xhprof-assets`) devuelve el recurso estático directamente. **El coste, dicho claro**: tras atender estas dos rutas se hace `exit` — el resto de la petición (las rutas posteriores a esta línea, el arranque del contenedor, el inicio de sesión y la lógica de cierre que registre la propia aplicación) no se ejecuta.

**3. Ventana de perfilado = esta línea → cierre del proceso** (se registra `register_shutdown_function`). **Los límites, tal cual son**: no incluye el código **anterior** a esta línea (autoload de composer, arranque del controlador frontal) ni lo que hagan otros procesos o extensiones (el análisis de la petición en php-fpm, el tratamiento en el lado de nginx). El final normal, `exit` y los Error / excepciones no capturados llegan al punto de parada; `SIGKILL` / el OOM killer no — el estado del perfilado desaparece con el proceso y no queda para la petición siguiente. Para acotar el alcance está la opción `ignore_url_arr` (coincidencia de subcadena sobre `uri()`, actúa sin tocar el código).

**4. Ejecútalo de verdad una vez con `php -S`**:

```sh
# Arriba en public/index.php está XhprofBootstrap::start(); el archivo es el controlador frontal
php -S 127.0.0.1:8000 -t public public/index.php
```

Visita `http://127.0.0.1:8000/` para generar datos y luego `http://127.0.0.1:8000/xhprof` para ver el informe: ambos en el mismo proceso, y los recursos quedan verificados de paso.

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

**Selector de idioma de la página de informe**

La lista desplegable a la derecha de la navegación enumera los 13 idiomas con su **propio nombre** (el `_meta.name` de cada catálogo, p. ej. 한국어, 日本語). El enlace de cada opción se construye a partir de **la cadena de consulta de la página actual** (`XhprofLib::report_url()`), así que `?token=`, el orden, `run` y todos los demás parámetros viajan con ella; cambiar de idioma **no abandona la vista actual**: en un informe de ejecución se permanece en la misma ejecución.

**Diagnóstico en la página de informe**

La tarjeta más alta del cuerpo del informe es el «Diagnóstico» (justo debajo de la descripción de la ejecución): primero «Por qué es lento» (como máximo 3 causas) y después «Otros hallazgos» (como máximo 3 comprobaciones). El enlace «ver» que sigue a cada conclusión abre la página de detalle de ese método; la recursión (R4) lleva enlace solo cuando el nombre simple está realmente en la tabla de símbolos — xhprof despliega la recursión como `fib@1`/`fib@2`, y si solo existen los nombres desplegados, buscar `fib` no encuentra nada. Las seis reglas y sus umbrales:

- **R1** tiempo exclusivo ≥ 10 % del tiempo total de la petición;
- **R2** número de llamadas ≥ 1000;
- **R3** llamadas de una misma arista ≥ 500 **y** tiempo exclusivo de la función llamada ≥ 5 % del tiempo total de la petición;
- **R4** un mismo símbolo aparece en ≥ 2 profundidades distintas (recursión);
- **R5** memoria máxima propia ≥ 30 % de la máxima global;
- **R6** tiempo exclusivo > tiempo inclusivo (`excl_wt > wt`, lógicamente imposible) — una sonda de integridad de datos que nunca se dispara con datos sanos.

Los umbrales están fijados como constantes en `src/Core/Analysis/Analyzer.php` y hoy **ninguna opción de configuración** puede cambiarlos ni desactivar la tarjeta (con `enable` desactivado no se muestrea nada, así que no hay nada que diagnosticar). Solo aparece **en la vista de ejecución única de primer nivel**: ni la vista diff ni la página de detalle de un método la renderizan — los `$symbol_tab`/`$totals` que reciben no son valores de una sola ejecución (en modo diff son los incrementos run2 − run1).

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

El primer diagrama es la **estructura**: la clase de entrada de cada uno de los once frameworks, los 5 contratos, las tres capas de Core y los dos únicos acoplamientos que quedan.

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
| PHP puro (sin framework) | Una línea `XhprofBootstrap::start()` al principio del archivo de entrada | Cierre del proceso (`register_shutdown_function`); además `stop()` para parar antes |

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
│   ├── Native/                   # PHP puro (sin framework): clase de entrada y 5 adaptadores
│   └── html/                     # report page assets (css / js / images / pet.svg site icon and brand icon)
├── wordpress/                    # mu-plugin bootstrap file (with plugin header)
├── joomla/                       # Joomla plugin (CMSPlugin + manifest)
├── drupal/xhprof/                # standard Drupal module (info / routing / services + controller)
├── tools/contracts/              # standalone verification loop: signatures and semantics against real framework packages (`legacy-symfony64/` es el tramo 6.4)
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
| Los once frameworks comparten un mismo conjunto de claves de configuración | test de paridad de configuración (conjuntos de claves, no byte a byte; los comentarios pueden diferir) |
| Los dos README se reflejan mutuamente | test de paridad de README: compara la secuencia de encabezados `##` / `###` y el número de bloques de código |
| Los métodos que llaman los adaptadores existen de verdad | ciclo de verificación `tools/contracts/` (job de CI propio, **dos tramos**: el tramo principal instala los paquetes más recientes de cada framework y el proyecto aparte `tools/contracts/legacy-symfony64` ejecuta el mismo caso de Symfony contra 6.4): instala paquetes reales de los frameworks (`drupal/core` real para Drupal, dos paquetes de versión reales del CMS para Joomla) y comprueba por reflexión que existan todos los métodos / constantes / funciones globales **para los ocho frameworks del ciclo** (Slim / Symfony / Yii3 / Joomla / WordPress / Drupal / Laravel / Webman); ThinkPHP / Hyperf no están en el ciclo — ver abajo |
| Semántica de los adaptadores | El mismo ciclo instancia objetos reales de petición y respuesta y ejecuta los adaptadores, con dos invariantes: `uri()` no lleva scheme/host, y `withHeaders()` sigue aplicándose después de `file()`. El número de SKIP del ciclo es una constante congelada (2 en el tramo principal, 0 en el tramo 6.4) y ambos SKIP están en Joomla: la ruta de lectura real de `#__extensions.params` y la forma del instalador, y para ejecutar ambas hace falta una base de datos o un instalador |


**No verificado automáticamente (no lo leas como «todo queda cubierto»)**

| Elemento | Por qué no |
|------|---------|
| El **cableado** de cada framework (si el hook está realmente enganchado, si el evento se dispara de verdad) | Los tests unitarios usan stubs; el cableado hoy solo se puede confirmar con pruebas de humo manuales |
| Los dos subpuntos restantes de Joomla | Las dos cosas que el ciclo aún no alcanza, y ambas por el mismo motivo (necesitan base de datos o instalador): la ruta de lectura real de `#__extensions.params` (`PluginHelper::getPlugin()` → `bootPlugin()`) y la forma del instalador (namespacemap escrito, `bootPlugin()` encuentra la clase) |
| La autoconfiguración de `kernel.event_subscriber` de Symfony | Requiere una compilación real del contenedor |
| Interferencia de estado estático en procesos de larga duración | Lado de Webman sin cambios (en Hyperf están aislados: los 9 valores de estado de render por petición pasan por el Context de la corrutina, fijados por `tests/Unit/Lib/RenderStateCoroutineTest.php` con una corrutina que cede de verdad) |
| E/S real con Redis, renderizado en navegador, sobrecarga del perfilado bajo carga real | La E/S real con Redis **ya está en el bucle** (`cases/Redis.php`: phpredis real + una petición Slim real de extremo a extremo — petición → persistencia → lista → página del informe); el renderizado en navegador y la sobrecarga bajo carga real siguen fuera del alcance de los tests unitarios y del bucle |
| Firmas y semántica de los adaptadores de ThinkPHP / Hyperf | estos dos no están en el bucle de verificación (que cubre ocho frameworks); sus stubs están escritos a mano en `tests/Stubs/framework-stubs.php`, sin comparación con paquetes reales |

**Lista de comprobación manual (tres pasos por framework)**

| Paso | Acción | Esperado |
|------|--------|----------|
| 1 | Monta la clase de entrada como se describe en «Configuración por framework» | Ningún error |
| 2 | Llama a cualquier URL de la aplicación | La longitud de la clave `xhprof:run_id` en Redis sube en 1 |
| 3 | Abre `/xhprof` | La página de informe se renderiza con sus estilos; `/xhprof-assets/js/xhprof_report.js` devuelve 200 |

**Prueba de humo para PHP puro**: levanta el servidor integrado con `php -S 127.0.0.1:8000 -t public public/index.php` (paso 4 de «PHP puro») y haz los tres pasos: la página de informe y los recursos están en el **mismo proceso** que las peticiones de negocio, así que el paso 3 se verifica directamente.

**Limitación conocida: el `request_uri` que muestra la lista no lleva puerto**

El contrato `host()` significa «solo host, sin puerto» (R-2), y los once frameworks lo cumplen — solo cambia la implementación: el `getHost()` de PSR-7 nunca lleva el puerto, Joomla / WordPress lo recortan a mano con `parse_url`, y Webman / ThinkPHP necesitan el argumento estricto `host(true)` (el valor por defecto devuelve la cabecera `Host` tal cual, puerto incluido). El `request_uri` que muestra la lista se construye como `host() . uri()` (`src/Core/XhprofLib/Utils/XHProfRunsDefault.php`), así que en un puerto no estándar (por ejemplo `:8080`) el **texto** de esa línea no muestra el puerto. **Los enlaces en sí no se ven afectados**: los de la lista y los del informe los construye `XhprofLib::report_url()` como URL relativas (solo ruta + query), abren la página correcta y no dependen de `host()`.

**`assets_url` ahora admite un prefijo personalizado**

El prefijo de recursos ya no es una constante fija: `src/Core/StaticController.php` casa las rutas de recursos contra la opción `assets_url` (por defecto `/xhprof-assets`, con o sin barra final). La limitación restante en un despliegue en subdirectorio es la de Drupal, más abajo. **Los once frameworks siguen esa opción**: diez clases de entrada cortocircuitan el camino de los recursos antes de empezar el perfilado y los sirven ellas mismas, mientras que Drupal sirve el prefijo por defecto con la ruta del módulo + controlador y entrega el prefijo propio al middleware. **Salvedad**: Laravel, Hyperf, Webman y ThinkPHP ya no necesitan controlador ni rutas — el middleware corre primero, así que las dos rutas registradas según las instrucciones antiguas quedan solo sombreadas: no dan error y ya nunca se alcanzan.

**Limitación conocida: la guarda de ruta falla cuando Drupal vive en un subdirectorio**

Cuando Drupal está instalado bajo un subdirectorio (por ejemplo `/sites/app/xhprof`), la guarda de ruta no puede casar una URI que lleve la ruta base, así que el comportamiento cae a «perfilado pero no guardado» (con la configuración por defecto, `ignore_url_arr` lo atrapa).

**Compatibilidad con Symfony 6.4**

La compatibilidad con Symfony 6.4 se ha medido (así se corrigieron dos sobreajustes invisibles en 7.4: las propiedades de `Request` no llevan declaración de tipo nativa en 6.4, y el charset que añade `prepare()` difiere en mayúsculas/minúsculas). **Los dos tramos corren en CI**: el tramo principal 7.x más el proyecto aparte `tools/contracts/legacy-symfony64`, que ejecuta el mismo archivo de caso sin copiarlo — y ambos tramos también están en el gate de tags.

---

## Autor

[erik](https://erik.xyz)

Este paquete se publica bajo la licencia MIT (véase `LICENSE`); `src/Core/XhprofLib/**`, `src/html/js/xhprof_report.js` y `src/html/css/xhprof.css` derivan de [phacility/xhprof](https://github.com/phacility/xhprof) (Apache-2.0) y siguen bajo sus términos; la lista de bibliotecas front-end de terceros está en `NOTICE`.

## Apoya el código abierto

<p align="center">
  <img src="./docs/weixinpay.png" alt="WeChat Pay" width="130" height="130" title="WeChat Pay" />
  <img src="./docs/alipay.png" alt="Alipay" width="130" height="130" title="Alipay" />
</p>

---

Este plugin hace referencia a [phacility/xhprof](https://github.com/phacility/xhprof) y [xiexianbo123/xhprof](https://github.com/xiexianbo123/xhprof).
