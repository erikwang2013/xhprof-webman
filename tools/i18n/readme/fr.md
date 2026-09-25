# Profileur de performance XHProf

Un plugin de profilage de performance du code, compatible avec webman / Laravel / ThinkPHP / Hyperf / Yii3 / Symfony / Slim 4 / WordPress / Joomla et Drupal.

Il collecte les données de profilage via l'extension xhprof et les stocke dans Redis. Les développeurs accèdent rapidement, depuis un navigateur, aux rapports d'analyse de performance pour identifier les goulots d'étranglement du code.

![Mascotte du projet : petite flamme](docs/images/pet.svg)

La même petite flamme sert aussi d'icône de site et d'icône de marque en haut à gauche de la page de rapport (`src/html/pet.svg`, servie sous le préfixe `assets_url`).

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
| PHP natif (sans framework) | — (aucun paquet externe) | 8.0 | `Native\XhprofBootstrap` | Une ligne en haut du fichier d'entrée, `XhprofBootstrap::start()`, sans contrôleur ni route à enregistrer |

Les classes d'entrée vivent toutes sous le préfixe de namespace `ErikWang2013\Xhprof\` (omis ci-dessus).  Aucun des onze ne vous demande d'enregistrer un contrôleur ou une route : la page de rapport et les ressources statiques sont servies par la classe d'entrée elle-même (pour Drupal, par la route du module).

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

**2. Page de rapport et ressources statiques** — **aucun contrôleur ni route à enregistrer** : avant le début du profilage, le middleware inspecte le chemin de la requête : une correspondance sur le chemin de rapport `/xhprof` renvoie aussitôt la page de rapport, et une correspondance sur le chemin des ressources (préfixe lu depuis l'option `assets_url`, par défaut `/xhprof-assets`) renvoie directement la ressource statique.

**3. Configuration** — voir `config/plugin/aaron-dev/xhprof/xhprof.php`.

---

### Laravel

**1. Enregistrer le middleware** — `app/Http/Kernel.php` :

```php
protected $middleware = [
    // ...
    \ErikWang2013\Xhprof\Laravel\Middleware::class,
];
```

**2. Page de rapport et ressources statiques** — **aucun contrôleur ni route à enregistrer** : avant le début du profilage, le middleware inspecte le chemin de la requête : une correspondance sur le chemin de rapport `/xhprof` renvoie aussitôt la page de rapport, et une correspondance sur le chemin des ressources (préfixe lu depuis l'option `assets_url`, par défaut `/xhprof-assets`) renvoie directement la ressource statique.

**3. Publier la configuration** :

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

**2. Page de rapport et ressources statiques** — **aucun contrôleur ni route à enregistrer** : avant le début du profilage, le middleware inspecte le chemin de la requête : une correspondance sur le chemin de rapport `/xhprof` renvoie aussitôt la page de rapport, et une correspondance sur le chemin des ressources (préfixe lu depuis l'option `assets_url`, par défaut `/xhprof-assets`) renvoie directement la ressource statique.

**3. Configuration** — copiez `vendor/aaron-dev/xhprof-webman/src/Thinkphp/config/xhprof.php` vers `config/xhprof.php` du projet.

---

### Hyperf

**1. Enregistrement automatique du middleware** — ConfigProvider ajoute automatiquement le middleware à la file des middlewares HTTP.

**2. Page de rapport et ressources statiques** — **aucun contrôleur ni route à enregistrer** : avant le début du profilage, le middleware inspecte le chemin de la requête : une correspondance sur le chemin de rapport `/xhprof` renvoie aussitôt la page de rapport, et une correspondance sur le chemin des ressources (préfixe lu depuis l'option `assets_url`, par défaut `/xhprof-assets`) renvoie directement la ressource statique.

**3. Publier la configuration** :

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

Le sous-tableau `redis` est spécifique à Yii3 : lorsqu'aucun `CacheInterface` n'est injecté, le middleware l'utilise pour parler directement à phpredis. Laissez `assets_url` peut être n'importe quel préfixe : les liens CSS/JS de la page de rapport et `StaticController` lisent la même option (par défaut `/xhprof-assets`). Les limites restantes en cas de déploiement dans un sous-répertoire sont dans [Vérification et limites connues](#vérification-et-limites-connues).

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

**2. Page de rapport et ressources statiques** — Drupal est **le seul des onze frameworks à passer par « module + routes »** : `xhprof.routing.yml` enregistre le chemin de rapport `/xhprof` et celui des ressources `/xhprof-assets`, servis par défaut par le contrôleur du module ; les dix autres classes d'entrée court-circuitent avant le début du profilage et servent la page de rapport et les ressources statiques sans enregistrer de route. **Avec un préfixe `assets_url` personnalisé, les ressources passent au middleware** : le chemin de la route de ressources du module est figé dans `xhprof.routing.yml` (`/xhprof-assets/{file}`) et ne correspond jamais à un autre préfixe.

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

### PHP natif (sans framework)

Pour les applications sans framework, avec un seul contrôleur frontal (un `public/index.php` par exemple).

**1. Ajoutez une ligne en haut du fichier d'entrée** :

```php
\ErikWang2013\Xhprof\Native\XhprofBootstrap::start();
```

Pour changer la configuration, passez le tableau à cette ligne (même jeu de clés que les dix autres ; les valeurs par défaut sont dans `src/Native/config/xhprof.php`) :

```php
\ErikWang2013\Xhprof\Native\XhprofBootstrap::start([
    'enable' => true,
    'auth_token' => 'xxx',
]);
```

Les deuxième et troisième arguments sont des points d'injection facultatifs : `CacheInterface $cache` et `LoggerInterface $logger` (par défaut l'adaptateur Redis de ce paquet et `error_log`). La valeur de retour est l'instance d'entrée de cette requête (`stop()` est idempotente) ; appelez-la pour arrêter plus tôt dans le même processus.

**2. Page de rapport et ressources statiques** — **aucun contrôleur ni route à enregistrer** : cette ligne inspecte le chemin de la requête avant le début du profilage : une correspondance sur le chemin de rapport `/xhprof` renvoie aussitôt la page de rapport (avec `Content-Type: text/html; charset=UTF-8` et `Cache-Control: no-cache, private`, `auth_token` s'applique comme ailleurs), une correspondance sur le chemin des ressources (préfixe lu dans l'option `assets_url`, par défaut `/xhprof-assets`) renvoie directement la ressource statique. **Le coût est dit franchement** : une fois ces deux chemins traités, un `exit` suit — le reste de la requête (les routes après cette ligne, l'amorçage du conteneur, le démarrage de session, et la logique de fin enregistrée par l'application elle-même) ne s'exécute pas.

**3. Fenêtre de profilage = cette ligne → arrêt du processus** (c'est `register_shutdown_function` qui est enregistrée). **Les limites, sans arrondi** : elle n'inclut pas le code **avant** cette ligne (autoload composer, amorçage du contrôleur frontal), ni ce que font d'autres processus ou extensions (l'analyse de la requête côté php-fpm, le traitement côté nginx). Une fin normale, `exit`, un Error ou une exception non interceptée atteignent le point d'arrêt ; `SIGKILL` / l'OOM killer non — l'état du profilage disparaît avec le processus et ne subsiste pas pour la requête suivante. Pour resserrer la portée, l'option `ignore_url_arr` (correspondance de sous-chaîne sur `uri()`, effet sans toucher au code).

**4. Lancez-le pour de vrai avec `php -S`** :

```sh
# En haut de public/index.php se trouve XhprofBootstrap::start() ; ce fichier est le contrôleur frontal
php -S 127.0.0.1:8000 -t public public/index.php
```

Ouvrez `http://127.0.0.1:8000/` pour produire des données, puis `http://127.0.0.1:8000/xhprof` pour la page de rapport — les deux dans le même processus, et les ressources sont vérifiées au passage.

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

**Sélecteur de langue de la page de rapport**

La liste déroulante à droite de la navigation énumère les 13 langues par leur **nom propre** (le `_meta.name` de chaque catalogue, p. ex. 한국어, 日本語). Le lien de chaque option est construit à partir de **la chaîne de requête de la page courante** (`XhprofLib::report_url()`) : `?token=`, le tri, `run` et tous les autres paramètres suivent donc ; changer de langue **ne quitte pas la vue courante** — sur un rapport d'exécution, on reste sur la même exécution.

**Diagnostic sur la page de rapport**

La carte tout en haut du corps du rapport est le « Diagnostic » (juste sous la description de l'exécution) : d'abord « Pourquoi c'est lent » (3 causes au plus), puis « Autres constats » (3 points de contrôle au plus). Le lien « voir » après chaque constat ouvre la page de détail de la méthode ; la récursion (R4) ne porte un lien que si le nom simple est réellement présent dans la table des symboles — xhprof déploie la récursion en `fib@1`/`fib@2`, et s'il ne reste que les noms déployés, chercher `fib` ne trouve rien. Les six règles et leurs seuils :

- **R1** temps réel excl. ≥ 10 % du temps total de la requête ;
- **R2** nombre d'appels ≥ 1000 ;
- **R3** appels d'une même relation ≥ 500 **et** temps réel excl. de la fonction appelée ≥ 5 % du temps total de la requête ;
- **R4** un même symbole apparaît à ≥ 2 profondeurs différentes (récursion) ;
- **R5** mémoire de pointe propre ≥ 30 % de la pointe globale ;
- **R6** temps réel excl. > temps réel incl. (`excl_wt > wt`, logiquement impossible) — une sonde d'intégrité des données, qui ne se déclenche jamais sur des données saines.

Les seuils sont des constantes de `src/Core/Analysis/Analyzer.php`, et **aucune option de configuration** ne permet aujourd'hui de les modifier ni de désactiver la carte (`enable` désactivé, rien n'est échantillonné, donc il n'y a rien à diagnostiquer). Elle n'apparaît **que dans la vue d'exécution unique de premier niveau** : ni la vue diff ni la page de détail d'une méthode ne la rendent — les `$symbol_tab`/`$totals` qui y sont passés ne sont pas des valeurs d'une seule exécution (en mode diff, ce sont les deltas run2 − run1).

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

Le premier diagramme est la **structure** : la classe d'entrée de chacun des onze frameworks, les 5 contrats, les trois couches de Core, et les deux seuls couplages restants.

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
| PHP natif (sans framework) | Une ligne `XhprofBootstrap::start()` en haut du fichier d'entrée | Arrêt du processus (`register_shutdown_function`), et `stop()` pour arrêter plus tôt |

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
│   ├── Native/                   # PHP natif (sans framework) : classe d'entrée et 5 adaptateurs
│   └── html/                     # ressources de la page de rapport (css / js / images / pet.svg icône de site et icône de marque)
├── wordpress/                    # fichier d'amorçage mu-plugin (avec en-tête de plugin)
├── joomla/                       # plugin Joomla (CMSPlugin + manifeste)
├── drupal/xhprof/                # module Drupal standard (info / routing / services + contrôleur)
├── tools/contracts/              # boucle de vérification autonome : signatures et sémantique face aux vrais paquets de framework (`legacy-symfony64/` est la jambe 6.4)
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
| Les onze frameworks partagent un même jeu de clés de configuration | test de parité de configuration (jeux de clés, pas octet par octet ; les commentaires peuvent différer) |
| Les deux README se correspondent | test de parité des README : compare la séquence de titres `##` / `###` et le nombre de blocs de code |
| Les méthodes appelées par les adaptateurs existent réellement | boucle de vérification `tools/contracts/` (job CI dédié, **deux jambes** : la jambe principale installe les paquets les plus récents de chaque framework, et le projet séparé `tools/contracts/legacy-symfony64` exécute le même cas Symfony contre 6.4) : elle installe de vrais paquets de framework (un vrai `drupal/core` pour Drupal, deux vrais paquets de version du CMS pour Joomla) et vérifie par réflexion que chaque méthode / constante / fonction globale existe **pour les huit frameworks de la boucle** (Slim / Symfony / Yii3 / Joomla / WordPress / Drupal / Laravel / Webman) ; ThinkPHP / Hyperf ne sont pas dans la boucle — voir ci-dessous |
| Sémantique des adaptateurs | La même boucle instancie de vrais objets de requête et de réponse et exécute les adaptateurs, avec deux invariants : `uri()` ne porte pas de scheme/host, et `withHeaders()` s'applique encore après `file()`. Le nombre de SKIP de la boucle est une constante gelée (2 sur la jambe principale, 0 sur la jambe 6.4) et les deux SKIP sont dans Joomla : le vrai chemin de lecture de `#__extensions.params` et la forme de l'installeur — les deux exigent une base de données ou un installeur pour tourner |


**Non vérifié automatiquement (à ne pas lire comme « tout est couvert »)**

| Élément | Pourquoi non |
|------|---------|
| Le **câblage** de chaque framework (le hook est-il vraiment attaché, l'événement se déclenche-t-il vraiment) | Les tests unitaires utilisent des stubs ; le câblage ne peut actuellement être confirmé que par des tests de fumée manuels |
| Les deux sous-points restants de Joomla | Les deux choses que la boucle n'atteint toujours pas, et pour la même raison (il faut une base de données ou un installeur) : le vrai chemin de lecture de `#__extensions.params` (`PluginHelper::getPlugin()` → `bootPlugin()`) et la forme de l'installeur (namespacemap écrit, `bootPlugin()` retrouve la classe) |
| L'auto-configuration `kernel.event_subscriber` de Symfony | Nécessite une vraie compilation du conteneur |
| Interférences d'état statique dans les processus longue durée | Côté Webman inchangé (côté Hyperf les 9 valeurs d'état de rendu par requête sont isolées dans le Context de coroutine, épinglées par `tests/Unit/Lib/RenderStateCoroutineTest.php` avec une coroutine qui cède réellement) |
| E/S Redis réelles, rendu navigateur, surcoût du profilage sous charge réelle | Les E/S Redis réelles sont **désormais dans la boucle** (`cases/Redis.php` : vrai phpredis + une vraie requête Slim de bout en bout — requête → persistance → liste → page de rapport) ; le rendu navigateur et le surcoût sous charge réelle restent hors du périmètre des tests unitaires et de la boucle |
| Signatures et sémantique des adaptateurs pour ThinkPHP / Hyperf | ces deux-là ne sont pas dans la boucle de vérification (elle couvre huit frameworks) ; leurs stubs sont écrits à la main dans `tests/Stubs/framework-stubs.php`, sans comparaison avec les vrais paquets |

**Liste de vérification manuelle (trois étapes par framework)**

| Étape | Action | Attendu |
|------|--------|----------|
| 1 | Monter la classe d'entrée comme décrit dans « Configuration par framework » | Aucune erreur |
| 2 | Appeler n'importe quelle URL de l'application | La longueur de la clé `xhprof:run_id` dans Redis augmente de 1 |
| 3 | Ouvrir `/xhprof` | La page de rapport s'affiche avec ses styles ; `/xhprof-assets/js/xhprof_report.js` renvoie 200 |

**Test de fumée pour PHP natif** : lancez le serveur intégré avec `php -S 127.0.0.1:8000 -t public public/index.php` (étape 4 de « PHP natif »), puis faites les trois étapes — la page de rapport et les ressources sont dans le **même processus** que les requêtes métier, l'étape 3 est donc vérifiable directement.

**Limite connue : le `request_uri` affiché dans la liste n'a pas de port**

Le contrat `host()` signifie « hôte seul, sans port » (R-2), et les onze frameworks le respectent — seule l'implémentation diffère : le `getHost()` de PSR-7 ne porte jamais le port, Joomla / WordPress le retirent à la main via `parse_url`, et Webman / ThinkPHP ont besoin de l'argument strict `host(true)` (la valeur par défaut renvoie l'en-tête `Host` tel quel, port compris). Le `request_uri` affiché dans la liste est construit en `host() . uri()` (`src/Core/XhprofLib/Utils/XHProfRunsDefault.php`) ; sur un port non standard (par ex. `:8080`), le **texte** de cette ligne ne montre donc pas le port. **Les liens eux-mêmes ne sont pas affectés** : les liens de la liste et du rapport sont tous construits par `XhprofLib::report_url()` en URL relatives (chemin + query uniquement) ; ils ouvrent la bonne page et ne dépendent pas de `host()`.

**`assets_url` accepte désormais un préfixe personnalisé**

Le préfixe des ressources n'est plus une constante codée en dur : `src/Core/StaticController.php` fait correspondre les chemins de ressources à l'option `assets_url` (par défaut `/xhprof-assets`, barre oblique finale facultative). La limite restante en cas de déploiement dans un sous-répertoire est celle de Drupal, ci-dessous. **Les onze frameworks suivent cette option** : dix classes d'entrée court-circuitent elles-mêmes le chemin des ressources avant le début du profilage et les servent, tandis que Drupal sert le préfixe par défaut via la route du module + contrôleur et confie un préfixe personnalisé au middleware. **Limite** : Laravel, Hyperf, Webman et ThinkPHP n'ont plus besoin de contrôleur ni de routes — le middleware passe en premier, si bien que les deux routes enregistrées selon les anciennes instructions ne sont que masquées : elles ne produisent aucune erreur et ne sont plus jamais atteintes.

**Limite connue : le garde de chemin échoue quand Drupal est dans un sous-répertoire**

Quand Drupal est installé dans un sous-répertoire (par ex. `/sites/app/xhprof`), le garde de chemin ne peut pas faire correspondre une URI qui porte le chemin de base, donc le comportement retombe sur « profilé mais non enregistré » (avec la configuration par défaut, `ignore_url_arr` l'attrape).

**Compatibilité Symfony 6.4**

La compatibilité avec Symfony 6.4 a été mesurée (c'est ainsi que deux sur-ajustements invisibles en 7.4 ont été corrigés : les propriétés de `Request` ne portent aucune déclaration de type natif en 6.4, et le charset ajouté par `prepare()` diffère en casse). **Les deux jambes tournent en CI** : la jambe principale 7.x plus le projet séparé `tools/contracts/legacy-symfony64`, qui exécute le même fichier de cas sans le copier — et les deux jambes sont aussi dans le gate des tags.

---

## Auteur

[erik](https://erik.xyz)

Ce paquet est publié sous licence MIT (voir `LICENSE`) ; `src/Core/XhprofLib/**`, `src/html/js/xhprof_report.js` et `src/html/css/xhprof.css` dérivent de [phacility/xhprof](https://github.com/phacility/xhprof) (Apache-2.0) et restent sous ses termes ; la liste des bibliothèques front-end tierces figure dans `NOTICE`.

## Soutenir l'open source

<p align="center">
  <img src="./docs/weixinpay.png" alt="WeChat Pay" width="130" height="130" title="WeChat Pay" />
  <img src="./docs/alipay.png" alt="Alipay" width="130" height="130" title="Alipay" />
</p>

---

Ce plugin s'appuie sur [phacility/xhprof](https://github.com/phacility/xhprof) et [xiexianbo123/xhprof](https://github.com/xiexianbo123/xhprof).
