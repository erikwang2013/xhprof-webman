# Profileur de performance XHProf

Un plugin de profilage de performance du code, compatible avec webman / Laravel / ThinkPHP / Hyperf / Yii3 / Symfony / Slim 4 / WordPress / Joomla et Drupal.

Il collecte les données de profilage via l'extension xhprof et les stocke dans Redis. Les développeurs accèdent rapidement, depuis un navigateur, aux rapports d'analyse de performance pour identifier les goulots d'étranglement du code.

**Journal des requêtes**

![Journal des requêtes](docs/images/runs-list.png)

**Rapport d’une exécution**

![Rapport d’une exécution](docs/images/run-report.png)

## Prérequis

- PHP >= 8.0
- extension xhprof
- extension redis
- serveur Redis

## Frameworks compatibles et versions minimales

| Framework | Version minimale | PHP minimal | Classe d'entrée | Comment le monter |
|-----------|----------------|-------------|-------------|--------------|
| webman | `workerman/webman ^2.1` | 8.0 | `Webman\XhprofMiddleware` | Enregistrer le middleware global dans `config/middleware.php` |
| Laravel | `laravel/framework ^9.0\|^10.0\|^11.0` | 8.0 | `Laravel\Middleware` | Enregistrer le middleware global dans `app/Http/Kernel.php` |
| ThinkPHP | `topthink/framework ^6.0\|^8.0` | 8.0 | `Thinkphp\Middleware` | Enregistrer le middleware global dans `app/middleware.php` |
| Hyperf | `hyperf/framework ^3.0` | 8.0 | `Hyperf\Middleware` | Enregistrement automatique via ConfigProvider |
| Yii3 | `yiisoft/middleware-dispatcher ^5.0` | 8.1 | `Yii3\XhprofMiddleware` | À enregistrer dans `config/web/di/application.php`, en premier dans la liste des middlewares |
| Symfony | `symfony/http-kernel ^6.4\|^7.0` | 8.1 (6.4) / 8.2 (7.x) | `Symfony\XhprofListener` | Ajouter le tag `kernel.event_subscriber` dans `config/services.yaml` |
| Slim 4 | `slim/slim ^4.12` | 8.0 | `Slim\XhprofMiddleware` | `$app->add(...)`, à ajouter en dernier |
| WordPress | 6.4+ | 8.0 | `Wordpress\XhprofPlugin` | Copier dans `wp-content/mu-plugins/` |
| Joomla | 4.4 / 5.x | 8.1 | `Joomla\Extension\Xhprof` | Copier dans `plugins/system/`, installer via Discover |
| Drupal | 10.x / 11.x | 8.1 (10.x) / 8.3 (11.x) | module `xhprof` (`Drupal\XhprofMiddleware`) | Module standard, il suffit de l'activer |

Les classes d'entrée vivent toutes sous le préfixe de namespace `ErikWang2013\Xhprof\` (omis ci-dessus). Parmi les six nouveaux frameworks, Drupal fait exception — il sert la page de rapport via les routes du module — tandis que les cinq autres classes d'entrée **servent elles-mêmes la page de rapport**, sans contrôleur ni route à enregistrer.

Ce paquet déclare `php >= 8.0`, mais les composants `yiisoft/*` dont Yii3 dépend exigent **PHP 8.1+**, donc **Yii3 n'est pas utilisable sur PHP 8.0** ; Symfony 7.x et Drupal 11.x demandent eux aussi une version de PHP plus élevée. La mise en place pas à pas se trouve dans « Configuration par framework » ci-dessous.

## Installation

Ajoutez la configuration xhprof dans php.ini :

```ini
[xhprof]
extension=xhprof.so
xhprof.output_dir=/tmp/xhprof
```

Installez via Composer :

```sh
composer require aaron-dev/xhprof-webman
```

---

## Configuration par framework

### Webman

**1. Enregistrer le middleware global** — `config/middleware.php` :

```php
return [
    '' => [
        ErikWang2013\Xhprof\Webman\XhprofMiddleware::class,
    ],
];
```

**2. Créer le contrôleur** :

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

**3. Enregistrer les routes** — `config/route.php` :

```php
use Webman\Route;
use ErikWang2013\Xhprof\Webman\StaticController;

Route::get('/xhprof', [app\controller\XhprofController::class, 'index']);
Route::get('/xhprof-assets/{path:.+}', [StaticController::class, 'serve']);

```

**4. Configuration** — voir `config/plugin/aaron-dev/xhprof/xhprof.php`.

---

### Laravel

**1. Enregistrer le middleware** — `app/Http/Kernel.php` :

```php
protected $middleware = [
    // ...
    \ErikWang2013\Xhprof\Laravel\Middleware::class,
];
```

**2. Créer le contrôleur** :

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

**3. Enregistrer les routes** — `routes/web.php` :

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

**4. Publier la configuration** :

```sh
php artisan vendor:publish --tag=xhprof-config
```

Fichier de configuration dans `config/xhprof.php`. Laravel prend en charge l'auto-découverte du ServiceProvider.

---

### ThinkPHP

**1. Enregistrer le middleware** — `app/middleware.php` :

```php
return [
    \ErikWang2013\Xhprof\Thinkphp\Middleware::class,
];
```

**2. Créer le contrôleur** :

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

**3. Enregistrer les routes** — `route/app.php` :

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

**4. Configuration** — copiez `vendor/aaron-dev/xhprof-webman/src/Thinkphp/config/xhprof.php` vers `config/xhprof.php` du projet.

---

### Hyperf

**1. Enregistrement automatique du middleware** — ConfigProvider ajoute automatiquement le middleware à la file des middlewares HTTP.

**2. Créer le contrôleur** :

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

Lorsque `Xhprof::index()` renvoie une chaîne HTML, **ne pas** la `return` directement : le `CoreMiddleware::transferToResponse()` de Hyperf ajoute inconditionnellement `content-type: text/plain` aux valeurs de retour de type chaîne, si bien que le navigateur affiche la page de rapport comme du texte brut (vérifié identique en 3.0.45 / 3.1.69 / 3.2.0). La réponse explicite ci-dessus contourne cela ; en cas d'échec de l'authentification, `index()` renvoie un objet réponse déjà envoyé — il suffit de le retourner tel quel.

**3. Routes des ressources statiques** — `config/routes.php` :

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

**4. Publier la configuration** :

```sh
php bin/hyperf.php vendor:publish aaron-dev/xhprof-webman
```

Configuration produite dans `config/autoload/xhprof.php`.

---

### Yii3

**1. Enregistrer le middleware** — `config/web/di/application.php` :

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

Deux erreurs faciles à commettre (toutes deux mesurées) :

- **N'écrivez pas** `'__construct()' => ['middlewares' => [...]]` : `MiddlewareDispatcher::__construct()` n'accepte qu'un `MiddlewareFactory` et un `EventDispatcherInterface` optionnel — il n'y a **aucun** paramètre `middlewares`. La liste des middlewares ne peut être injectée que par la **méthode d'instance** `withMiddlewares()`.
- **Ne mettez pas** une instance (`new XhprofMiddleware(...)`) dans `withMiddlewares()` : les définitions n'acceptent qu'une chaîne de classe, un tableau de définition ou un appelable. Avec une instance, l'enregistrement ne signale rien et `dispatch()` lève un `TypeError` (`MiddlewareFactory::create()` est typé `callable|array|string`).

**2. Page de rapport et ressources statiques** — **aucun contrôleur ni route à enregistrer** : `XhprofMiddleware` est un middleware PSR-15. Avant de démarrer le profilage, il inspecte le chemin de la requête : une correspondance sur le chemin de rapport `/xhprof` renvoie immédiatement la page de rapport, et une correspondance sur le chemin des ressources (préfixe par défaut `/xhprof-assets`) renvoie directement la ressource statique. La réponse de la page de rapport porte un `Content-Type: text/html; charset=UTF-8` explicite posé par la classe d'entrée : les réponses PSR-7 n'en ont aucun par défaut et l'émetteur de réponse de Yii3 n'en ajoute pas, donc sans cela les navigateurs affichent le rapport HTML en texte brut.

**3. Configuration** — les valeurs par défaut se trouvent dans le paquet, à `src/Yii3/config/xhprof.php` ; voir « Référence de configuration » pour les champs. Pour les remplacer, injectez `$config` via le DI :

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

Le sous-tableau `redis` est spécifique à Yii3 : lorsqu'aucun `CacheInterface` n'est injecté, le middleware l'utilise pour parler directement à phpredis. Laissez `assets_url` à sa valeur par défaut — tout autre préfixe ne produit qu'un 200 avec un corps vide (c'est une constante codée en dur dans Core ; voir [Vérification et limites connues](#vérification-et-limites-connues)).

**4. Version requise** — les composants `yiisoft/*` dont Yii3 dépend exigent PHP >= 8.1. Bien que ce paquet déclare `php >= 8.0`, l'intégration Yii3 n'est pas utilisable sur PHP 8.0.

---

### Symfony

**1. Enregistrer l'abonné d'événements** — `config/services.yaml` :

```yaml
services:
    ErikWang2013\Xhprof\Symfony\XhprofListener:
        tags:
            - { name: kernel.event_subscriber }
```

**2. Page de rapport et ressources statiques** — **aucun contrôleur ni route à enregistrer** : avant de démarrer le profilage, l'écouteur inspecte le chemin de la requête : une correspondance sur le chemin de rapport `/xhprof` renvoie immédiatement la page de rapport, et une correspondance sur le chemin des ressources (préfixe par défaut `/xhprof-assets`) renvoie directement la ressource statique.

**3. Configuration** — les valeurs par défaut se trouvent dans le paquet, à `src/Symfony/config/xhprof.php` ; voir « Référence de configuration » pour les champs.

**4. Sous-requêtes et repli sur exception** — l'écouteur écoute `kernel.request` (priorité 10000) et `kernel.response` (priorité -10000). `isMainRequest()` filtre les sous-requêtes ESI/fragment, qui sinon arrêteraient le profilage trop tôt ; un `register_shutdown_function` idempotent est également enregistré au démarrage de la requête — si HttpKernel relance une exception, `kernel.response` n'est jamais déclenché, et sans ce repli l'état de profilage fuiterait dans la requête suivante.

---

### Slim 4

**1. Enregistrer le middleware** — `public/index.php` :

```php
use ErikWang2013\Xhprof\Slim\XhprofMiddleware;

$app->addRoutingMiddleware();

// must be added last: Slim's middleware stack is LIFO, added later = further out = runs first
$app->add(new XhprofMiddleware(
    $app->getResponseFactory()
));
```

Les trois autres arguments du constructeur sont tous optionnels ; omettez-les pour utiliser les valeurs par défaut du paquet :

- Argument 2, `array $config` : votre tableau de configuration, fusionné par-dessus `src/Slim/config/xhprof.php` du paquet avec `array_replace` (remplacement de valeur entière — les clés de type liste comme `ignore_url_arr` ne sont jamais fusionnées récursivement).
- Argument 3, `CacheInterface $cache` : omis, il fait paresseusement `new \Redis()` (le constructeur ne touche volontairement jamais à ext-redis, pour qu'une extension manquante ne fasse pas tout exploser pendant la construction de l'adaptateur). Pour injecter votre propre connexion, passez `new \ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter($redis)`, ou tout objet implémentant `ErikWang2013\Xhprof\Core\Contract\CacheInterface`.
- Argument 4, `LoggerInterface $logger` : omis, c'est `new \ErikWang2013\Xhprof\Slim\Adapter\LogAdapter()` et **les logs sont silencieusement jetés** (Slim ne fournit aucun logger PSR-3). Pour les conserver, passez `new LogAdapter($psrLogger)`, où `$psrLogger` est un logger PSR-3 que vous avez déjà.

**N'écrivez pas `$app->add(XhprofMiddleware::class)`** : le `CallableResolver` de Slim transforme cette chaîne de classe en `new XhprofMiddleware($container)` — en ne passant que le conteneur, et en différant la résolution jusqu'au moment de la requête. Résultat : `add()` ne signale rien et la première requête lève un `TypeError` — le mode de défaillance le plus difficile à diagnostiquer. Utilisez toujours le `new` explicite ci-dessus.

**2. Page de rapport et ressources statiques** — **aucun contrôleur ni route à enregistrer** : avant de démarrer le profilage, le middleware inspecte le chemin de la requête : une correspondance sur le chemin de rapport `/xhprof` renvoie immédiatement la page de rapport, et une correspondance sur le chemin des ressources (préfixe par défaut `/xhprof-assets`) renvoie directement la ressource statique.

**3. Configuration** — les valeurs par défaut se trouvent dans le paquet, à `src/Slim/config/xhprof.php` ; voir « Référence de configuration » pour les champs.

**4. Ordre de montage** — la pile de middlewares de Slim est LIFO (mesuré avec deux middlewares, l'ordre d'exécution est `B:before → A:before → A:after → B:after`) : plus vous `add()` tard, plus le middleware est à l'extérieur et plus il s'exécute tôt. xhprof doit donc être ajouté **en dernier**, et **après `addRoutingMiddleware()`** — sinon `/xhprof` n'est pas dans la table de routage, RoutingMiddleware lève `HttpNotFoundException` en premier, et la requête n'atteint jamais le middleware. La réponse de la page de rapport porte un `Content-Type: text/html; charset=UTF-8` explicite posé par la classe d'entrée : les réponses PSR-7 n'en ont aucun par défaut et le `ResponseEmitter` de Slim n'en ajoute pas, donc sans cela les navigateurs affichent le rapport HTML en texte brut.

---

### WordPress

**1. Installer le mu-plugin** — copiez le fichier d'amorçage du paquet dans `wp-content/mu-plugins/` :

```sh
cp vendor/aaron-dev/xhprof-webman/wordpress/xhprof-webman.php wp-content/mu-plugins/
```

`wordpress/xhprof-webman.php` porte un en-tête de plugin et amorce `Wordpress\XhprofPlugin`. Les mu-plugins sont chargés automatiquement — rien à activer dans wp-admin.

**2. Page de rapport et ressources statiques** — **aucun contrôleur ni route à enregistrer** : avant de démarrer le profilage, la classe d'entrée inspecte le chemin de la requête : une correspondance sur le chemin de rapport `/xhprof` renvoie immédiatement la page de rapport, et une correspondance sur le chemin des ressources (préfixe par défaut `/xhprof-assets`) renvoie directement la ressource statique.

**3. Configuration** — les valeurs par défaut se trouvent dans le paquet, à `src/Wordpress/config/xhprof.php` ; voir « Référence de configuration » pour les champs. Utilisez `ignore_url_arr` pour exclure les chemins à forte fréquence comme `wp-cron.php` et `admin-ajax.php`.

**4. Une limite structurelle de la fenêtre de profilage** — la fenêtre va de `plugins_loaded` à `shutdown`, ce qui **n'inclut pas** l'amorçage de `wp-settings.php` ni le chargement des plugins lui-même. C'est une limite structurelle de WordPress : le travail effectué dans cette phase ne peut pas être profilé.

---

### Joomla

**1. Installer le plugin** — copiez le répertoire `joomla/` du paquet dans le `plugins/system/xhprof/` du site :

```sh
cp -r vendor/aaron-dev/xhprof-webman/joomla/ plugins/system/xhprof/
```

Le répertoire `joomla/` du paquet *est* le plugin : le manifeste `xhprof.xml`, `services/provider.php`, et `src/Extension/Xhprof.php`. La classe d'entrée est `ErikWang2013\Xhprof\Joomla\Extension\Xhprof` (un `CMSPlugin`). Après la copie, lancez Discover via « Système → Gérer → Extensions → Discover » puis installez/activez le plugin.

**2. Page de rapport et ressources statiques** — **aucun contrôleur ni route à enregistrer** : avant de démarrer le profilage, le plugin inspecte le chemin de la requête : une correspondance sur le chemin de rapport `/xhprof` renvoie immédiatement la page de rapport, et une correspondance sur le chemin des ressources (préfixe par défaut `/xhprof-assets`) renvoie directement la ressource statique.

**3. Configuration** — les valeurs par défaut se trouvent dans le paquet, à `src/Joomla/config/xhprof.php` ; voir « Référence de configuration » pour les champs. **Compromis connu** : le plugin lit le fichier de configuration du paquet plutôt que les paramètres du plugin — ces paramètres exigeraient une lecture en base de données, et la configuration est lue à chaque requête.

**4. Limites du profilage** — la fenêtre va de `ApplicationEvents::AFTER_INITIALISE` à `ApplicationEvents::AFTER_RESPOND` ; un `register_shutdown_function` idempotent est également enregistré dans `AFTER_INITIALISE`, car `AFTER_RESPOND` n'est pas garanti d'être atteint sur le chemin d'exception — sans ce repli, l'état de profilage fuiterait dans la requête suivante.

---

### Drupal

**1. Activer le module** — `drupal/xhprof/` dans le paquet est un module Drupal standard (`xhprof.info.yml` / `xhprof.routing.yml` / `xhprof.services.yml`). Placez-le dans `modules/custom/xhprof/` de votre site, puis activez-le sur la page « Étendre » (ou avec `drush en xhprof`).

**2. Page de rapport** — Drupal est **le seul des dix frameworks à servir la page de rapport via les routes du module** : `xhprof.routing.yml` enregistre le chemin de rapport `/xhprof` et un contrôleur du module le rend. Les cinq autres nouveaux frameworks servent eux-mêmes la page de rapport et les ressources statiques et n'enregistrent aucune route.

**3. Configuration** — la configuration est une configuration typée au niveau du module : les valeurs par défaut se trouvent dans `drupal/xhprof/config/install/xhprof.settings.yml`, avec le schéma dans `drupal/xhprof/config/schema/xhprof.schema.yml`. Voir « Référence de configuration » pour les champs.

**4. Enregistrement du middleware** — enregistrez le service de middleware dans le `xhprof.services.yml` du module :

```yaml
services:
  xhprof.http_middleware:
    class: ErikWang2013\Xhprof\Drupal\XhprofMiddleware
    arguments: ['@config.factory', '@logger.factory']
    tags:
      - { name: http_middleware, priority: 1000 }
```

Le kernel interne est **préfixé automatiquement comme argument de constructeur 0** par le `StackedKernelPass` de Drupal — **ne l'écrivez pas vous-même** : cela produirait deux kernels internes, ce qui échoue à la compilation du conteneur sur Drupal <= 11.2.x (y compris tous les 10.x) et lève un TypeError à chaque requête sur 11.3.0 et au-delà. Le profilage s'arrête dans `finally`.

**5. Remarques**

- `priority: 1000` place le middleware **en dehors du cache de page** (la priorité la plus haute déjà présente dans le core est negotiation : 400 sur D10, 500 sur D11, tandis que le cache de page est à 200), donc **les requêtes servies depuis le cache de page de Drupal sont tout de même profilées**. Pour un outil de profilage c'est le comportement attendu, mais les utilisateurs doivent le savoir.
- Le cache fonctionne sans configuration : le middleware utilise par défaut l'adaptateur Redis fourni avec ce paquet (le paquet dépend durement de ext-redis), et il accepte aussi un argument `CacheInterface` optionnel via `arguments` dans `services.yml` pour le remplacer. Si le cache est indisponible, un échec d'enregistrement est absorbé par `XhprofProfiler::stop()` en une seule ligne de log — **aucune erreur n'est levée**.
- Les requêtes vers la page de rapport `/xhprof` et vers `/xhprof-assets/*` **ne sont pas profilées** : le middleware ignore le profilage par chemin avant `xhprofStart()`. La réponse est tout de même produite par le Controller de `xhprof.routing.yml` (**ce n'est pas un court-circuit**). Donc même avec `ignore_url_arr` à `[]` (aucun filtrage), ces deux requêtes n'apparaissent jamais dans le rapport.

---

## Référence de configuration

Tous les frameworks partagent ces options de configuration :

| Configuration | Type | Défaut | Description |
|--------|------|---------|-------------|
| `enable` | bool | `true` | Active/désactive le profilage |
| `time_limit` | int | `0` | Ne profiler que les requêtes dépassant n secondes, 0 signifie toutes |
| `log_num` | int | `1000` | Nombre maximal d'enregistrements |
| `view_wtred` | int | `3` | Met en rouge les lignes dont le temps de réponse dépasse n secondes |
| `ignore_url_arr` | array | `["/xhprof"]` | Chemins d'URL à ignorer |
| `assets_url` | string | `/xhprof-assets` | Préfixe d'URL des ressources statiques |
| `auth_token` | string\|null | `null` | Si défini, la page de rapport exige `?token=xxx` ; recommandé pour les déploiements publics |
| `key_prefix` | string | `xhprof` | Préfixe des clés Redis ; utilisez des valeurs distinctes par projet lorsque vous partagez un Redis |
| `log_ttl` | int | `604800` | Durée de conservation des données en secondes (7 jours par défaut) |
| `locale` | string\|null | `null` | Langue de la page de rapport : `zh_CN`/`en`/`ko`/`ru`/`de`/`fr`/`es`/`pt`/`ar`/`hi`/`bn`/`id`/`ja` ; `null` = suivre l'`Accept-Language` du navigateur, sinon le chinois ; `?lang=xx` la remplace pour une requête |

Les limites connues de ces options sur chaque framework sont listées dans [Vérification et limites connues](#vérification-et-limites-connues).

---

## Initialisation manuelle

Si la détection automatique du framework échoue, vous pouvez injecter les adaptateurs manuellement :

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

**Les six nouveaux frameworks (Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal) ne doivent pas appeler `Xhprof::bootstrap()` sans argument** — sans argument, il passe par `autoDetect()`, qui ne connaît que les branches webman / Laravel / ThinkPHP / Hyperf et lève `Unsupported framework` sur ces six frameworks. Passez les 5 adaptateurs explicitement comme dans l'exemple ci-dessus (la classe d'entrée fournie avec chaque framework le fait déjà pour vous).

---

## Architecture et conception

Core atteint le framework par exactement 5 contrats, tous dans `src/Core/Contract/` :

| Contrat | Méthodes | Rôle |
|----------|---------|---------|
| `RequestInterface` | `get()` `all()` `method()` `header()` `host()` `uri()` `url()` `getRealIp()` | Lire les données de la requête, faire correspondre le chemin de rapport, construire les liens de la page de rapport |
| `ResponseInterface` | `withBody()` `withHeaders()` `withStatus()` `file()` `send()` | Émettre la page de rapport, les ressources statiques, et les 400/403 |
| `ConfigInterface` | `get()` | Lire la configuration du plugin : `get('xhprof')` pour le bloc entier, `get('xhprof.assets_url')` pour une feuille |
| `CacheInterface` | `get()` `set()` `mget()` `incr()` `lPush()` `rPop()` `lRange()` `del()` `decr()` | Lectures et écritures Redis |
| `LoggerInterface` | `error()` | Avertissements pour extensions manquantes et échecs d'enregistrement |

Chaque framework fournit 5 adaptateurs implémentant ces contrats, enregistrés dans Core par `Xhprof::bootstrap()`. Tout ce qui est spécifique à un framework reste dans son propre répertoire `src/<Fw>/`.

**Il ne reste que deux couplages aux frameworks dans Core** :

1. La chaîne `class_exists()` dans `Xhprof::autoDetect()` (`Webman\App` → `Illuminate\Foundation\Application` → `think\App` → `Hyperf\Context\ApplicationContext`), atteinte uniquement par le `bootstrap()` sans argument.
2. La bascule de coroutine Hyperf codée en dur : `Xhprof::markHyperfContext()` plus les vérifications d'existence de `\Hyperf\Context\Context`, qui décident si les adaptateurs vont dans des propriétés statiques globales au processus ou dans le Context de coroutine.

**Les six nouveaux frameworks ne passent jamais par `autoDetect()` — ils utilisent tous l'injection explicite** : chaque classe d'entrée construit ses propres 5 adaptateurs et les passe à `Xhprof::bootstrap($req, $res, $cfg, $cache, $log)`. La raison est que sur les frameworks PSR-7, la Request/Response ne peut être obtenue que depuis le pipeline de requête, donc un `bootstrap()` sans argument ne peut pas fonctionner par construction ; un second bénéfice est que `autoDetect()` reste figé sur ses quatre frameworks actuels.

![Architecture](docs/images/architecture.svg)

Le premier diagramme est la **structure** : la classe d'entrée de chacun des dix frameworks, les 5 contrats, les trois couches de Core, et les deux seuls couplages restants.

![Design rationale](docs/images/design.svg)

Le second diagramme est le **raisonnement** : cinq compromis présentés en décision / raison / coût, coiffés par « changements de `src/Core/` dus aux six nouveaux frameworks = 0 ».

---

## Cycle de vie d'une requête

Une requête profilée :

1. **Avant que le profilage ne démarre**, la classe d'entrée inspecte le chemin : une correspondance sur le chemin de rapport renvoie immédiatement la page de rapport ; une correspondance sur le chemin des ressources renvoie immédiatement la ressource statique. Aucun des deux chemins n'est profilé, et aucun n'entre dans le flux ci-dessous.
2. `XhprofProfiler::isEnabled()` lit `enable` dans la configuration ; si le profilage est désactivé ou qu'une extension manque, tout le bloc est ignoré.
3. `Xhprof::xhprofStart()` → `XhprofProfiler::start()` → `xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY)`.
4. La logique métier s'exécute.
5. `finally { Xhprof::xhprofStop(); }` → `xhprof_disable()`, puis `XHProfRunsDefault::save_run()` écrit dans Redis. `finally` plutôt qu'une instruction simple, afin qu'une exception levée réinitialise tout de même l'état de profilage et enregistre le run.
6. Le navigateur ouvre la page de rapport ; `Xhprof::index()` relit les données depuis Redis et les rend.

![Lifecycle](docs/images/lifecycle.svg)

| Framework | Début du profilage | Fin du profilage |
|-----------|-----------------|----------------|
| webman / Laravel / ThinkPHP / Hyperf | Entrée du middleware (`process()` / `handle()`) | `finally` |
| Yii3 / Slim 4 | `process()` PSR-15 | `finally` |
| Symfony | `kernel.request` (priorité 10000) | `kernel.response` (priorité -10000), plus un repli shutdown |
| WordPress | `plugins_loaded` | `shutdown` |
| Joomla | `onAfterInitialise` | `onAfterRespond`, plus un repli shutdown |
| Drupal | `http_middleware` (priorité 1000, le plus externe) | `finally` |

---

## Structure du projet

```
xhprof-webman/
├── src/
│   ├── Core/                     # Indépendant du framework : contrats, page de rapport, Redis, assets
│   │   ├── Contract/             # les 5 interfaces de contrat
│   │   ├── XhprofLib/            # rendu du rapport et stockage des runs (issu de phacility/xhprof)
│   │   ├── Xhprof.php            # façade statique : bootstrap() / index()
│   │   ├── XhprofProfiler.php    # xhprof_enable/disable et configuration
│   │   ├── StaticController.php  # ressources statiques /xhprof-assets
│   │   ├── MiddlewareTrait.php   # enveloppe de profilage partagée Laravel / ThinkPHP
│   │   └── RedisAdapterTrait.php # implémentation partagée des adaptateurs Redis
│   ├── Webman/ Laravel/ Thinkphp/ Hyperf/            # les 4 frameworks existants
│   ├── Yii3/ Symfony/ Slim/ Wordpress/ Joomla/ Drupal/   # les 6 nouveaux frameworks
│   └── html/                     # ressources de la page de rapport (css / js / images)
├── wordpress/                    # fichier d'amorçage mu-plugin (avec en-tête de plugin)
├── joomla/                       # plugin Joomla (CMSPlugin + manifeste)
├── drupal/xhprof/                # module Drupal standard (info / routing / services + contrôleur)
├── tools/contracts/              # boucle de vérification autonome : signatures et sémantique face aux vrais paquets de framework
├── tools/i18n/                   # chaîne d'outils de traduction pour le README et les trois SVG (générer / vérifier / autotest)
├── docs/i18n/                    # les 12 livrables traduits (anglais, coréen, russe, allemand, français, espagnol, portugais, arabe, hindi, bengali, indonésien, japonais)
├── tests/                        # PHPUnit : tests des adaptateurs, du câblage, du Core, et parité structurelle des 14 README
└── docs/images/                  # schémas du README
```

Sauf pour Drupal, chaque répertoire `src/<Fw>/` a la même forme :

```
src/<Fw>/
├── Adapter/{Request,Response,Config,Redis,Log}Adapter.php
├── <EntryClass>.php
└── config/xhprof.php             # les mêmes 10 clés de configuration que tout autre framework
```

`src/Drupal/` est la seule exception : il n'a pas de répertoire `config/` — sa configuration vit dans la configuration typée au niveau du module (`drupal/xhprof/config/install/xhprof.settings.yml`).

---

## Vérification et limites connues

**Ce qui est mécaniquement prouvé**

| Élément | Comment |
|------|-----|
| Comportement des adaptateurs et du câblage des entrées | `tests/Unit/Adapter/*Test.php` : activé → enregistré / désactivé → non enregistré / exception métier → enregistré tout de même via `finally` |
| Les dix frameworks partagent un même jeu de clés de configuration | test de parité de configuration (jeux de clés, pas octet par octet ; les commentaires peuvent différer) |
| Les deux README se correspondent | test de parité des README : compare la séquence de titres `##` / `###` et le nombre de blocs de code |
| Les méthodes appelées par les adaptateurs existent réellement | boucle de vérification `tools/contracts/` (sa propre tâche CI) : installe les vrais paquets de framework et vérifie par réflexion que chaque méthode / constante / fonction globale existe **pour les six frameworks de la boucle** (Slim / Symfony / Yii3 / Joomla / WordPress / Drupal) ; Webman / Laravel / ThinkPHP / Hyperf n'en font pas partie, voir ci-dessous |
| Sémantique des adaptateurs | La même boucle instancie de vrais objets requête et réponse et exécute les adaptateurs, y compris deux invariants : `uri()` ne porte ni schéma ni hôte, et `withHeaders()` s'applique encore après `file()` |


**Non vérifié automatiquement (ne lisez pas ceci comme « les six ont tous été testés »)**

| Élément | Pourquoi non |
|------|---------|
| Le **câblage** de chaque framework (le hook est-il vraiment attaché, l'événement se déclenche-t-il vraiment) | Les tests unitaires utilisent des stubs ; le câblage ne peut actuellement être confirmé que par des tests de fumée manuels |
| WordPress de bout en bout | Le timing réel de `plugins_loaded`, le déclenchement de `shutdown` sur erreur fatale, et le chargement effectif du mu-plugin exigent un vrai WordPress |
| Découverte du plugin Joomla et `$app->close()` | Nécessite de lancer Discover dans une vraie administration Joomla |
| La priorité de Drupal tombe-t-elle vraiment en dehors du cache de page | Nécessite un kernel Drupal démarré |
| L'auto-configuration `kernel.event_subscriber` de Symfony | Nécessite une vraie compilation du conteneur |
| Interférences d'état statique dans les processus longue durée | Hérité de l'architecture existante (il en va de même pour Webman / Hyperf) ; inchangé ici |
| E/S Redis réelles, rendu navigateur, surcoût du profilage sous charge réelle | Hors du périmètre des tests unitaires et de la boucle de vérification |
| Signatures et sémantique des adaptateurs pour Webman / Laravel / ThinkPHP / Hyperf | ces quatre-là ne sont pas dans la boucle de vérification (elle couvre six frameworks) ; leurs stubs sont écrits à la main dans `tests/Stubs/framework-stubs.php`, sans comparaison avec les vrais paquets |

**Liste de vérification manuelle (trois étapes par framework)**

| Étape | Action | Attendu |
|------|--------|----------|
| 1 | Monter la classe d'entrée comme décrit dans « Configuration par framework » | Aucune erreur |
| 2 | Appeler n'importe quelle URL de l'application | La longueur de la clé `xhprof:run_id` dans Redis augmente de 1 |
| 3 | Ouvrir `/xhprof` | La page de rapport s'affiche avec ses styles ; `/xhprof-assets/js/xhprof_report.js` renvoie 200 |

**Limite connue : `host()` n'a pas de port**

Le contrat `host()` signifie « hôte seul, sans port », mais les liens de la liste du rapport sont construits en `host() . uri()` (`src/Core/XhprofLib/Utils/XHProfRunsDefault.php`). Donc **sur un port non standard (par ex. `:8080`) les liens de la liste perdent le port et ne mènent nulle part**. C'est un problème latent de l'implémentation existante (il affecte autant webman / Laravel / ThinkPHP / Hyperf), il n'est pas corrigé ici, et il est consigné comme limite connue.

**Limite connue : `assets_url` ne fonctionne que lorsqu'il vaut `/xhprof-assets`**

Le préfixe des ressources est une constante codée en dur dans `src/Core/StaticController.php` (`private const URI_PREFIX = '/xhprof-assets'`), tandis que les liens CSS/JS de la page de rapport lisent l'option de configuration `assets_url` (`src/Core/Xhprof.php`). Dès que les deux divergent, `getPathFromRequest()` renvoie `null` et `serve()` renvoie `withBody('')->withHeaders([])` — **une réponse 200 vide, pas un 404**. Conséquence : mettez `assets_url` à autre chose et le CSS/JS devient silencieusement vide, laissant la page de rapport sans style et sans la moindre erreur. Autrement dit, `assets_url` est actuellement une fausse option qui ne fonctionne que laissée à sa valeur par défaut. C'est un problème préexistant et il n'est pas corrigé ici.

**Limite connue : le garde de chemin échoue quand Drupal est dans un sous-répertoire**

Quand Drupal est installé dans un sous-répertoire (par ex. `/sites/app/xhprof`), le garde de chemin ne peut pas faire correspondre une URI qui porte le chemin de base, donc le comportement retombe sur « profilé mais non enregistré » (avec la configuration par défaut, `ignore_url_arr` l'attrape). C'est la même classe de limite que le préfixe `assets_url` codé en dur.

**Compatibilité Symfony 6.4**

La compatibilité Symfony 6.4 a été mesurée (c'est ainsi que deux sur-ajustements invisibles en 7.4 ont été corrigés : les propriétés de `Request` ne portent aucune déclaration de type natif en 6.4, et le charset ajouté par `prepare()` diffère en casse), mais la boucle de vérification CI ne fait tourner que 7.4.

---

## Auteur

[erik](https://erik.xyz)

## Soutenir l'open source

<p align="center">
  <img src="./docs/weixinpay.png" alt="WeChat Pay" width="130" height="130" title="WeChat Pay" />
  <img src="./docs/alipay.png" alt="Alipay" width="130" height="130" title="Alipay" />
</p>

---

Ce plugin s'appuie sur [phacility/xhprof](https://github.com/phacility/xhprof) et [xiexianbo123/xhprof](https://github.com/xiexianbo123/xhprof).
