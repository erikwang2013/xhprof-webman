# Plugin de profiling de performance XHProf

Um plugin de profiling de performance de código compatível com webman / Laravel / ThinkPHP / Hyperf / Yii3 / Symfony / Slim 4 / WordPress / Joomla e Drupal.

Coleta dados de profiling pela extensão xhprof e os guarda no Redis. O desenvolvedor acessa rapidamente relatórios de análise de performance pelo navegador para identificar gargalos de performance no código.

![Mascote do projeto: pequena chama](docs/images/pet.svg)

A mesma chama também é o ícone do site e o ícone da marca no canto superior esquerdo da página de relatório (`src/html/pet.svg`, servida sob o prefixo `assets_url`).

**Registro de Requisições**

![Registro de Requisições](docs/images/runs-list.png)

**Relatório de uma execução**

![Relatório de uma execução](docs/images/run-report.png)

## Requisitos

- PHP >= 8.0
- extensão xhprof
- extensão redis
- servidor Redis

## Frameworks compatíveis e versões mínimas

| Framework | Versão mínima | PHP mínimo | Classe de entrada | Como montar |
|-----------|----------------|-------------|-------------|--------------|
| webman | `workerman/webman ^2.1` | 8.0 | `Webman\XhprofMiddleware` | Registre o middleware global em `config/middleware.php` |
| Laravel | `laravel/framework ^9.0\|^10.0\|^11.0` | 8.0 | `Laravel\Middleware` | Registre o middleware global em `app/Http/Kernel.php` |
| ThinkPHP | `topthink/framework ^6.0\|^8.0` | 8.0 | `Thinkphp\Middleware` | Registre o middleware global em `app/middleware.php` |
| Hyperf | `hyperf/framework ^3.0` | 8.0 | `Hyperf\Middleware` | Registrado automaticamente via ConfigProvider |
| Yii3 | `yiisoft/middleware-dispatcher ^5.0` | 8.1 | `Yii3\XhprofMiddleware` | Registre em `config/web/di/application.php`; precisa ser o primeiro da lista de middlewares |
| Symfony | `symfony/http-kernel ^6.4\|^7.0` | 8.1 (6.4) / 8.2 (7.x) | `Symfony\XhprofListener` | Adicione a tag `kernel.event_subscriber` em `config/services.yaml` |
| Slim 4 | `slim/slim ^4.12` | 8.0 | `Slim\XhprofMiddleware` | `$app->add(...)`, e precisa ser adicionado por último |
| WordPress | 6.4+ | 8.0 | `Wordpress\XhprofPlugin` | Copie para `wp-content/mu-plugins/` |
| Joomla | 4.4 / 5.x | 8.1 | `Joomla\Extension\Xhprof` | Copie para `plugins/system/` e instale pelo Discover |
| Drupal | 10.x / 11.x | 8.1 (10.x) / 8.3 (11.x) | módulo `xhprof` (`Drupal\XhprofMiddleware`) | Módulo padrão, basta habilitar |
| PHP puro (sem framework) | — (sem pacote externo) | 8.0 | `Native\XhprofBootstrap` | Uma linha no topo do arquivo de entrada, `XhprofBootstrap::start()`, sem registrar controller nem rota |

Todas as classes de entrada ficam sob o prefixo de namespace `ErikWang2013\Xhprof\` (omitido acima).  Nenhum dos onze precisa que você registre controller ou rota: a página de relatório e os recursos estáticos são servidos pela própria classe de entrada (no caso do Drupal, pela rota do módulo).

Este pacote declara `php >= 8.0`, mas os componentes `yiisoft/*` dos quais o Yii3 depende exigem **PHP 8.1+**, então **o Yii3 não é utilizável no PHP 8.0**; o Symfony 7.x e o Drupal 11.x também precisam de uma versão de PHP mais alta. O passo a passo está em "Configuração por framework", abaixo.

## Instalação

Adicione a configuração do xhprof no php.ini:

```ini
[xhprof]
extension=xhprof.so
xhprof.output_dir=/tmp/xhprof
```

Instale via Composer:

```sh
composer require aaron-dev/xhprof-webman
```

---

## Configuração por framework

### Webman

**1. Registre o middleware global** — `config/middleware.php`:

```php
return [
    '' => [
        ErikWang2013\Xhprof\Webman\XhprofMiddleware::class,
    ],
];
```

**2. Página de relatório e recursos estáticos** — **não precisa de controller nem de registro de rota**: antes de o profiling começar, o middleware inspeciona o caminho da requisição: um acerto no caminho do relatório `/xhprof` devolve a página de relatório na hora, e um acerto no caminho dos assets (prefixo lido da opção `assets_url`, padrão `/xhprof-assets`) devolve o recurso estático direto.

**3. Configuração** — veja `config/plugin/aaron-dev/xhprof/xhprof.php`.

---

### Laravel

**1. Registre o middleware** — `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ...
    \ErikWang2013\Xhprof\Laravel\Middleware::class,
];
```

**2. Página de relatório e recursos estáticos** — **não precisa de controller nem de registro de rota**: antes de o profiling começar, o middleware inspeciona o caminho da requisição: um acerto no caminho do relatório `/xhprof` devolve a página de relatório na hora, e um acerto no caminho dos assets (prefixo lido da opção `assets_url`, padrão `/xhprof-assets`) devolve o recurso estático direto.

**3. Publique a configuração**:

```sh
php artisan vendor:publish --tag=xhprof-config
```

O arquivo de configuração fica em `config/xhprof.php`. O Laravel descobre o ServiceProvider automaticamente.

---

### ThinkPHP

**1. Registre o middleware** — `app/middleware.php`:

```php
return [
    \ErikWang2013\Xhprof\Thinkphp\Middleware::class,
];
```

**2. Página de relatório e recursos estáticos** — **não precisa de controller nem de registro de rota**: antes de o profiling começar, o middleware inspeciona o caminho da requisição: um acerto no caminho do relatório `/xhprof` devolve a página de relatório na hora, e um acerto no caminho dos assets (prefixo lido da opção `assets_url`, padrão `/xhprof-assets`) devolve o recurso estático direto.

**3. Configuração** — copie `vendor/aaron-dev/xhprof-webman/src/Thinkphp/config/xhprof.php` para o `config/xhprof.php` do projeto.

---

### Hyperf

**1. Registro automático do middleware** — o ConfigProvider adiciona o middleware à fila de middlewares HTTP automaticamente.

**2. Página de relatório e recursos estáticos** — **não precisa de controller nem de registro de rota**: antes de o profiling começar, o middleware inspeciona o caminho da requisição: um acerto no caminho do relatório `/xhprof` devolve a página de relatório na hora, e um acerto no caminho dos assets (prefixo lido da opção `assets_url`, padrão `/xhprof-assets`) devolve o recurso estático direto.

**3. Publique a configuração**:

```sh
php bin/hyperf.php vendor:publish aaron-dev/xhprof-webman
```

A configuração é gravada em `config/autoload/xhprof.php`.

---

### Yii3

**1. Registre o middleware** — `config/web/di/application.php`:

```php
use ErikWang2013\Xhprof\Yii3\XhprofMiddleware;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;

return [
    MiddlewareDispatcher::class => [
        'class' => MiddlewareDispatcher::class,
        // o primeiro elemento é o middleware mais externo (executa primeiro, termina por último)
        'withMiddlewares()' => [[
            XhprofMiddleware::class,
            // ... other middlewares
        ]],
    ],
];
```

Dois erros fáceis de cometer (ambos medidos):

- **Não** escreva `'__construct()' => ['middlewares' => [...]]`: o `MiddlewareDispatcher::__construct()` aceita apenas um `MiddlewareFactory` e um `EventDispatcherInterface` opcional — **não existe** parâmetro `middlewares`. A lista de middlewares só pode ser injetada pelo **método de instância** `withMiddlewares()`.
- **Não** coloque uma instância (`new XhprofMiddleware(...)`) dentro de `withMiddlewares()`: as definições aceitam apenas uma string de classe, um array de definição ou um callable. Com uma instância, o registro não reporta nada e o `dispatch()` lança um `TypeError` (o `MiddlewareFactory::create()` é tipado como `callable|array|string`).

**2. Página de relatório e recursos estáticos** — **não precisa de controller nem de registro de rota**: o `XhprofMiddleware` é um middleware PSR-15. Antes de o profiling começar ele inspeciona o caminho da requisição: um acerto no caminho do relatório `/xhprof` devolve a página de relatório na hora, e um acerto no caminho de assets (prefixo padrão `/xhprof-assets`) devolve o recurso estático direto. A resposta da página de relatório carrega um `Content-Type: text/html; charset=UTF-8` explícito, vindo da classe de entrada: respostas PSR-7 não têm um valor padrão e o emissor de respostas do Yii3 não adiciona nenhum, então sem isso o navegador renderiza o relatório HTML como texto puro.

**3. Configuração** — os padrões ficam no pacote, em `src/Yii3/config/xhprof.php`; veja "Referência de configuração" para os campos. Para sobrescrevê-los, injete `$config` via DI:

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

O subarray `redis` é específico do Yii3: quando nenhum `CacheInterface` é injetado, o middleware o usa para falar diretamente com o phpredis. Deixe `assets_url` pode ser qualquer prefixo: os links de CSS/JS da página de relatório e o `StaticController` leem a mesma opção (padrão `/xhprof-assets`). As limitações restantes em implantação num subdiretório estão em [Verificação e limitações conhecidas](#verificação-e-limitações-conhecidas).

**4. Requisito de versão** — os componentes `yiisoft/*` dos quais o Yii3 depende exigem PHP >= 8.1. Apesar de este pacote declarar `php >= 8.0`, a integração com o Yii3 não pode ser usada no PHP 8.0.

---

### Symfony

**1. Registre o event subscriber** — `config/services.yaml`:

```yaml
services:
    ErikWang2013\Xhprof\Symfony\XhprofListener:
        tags:
            - { name: kernel.event_subscriber }
```

**2. Página de relatório e recursos estáticos** — **não precisa de controller nem de registro de rota**: antes de o profiling começar o listener inspeciona o caminho da requisição: um acerto no caminho do relatório `/xhprof` devolve a página de relatório na hora, e um acerto no caminho de assets (prefixo padrão `/xhprof-assets`) devolve o recurso estático direto.

**3. Configuração** — os padrões ficam no pacote, em `src/Symfony/config/xhprof.php`; veja "Referência de configuração" para os campos.

**4. Sub-requisições e fallback de exceção** — ele escuta em `kernel.request` (prioridade 10000) e `kernel.response` (prioridade -10000). O `isMainRequest()` filtra sub-requisições de ESI/fragmento, que de outro modo encerrariam o profiling cedo demais; um `register_shutdown_function` idempotente também é registrado quando a requisição começa — se o HttpKernel relançar uma exceção, o `kernel.response` nunca dispara, e sem esse fallback o estado de profiling vazaria para a requisição seguinte.

---

### Slim 4

**1. Registre o middleware** — `public/index.php`:

```php
use ErikWang2013\Xhprof\Slim\XhprofMiddleware;

$app->addRoutingMiddleware();

// precisa ser adicionado por último: a pilha de middlewares do Slim é LIFO; adicionado depois = mais por fora = executa primeiro
$app->add(new XhprofMiddleware(
    $app->getResponseFactory()
));
```

Os três argumentos restantes do construtor são todos opcionais; omita-os para usar os padrões do pacote:

- Argumento 2, `array $config`: o seu array de configuração, mesclado sobre o `src/Slim/config/xhprof.php` do pacote com `array_replace` (substituição do valor inteiro — chaves de lista, como `ignore_url_arr`, nunca são mescladas recursivamente).
- Argumento 3, `CacheInterface $cache`: se for omitido, ele faz um `new \Redis()` preguiçoso (o construtor deliberadamente nunca toca na ext-redis, então uma extensão ausente não explode enquanto o adaptador é construído). Para injetar a sua própria conexão, passe `new \ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter($redis)`, ou qualquer objeto que implemente `ErikWang2013\Xhprof\Core\Contract\CacheInterface`.
- Argumento 4, `LoggerInterface $logger`: se for omitido, é `new \ErikWang2013\Xhprof\Slim\Adapter\LogAdapter()` e **os logs são descartados em silêncio** (o Slim não traz nenhum logger PSR-3). Para registrá-los, passe `new LogAdapter($psrLogger)`, onde `$psrLogger` é um logger PSR-3 que você já tenha.

**Não escreva `$app->add(XhprofMiddleware::class)`**: o `CallableResolver` do Slim transforma essa string de classe em `new XhprofMiddleware($container)` — passando apenas o container e adiando a resolução para o momento da requisição. O resultado é que o `add()` não reporta nada e a primeira requisição lança um `TypeError` — o modo de falha mais difícil de diagnosticar. Use sempre o `new` explícito mostrado acima.

**2. Página de relatório e recursos estáticos** — **não precisa de controller nem de registro de rota**: antes de o profiling começar o middleware inspeciona o caminho da requisição: um acerto no caminho do relatório `/xhprof` devolve a página de relatório na hora, e um acerto no caminho de assets (prefixo padrão `/xhprof-assets`) devolve o recurso estático direto.

**3. Configuração** — os padrões ficam no pacote, em `src/Slim/config/xhprof.php`; veja "Referência de configuração" para os campos.

**4. Ordem de montagem** — a pilha de middlewares do Slim é LIFO (medido com dois middlewares, a ordem de execução é `B:before → A:before → A:after → B:after`): quanto mais tarde você chama `add()`, mais por fora ele fica e mais cedo ele executa. Então o xhprof precisa ser adicionado **por último**, e **depois do `addRoutingMiddleware()`** — caso contrário `/xhprof` não está na tabela de rotas, o RoutingMiddleware lança `HttpNotFoundException` primeiro e a requisição nunca chega ao middleware. A resposta da página de relatório carrega um `Content-Type: text/html; charset=UTF-8` explícito, vindo da classe de entrada: respostas PSR-7 não têm um valor padrão e o `ResponseEmitter` do Slim não adiciona nenhum, então sem isso o navegador renderiza o relatório HTML como texto puro.

---

### WordPress

**1. Instale o mu-plugin** — copie o arquivo de bootstrap do pacote para `wp-content/mu-plugins/`:

```sh
cp vendor/aaron-dev/xhprof-webman/wordpress/xhprof-webman.php wp-content/mu-plugins/
```

O `wordpress/xhprof-webman.php` traz um cabeçalho de plugin e inicializa o `Wordpress\XhprofPlugin`. Os mu-plugins são carregados automaticamente — não há nada para habilitar no wp-admin.

**2. Página de relatório e recursos estáticos** — **não precisa de controller nem de registro de rota**: antes de o profiling começar a classe de entrada inspeciona o caminho da requisição: um acerto no caminho do relatório `/xhprof` devolve a página de relatório na hora, e um acerto no caminho de assets (prefixo padrão `/xhprof-assets`) devolve o recurso estático direto.

**3. Configuração** — os padrões ficam no pacote, em `src/Wordpress/config/xhprof.php`; veja "Referência de configuração" para os campos. Use `ignore_url_arr` para excluir caminhos de alta frequência, como `wp-cron.php` e `admin-ajax.php`.

**4. Um limite estrutural da janela de profiling** — a janela é `plugins_loaded` → `shutdown`, e ela **não inclui** o bootstrap do `wp-settings.php` nem o carregamento dos próprios plugins. Esse é um limite estrutural do WordPress: o trabalho feito nessa fase não pode ser perfilado.

---

### Joomla

**1. Instale o plugin** — copie o diretório `joomla/` do pacote para o `plugins/system/xhprof/` do site:

```sh
cp -r vendor/aaron-dev/xhprof-webman/joomla/ plugins/system/xhprof/
```

O diretório `joomla/` do pacote *é* o plugin: o manifesto `xhprof.xml`, o `services/provider.php` e o `src/Extension/Xhprof.php`. A classe de entrada é `ErikWang2013\Xhprof\Joomla\Extension\Xhprof` (um `CMSPlugin`). Depois de copiar, rode o Discover em "Sistema → Gerenciar → Extensões → Discover" e instale/habilite o plugin.

**2. Página de relatório e recursos estáticos** — **não precisa de controller nem de registro de rota**: antes de o profiling começar o plugin inspeciona o caminho da requisição: um acerto no caminho do relatório `/xhprof` devolve a página de relatório na hora, e um acerto no caminho de assets (prefixo padrão `/xhprof-assets`) devolve o recurso estático direto.

**3. Configuração** — os padrões ficam no pacote, em `src/Joomla/config/xhprof.php`; veja "Referência de configuração" para os campos. **Trade-off conhecido**: ele lê o arquivo de configuração do pacote em vez de ler os parâmetros do plugin — parâmetros de plugin exigem uma leitura no banco de dados, e a configuração é lida a cada requisição.

**4. Fronteiras do profiling** — a janela é `ApplicationEvents::AFTER_INITIALISE` → `ApplicationEvents::AFTER_RESPOND`; um `register_shutdown_function` idempotente também é registrado em `AFTER_INITIALISE`, porque não há garantia de que `AFTER_RESPOND` seja alcançado no caminho de exceção — sem esse fallback o estado de profiling vazaria para a requisição seguinte.

---

### Drupal

**1. Habilite o módulo** — o `drupal/xhprof/` do pacote é um módulo Drupal padrão (`xhprof.info.yml` / `xhprof.routing.yml` / `xhprof.services.yml`). Coloque-o em `modules/custom/xhprof/` do seu site e habilite-o na página "Extend" (ou com `drush en xhprof`).

**2. Página de relatório e recursos estáticos** — o Drupal é **o único dos onze frameworks que segue o formato «módulo + rotas»**: o `xhprof.routing.yml` registra o caminho do relatório `/xhprof` e o dos recursos `/xhprof-assets`, servidos por padrão pelo controller do módulo; as outras dez classes de entrada fazem curto-circuito antes de o profiling começar e servem a página de relatório e os recursos estáticos sem registrar rotas. **Com um prefixo `assets_url` próprio, os recursos passam ao middleware**: o path da rota de recursos do módulo é fixo no `xhprof.routing.yml` (`/xhprof-assets/{file}`) e nunca casa com outro prefixo.

**3. Configuração** — a configuração é config tipada de nível de módulo: os padrões ficam em `drupal/xhprof/config/install/xhprof.settings.yml`, com o schema em `drupal/xhprof/config/schema/xhprof.schema.yml`. Veja "Referência de configuração" para os campos.

**4. Registro do middleware** — registre o serviço do middleware no `xhprof.services.yml` do módulo:

```yaml
services:
  xhprof.http_middleware:
    class: ErikWang2013\Xhprof\Drupal\XhprofMiddleware
    arguments: ['@config.factory', '@logger.factory']
    tags:
      - { name: http_middleware, priority: 1000 }
```

O kernel interno é **inserido automaticamente como argumento 0 do construtor** pelo `StackedKernelPass` do Drupal — **não o escreva você mesmo**: fazer isso gera dois kernels internos, o que falha na compilação do container no Drupal <= 11.2.x (incluindo todo o 10.x) e lança um TypeError a cada requisição no 11.3.0 e acima. O profiling para no `finally`.

**5. Observações**

- `priority: 1000` coloca o middleware **fora do cache de página** (a maior prioridade que já existe no core é a de negociação: 400 no D10, 500 no D11, enquanto o cache de página é 200), então **requisições servidas pelo cache de página do Drupal ainda são perfiladas**. Para uma ferramenta de profiling esse é o comportamento pretendido, mas o usuário deve saber disso.
- O cache funciona sem nenhuma configuração: o middleware usa por padrão o adaptador Redis que acompanha este pacote (o pacote depende obrigatoriamente da ext-redis), e também aceita um argumento opcional `CacheInterface` via `arguments` no `services.yml` para sobrescrevê-lo. Se o cache estiver indisponível, uma gravação que falhou é engolida pelo `XhprofProfiler::stop()` em uma única linha de log — **nenhum erro é levantado**.
- Requisições para a página de relatório `/xhprof` e para `/xhprof-assets/*` **não são perfiladas**: o middleware pula o profiling por caminho antes do `xhprofStart()`. A resposta ainda é produzida pelo Controller do `xhprof.routing.yml` (**isto não é um curto-circuito**). Portanto, mesmo com `ignore_url_arr` definido como `[]` (não filtrando nada), essas duas requisições nunca aparecem no relatório.

### PHP puro (sem framework)

Para aplicações sem framework, com apenas um front controller (como `public/index.php`).

**1. Adicione uma linha no topo do arquivo de entrada**:

```php
\ErikWang2013\Xhprof\Native\XhprofBootstrap::start();
```

Para mudar a configuração, passe o array nessa mesma linha (o conjunto de chaves é o mesmo das outras dez; os padrões ficam em `src/Native/config/xhprof.php`):

```php
\ErikWang2013\Xhprof\Native\XhprofBootstrap::start([
    'enable' => true,
    'auth_token' => 'xxx',
]);
```

O segundo e o terceiro argumentos são pontos de injeção opcionais: `CacheInterface $cache` e `LoggerInterface $logger` (por padrão, o adaptador Redis deste pacote e `error_log`). O valor de retorno é a instância de entrada desta requisição (`stop()` é idempotente); chame-o para parar mais cedo no mesmo processo.

**2. Página de relatório e recursos estáticos** — **não precisa de controller nem de registro de rota**: esta linha inspeciona o caminho da requisição antes de o profiling começar: um acerto no caminho do relatório `/xhprof` devolve a página de relatório na hora (com `Content-Type: text/html; charset=UTF-8` e `Cache-Control: no-cache, private`; `auth_token` continua valendo), e um acerto no caminho dos assets (o prefixo é lido da opção `assets_url`, padrão `/xhprof-assets`) devolve o recurso estático direto. **O custo está escrito às claras**: depois de atender esses dois caminhos há um `exit` — o resto da requisição (as rotas após esta linha, o bootstrap do contêiner, o início da sessão e a lógica de encerramento registrada pela própria aplicação) não roda.

**3. Janela de profiling = esta linha → encerramento do processo** (o que se registra é `register_shutdown_function`). **Os limites, como são**: não inclui o código **anterior** a esta linha (autoload do composer, bootstrap do front controller) nem o que outros processos ou extensões fazem (a análise da requisição no php-fpm, o tratamento do lado do nginx). Fim normal, `exit` e Error / exceções não capturados chegam ao ponto de parada; `SIGKILL` / OOM killer não — o estado do profiling some com o processo e não fica para a próxima requisição. Para estreitar o escopo há a opção `ignore_url_arr` (correspondência de subcadeia sobre `uri()`, vale sem mexer no código).

**4. Rode de verdade uma vez com `php -S`**:

```sh
# No topo de public/index.php há XhprofBootstrap::start(), e esse arquivo é o front controller
php -S 127.0.0.1:8000 -t public public/index.php
```

Acesse `http://127.0.0.1:8000/` para gerar dados e depois `http://127.0.0.1:8000/xhprof` para ver o relatório — os dois no mesmo processo, e os assets também ficam verificados.

---

## Referência de configuração

Todos os frameworks compartilham estas opções de configuração:

| Config | Tipo | Padrão | Descrição |
|--------|------|---------|-------------|
| `enable` | bool | `true` | Habilita/desabilita o profiling |
| `time_limit` | int | `0` | Perfila apenas requisições acima de n segundos; 0 significa todas |
| `log_num` | int | `1000` | Número máximo de registros |
| `view_wtred` | int | `3` | Destaca em vermelho as linhas com tempo de resposta > n segundos |
| `ignore_url_arr` | array | `["/xhprof"]` | Caminhos de URL a ignorar |
| `assets_url` | string | `/xhprof-assets` | Prefixo de URL dos recursos estáticos |
| `auth_token` | string\|null | `null` | Quando definido, a página de relatório exige `?token=xxx`; recomendado para implantações públicas |
| `key_prefix` | string | `xhprof` | Prefixo das chaves no Redis; use valores distintos por projeto quando compartilhar um mesmo Redis |
| `log_ttl` | int | `604800` | Retenção dos dados em segundos (padrão: 7 dias) |
| `locale` | string\|null | `null` | Idioma da página de relatório: `zh_CN`/`en`/`ko`/`ru`/`de`/`fr`/`es`/`pt`/`ar`/`hi`/`bn`/`id`/`ja`; `null` = seguir o `Accept-Language` do navegador e, sem correspondência, usar chinês; `?lang=xx` sobrescreve em uma requisição |

As limitações conhecidas dessas opções em cada framework estão em [Verificação e limitações conhecidas](#verificação-e-limitações-conhecidas).

**Seletor de idioma da página de relatório**

A lista suspensa à direita da navegação enumera os 13 idiomas pelo **próprio nome** (o `_meta.name` de cada catálogo, por exemplo 한국어, 日本語). O link de cada opção é montado a partir da **string de consulta da página atual** (`XhprofLib::report_url()`), então `?token=`, a ordenação, `run` e todos os demais parâmetros seguem junto; trocar de idioma **não sai da visualização atual** — em um relatório de execução você continua na mesma execução.

**Diagnóstico na página de relatório**

O cartão mais alto do corpo do relatório é o de Diagnóstico (logo abaixo da descrição da execução): primeiro Por que está lento (no máximo 3 causas) e depois Outras descobertas (no máximo 3 verificações). O link ver após cada conclusão abre a página de detalhes do método; a recursão (R4) só tem link quando o nome simples está realmente na tabela de símbolos — o xhprof expande a recursão em `fib@1`/`fib@2`, e se só existirem os nomes expandidos, a página de detalhes não encontra `fib`. As seis regras e seus limites:

- **R1** tempo exclusivo ≥ 10% do tempo total da requisição;
- **R2** número de chamadas ≥ 1000;
- **R3** chamadas de uma mesma aresta ≥ 500 **e** tempo exclusivo da função chamada ≥ 5% do tempo total da requisição;
- **R4** um mesmo símbolo aparece em ≥ 2 profundidades diferentes (recursão);
- **R5** pico de memória próprio ≥ 30% do pico global;
- **R6** tempo exclusivo > tempo inclusivo (`excl_wt > wt`, logicamente impossível) — uma sonda de integridade dos dados, que nunca dispara com dados saudáveis.

Os limites são constantes em `src/Core/Analysis/Analyzer.php` e hoje **nenhuma opção de configuração** consegue alterá-los ou desativar o cartão (com `enable` desligado nada é amostrado, logo não há o que diagnosticar). Ele aparece **apenas na visualização de execução única de nível superior**: nem a visualização diff nem a página de detalhes de um método o renderizam — os `$symbol_tab`/`$totals` que recebem não são valores de uma única execução (no modo diff são os incrementos run2 − run1).

---

## Inicialização manual

Se a detecção automática de framework falhar, você pode injetar os adaptadores manualmente:

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

**Os seis novos frameworks (Yii3 / Symfony / Slim 4 / WordPress / Joomla / Drupal) não podem chamar o `Xhprof::bootstrap()` sem argumentos** — sem argumentos ele passa pelo `autoDetect()`, que só conhece os ramos webman / Laravel / ThinkPHP / Hyperf e lança `Unsupported framework` nesses seis. Passe os 5 adaptadores explicitamente, como no exemplo acima (a classe de entrada que acompanha cada framework já faz isso por você).

---

## Arquitetura e design

O Core alcança o framework por exatamente 5 contratos, todos em `src/Core/Contract/`:

| Contrato | Métodos | Finalidade |
|----------|---------|---------|
| `RequestInterface` | `get()` `all()` `method()` `header()` `host()` `uri()` `url()` `getRealIp()` | Ler dados da requisição, casar o caminho do relatório, montar links da página de relatório |
| `ResponseInterface` | `withBody()` `withHeaders()` `withStatus()` `file()` `send()` | Emitir a página de relatório, os recursos estáticos e 400/403 |
| `ConfigInterface` | `get()` | Ler a configuração do plugin: `get('xhprof')` para o bloco inteiro, `get('xhprof.assets_url')` para uma folha |
| `CacheInterface` | `get()` `set()` `mget()` `incr()` `lPush()` `rPop()` `lRange()` `del()` `decr()` | Leituras e escritas no Redis |
| `LoggerInterface` | `error()` | Avisos de extensões ausentes e de gravações que falharam |

Cada framework fornece 5 adaptadores que implementam esses contratos, registrados no Core pelo `Xhprof::bootstrap()`. Tudo que é específico de um framework fica dentro do diretório `src/<Fw>/` daquele framework.

**Restam apenas dois acoplamentos a frameworks no Core**:

1. A cadeia de `class_exists()` em `Xhprof::autoDetect()` (`Webman\App` → `Illuminate\Foundation\Application` → `think\App` → `Hyperf\Context\ApplicationContext`), alcançada apenas pelo `bootstrap()` sem argumentos.
2. A chave de corrotina do Hyperf embutida no código: `Xhprof::markHyperfContext()` mais as checagens de existência de `\Hyperf\Context\Context`, que decidem se os adaptadores vão para propriedades estáticas do processo inteiro ou para o Context da corrotina.

**Os seis novos frameworks nunca passam pelo `autoDetect()` — todos usam injeção explícita**: cada classe de entrada constrói os seus próprios 5 adaptadores e os passa para `Xhprof::bootstrap($req, $res, $cfg, $cache, $log)`. A razão é que, em frameworks PSR-7, o Request/Response só pode ser obtido do pipeline da requisição, então um `bootstrap()` sem argumentos não tem como funcionar por construção; um segundo benefício é que o `autoDetect()` fica congelado nos quatro frameworks atuais.

![Arquitetura](docs/images/architecture.svg)

O primeiro diagrama é a **estrutura**: a classe de entrada de cada um dos onze frameworks, os 5 contratos, as três camadas do Core e os dois únicos acoplamentos que restam.

![Razões do design](docs/images/design.svg)

O segundo diagrama é o **raciocínio**: cinco trade-offs dispostos como decisão / motivo / custo, encabeçados por "mudanças em `src/Core/` pelos seis novos frameworks = 0".

---

## Ciclo de vida da requisição

Uma requisição perfilada:

1. **Antes de o profiling começar**, a classe de entrada inspeciona o caminho: um acerto no caminho do relatório devolve a página de relatório na hora; um acerto no caminho de assets devolve o recurso estático na hora. Nenhum dos dois caminhos é perfilado, e nenhum dos dois entra no fluxo abaixo.
2. O `XhprofProfiler::isEnabled()` lê `enable` da configuração; se o profiling estiver desligado ou faltar uma extensão, todo o bloco é pulado.
3. `Xhprof::xhprofStart()` → `XhprofProfiler::start()` → `xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY)`.
4. A lógica de negócio executa.
5. `finally { Xhprof::xhprofStop(); }` → `xhprof_disable()`, e então `XHProfRunsDefault::save_run()` grava no Redis. `finally` em vez de um comando solto, para que uma exceção lançada ainda limpe o estado de profiling e salve o run.
6. O navegador abre a página de relatório; o `Xhprof::index()` lê os dados de volta do Redis e os renderiza.

![Ciclo de vida](docs/images/lifecycle.svg)

| Framework | Profiling começa | Profiling termina |
|-----------|-----------------|----------------|
| webman / Laravel / ThinkPHP / Hyperf | Entrada do middleware (`process()` / `handle()`) | `finally` |
| Yii3 / Slim 4 | PSR-15 `process()` | `finally` |
| Symfony | `kernel.request` (prioridade 10000) | `kernel.response` (prioridade -10000), mais um fallback de shutdown |
| WordPress | `plugins_loaded` | `shutdown` |
| Joomla | `onAfterInitialise` | `onAfterRespond`, mais um fallback de shutdown |
| Drupal | `http_middleware` (prioridade 1000, o mais externo) | `finally` |
| PHP puro (sem framework) | Uma linha `XhprofBootstrap::start()` no topo do arquivo de entrada | Encerramento do processo (`register_shutdown_function`), e `stop()` para parar antes |

---

## Estrutura do projeto

```
xhprof-webman/
├── src/
│   ├── Core/                     # Agnóstico de framework: contratos, página de relatório, Redis, assets
│   │   ├── Contract/             # as 5 interfaces de contrato
│   │   ├── XhprofLib/            # renderização do relatório e armazenamento de runs (de phacility/xhprof)
│   │   ├── Xhprof.php            # fachada estática: bootstrap() / index()
│   │   ├── XhprofProfiler.php    # xhprof_enable/disable e configuração
│   │   ├── StaticController.php  # recursos estáticos de /xhprof-assets
│   │   ├── MiddlewareTrait.php   # wrapper de profiling compartilhado por Laravel / ThinkPHP
│   │   └── RedisAdapterTrait.php # implementação compartilhada do adaptador Redis
│   ├── Webman/ Laravel/ Thinkphp/ Hyperf/            # os 4 frameworks já existentes
│   ├── Yii3/ Symfony/ Slim/ Wordpress/ Joomla/ Drupal/   # os 6 novos frameworks
│   ├── Native/                   # PHP puro (sem framework): classe de entrada e 5 adaptadores
│   └── html/                     # assets da página de relatório (css / js / images / pet.svg ícone do site e ícone da marca)
├── wordpress/                    # arquivo de bootstrap do mu-plugin (com cabeçalho de plugin)
├── joomla/                       # plugin Joomla (CMSPlugin + manifesto)
├── drupal/xhprof/                # módulo Drupal padrão (info / routing / services + controller)
├── tools/contracts/              # ciclo de verificação independente: assinaturas e semântica contra os pacotes reais dos frameworks (`legacy-symfony64/` é a perna 6.4)
├── tools/i18n/                   # cadeia de ferramentas de tradução do README e dos três SVGs (gerar / verificar / autoteste)
├── docs/i18n/                    # os 12 resultados traduzidos (inglês, coreano, russo, alemão, francês, espanhol, português, árabe, hindi, bengali, indonésio, japonês)
├── tests/                        # PHPUnit: testes dos adaptadores, da fiação, do Core, e paridade estrutural dos 14 READMEs
└── docs/images/                  # diagramas do README
```

Com exceção do Drupal, todo diretório `src/<Fw>/` tem o mesmo formato:

```
src/<Fw>/
├── Adapter/{Request,Response,Config,Redis,Log}Adapter.php
├── <EntryClass>.php
└── config/xhprof.php             # as mesmas 10 chaves de configuração de todos os outros frameworks
```

O `src/Drupal/` é a única exceção: ele não tem diretório `config/` — a configuração dele fica em config tipada de nível de módulo (`drupal/xhprof/config/install/xhprof.settings.yml`).

---

## Verificação e limitações conhecidas

**O que é provado mecanicamente**

| Item | Como |
|------|-----|
| Comportamento dos adaptadores e do wiring das entradas | `tests/Unit/Adapter/*Test.php`: habilitado → salvo / desabilitado → não salvo / exceção de negócio → ainda assim salvo via `finally` |
| Os onze frameworks compartilham um mesmo conjunto de chaves de configuração | teste de paridade de config (conjuntos de chaves, não byte a byte; os comentários podem diferir) |
| Os dois READMEs espelham um ao outro | teste de paridade do README: compara a sequência de títulos `##` / `###` e o número de blocos de código |
| Os métodos que os adaptadores chamam realmente existem | ciclo de verificação `tools/contracts/` (job próprio de CI, **duas pernas**: a perna principal instala os pacotes mais recentes de cada framework, e o projeto separado `tools/contracts/legacy-symfony64` roda o mesmo caso do Symfony contra o 6.4): instala pacotes reais dos frameworks (`drupal/core` real para o Drupal, dois pacotes de versão reais do CMS para o Joomla) e verifica por reflection que cada método / constante / função global existe **para os oito frameworks do ciclo** (Slim / Symfony / Yii3 / Joomla / WordPress / Drupal / Laravel / Webman); ThinkPHP / Hyperf não estão no ciclo — veja abaixo |
| Semântica dos adaptadores | O mesmo ciclo instancia objetos reais de request e response e executa os adaptadores, com dois invariantes: `uri()` não carrega scheme/host, e `withHeaders()` continua valendo depois de `file()`. O número de SKIP do ciclo é uma constante congelada (2 na perna principal, 0 na perna 6.4) e os dois SKIP estão no Joomla: o caminho de leitura real de `#__extensions.params` e o formato do instalador — ambos precisam de banco de dados ou instalador para rodar |


**Não verificado automaticamente (não leia isto como «tudo está coberto»)**

| Item | Por que não |
|------|---------|
| O **wiring** de cada framework (o hook está mesmo ligado, o evento dispara mesmo) | Os testes unitários usam stubs; hoje o wiring só pode ser confirmado por smoke tests manuais |
| Os dois subitens restantes do Joomla | As duas coisas que o ciclo ainda não alcança, e ambas pelo mesmo motivo (precisam de banco de dados ou instalador): o caminho de leitura real de `#__extensions.params` (`PluginHelper::getPlugin()` → `bootPlugin()`) e o formato do instalador (namespacemap escrito, `bootPlugin()` achando a classe) |
| A autoconfiguração de `kernel.event_subscriber` do Symfony | Exige uma compilação real do container |
| Vazamento de estado estático em processos de longa duração | Lado do Webman inalterado (no Hyperf os 9 valores de estado de render por requisição são isolados no Context da corrotina, fixados por `tests/Unit/Lib/RenderStateCoroutineTest.php` com uma corrotina que cede de verdade) |
| I/O real no Redis, renderização no navegador, overhead de profiling sob carga real | O I/O real no Redis **agora está no ciclo** (`cases/Redis.php`: phpredis real + uma requisição Slim real de ponta a ponta — requisição → persistência → lista → página do relatório); a renderização no navegador e o overhead sob carga real continuam fora do escopo dos testes unitários e do ciclo |
| Assinaturas e semântica dos adaptadores de ThinkPHP / Hyperf | esses dois não estão no ciclo de verificação (que cobre oito frameworks); seus stubs são escritos à mão em `tests/Stubs/framework-stubs.php`, sem comparação com os pacotes reais |

**Checklist manual de smoke (três passos por framework)**

| Passo | Ação | Esperado |
|------|--------|----------|
| 1 | Monte a classe de entrada como descrito em "Configuração por framework" | Nenhum erro |
| 2 | Acesse qualquer URL da aplicação | O tamanho da chave `xhprof:run_id` no Redis aumenta em 1 |
| 3 | Abra `/xhprof` | A página de relatório renderiza com os estilos; `/xhprof-assets/js/xhprof_report.js` retorna 200 |

**Teste de fumaça do PHP puro**: suba o servidor embutido com `php -S 127.0.0.1:8000 -t public public/index.php` (passo 4 de "PHP puro") e faça os três passos — a página de relatório e os recursos ficam no **mesmo processo** das requisições de negócio, então o passo 3 é verificado na hora.

**Limitação conhecida: o `request_uri` exibido na lista não tem porta**

O contrato `host()` significa "só o host, sem porta" (R-2), e os onze frameworks o cumprem — só a implementação difere: o `getHost()` do PSR-7 nunca carrega a porta, Joomla / WordPress a cortam à mão com `parse_url`, e Webman / ThinkPHP precisam do argumento estrito `host(true)` (o padrão devolve o cabeçalho `Host` como está, porta incluída). O `request_uri` exibido na lista é montado como `host() . uri()` (`src/Core/XhprofLib/Utils/XHProfRunsDefault.php`), então numa porta fora do padrão (por exemplo `:8080`) o **texto** dessa linha não mostra a porta. **Os links em si não são afetados**: os da lista e os do relatório são todos montados por `XhprofLib::report_url()` como URLs relativas (só caminho + query), abrem a página certa e não dependem de `host()`.

**O `assets_url` agora aceita um prefixo personalizado**

O prefixo de assets não é mais uma constante fixa: `src/Core/StaticController.php` casa os caminhos de assets com a opção `assets_url` (padrão `/xhprof-assets`, barra final opcional). A limitação restante em implantação num subdiretório é a do Drupal, abaixo. **Os onze frameworks seguem essa opção**: dez classes de entrada fazem curto-circuito no caminho dos recursos antes do início do profiling e os servem, enquanto o Drupal serve o prefixo padrão pela rota do módulo + controller e entrega um prefixo próprio ao middleware. **Limite**: Laravel, Hyperf, Webman e ThinkPHP não precisam mais de controller nem de rotas — o middleware roda antes, então o controller e as duas rotas registrados conforme as instruções antigas ficam apenas sombreados: não dão erro e nunca mais são alcançados.

**Limitação conhecida: a guarda de caminho falha quando o Drupal fica em um subdiretório**

Quando o Drupal é instalado sob um subdiretório (por exemplo `/sites/app/xhprof`), a guarda de caminho não consegue casar uma URI que carrega o caminho base, então o comportamento cai para "perfilado mas não salvo" (com a configuração padrão, o `ignore_url_arr` pega esse caso).

**Compatibilidade com o Symfony 6.4**

A compatibilidade com o Symfony 6.4 foi medida (foi assim que dois over-fits invisíveis no 7.4 foram corrigidos: as propriedades de `Request` não têm declaração de tipo nativa no 6.4, e o charset adicionado por `prepare()` difere em maiúsculas e minúsculas). **As duas pernas rodam na CI**: a perna principal 7.x mais o projeto separado `tools/contracts/legacy-symfony64`, que executa o mesmo arquivo de caso sem copiá-lo — e as duas pernas também estão no gate de tags.

---

## Autor

[erik](https://erik.xyz)

Este pacote é distribuído sob a licença MIT (ver `LICENSE`); `src/Core/XhprofLib/**`, `src/html/js/xhprof_report.js` e `src/html/css/xhprof.css` derivam do [phacility/xhprof](https://github.com/phacility/xhprof) (Apache-2.0) e permanecem sob esses termos; a lista das bibliotecas front-end de terceiros está em `NOTICE`.

## Apoie o código aberto

<p align="center">
  <img src="./docs/weixinpay.png" alt="WeChat Pay" width="130" height="130" title="WeChat Pay" />
  <img src="./docs/alipay.png" alt="Alipay" width="130" height="130" title="Alipay" />
</p>

---

Este plugin faz referência a [phacility/xhprof](https://github.com/phacility/xhprof) e [xiexianbo123/xhprof](https://github.com/xiexianbo123/xhprof).
