<?php

declare(strict_types=1);

/**
 * 框架最小 stub，仅覆盖本包适配器/中间件实际调用的方法。
 * 仅测试进程加载，不参与生产运行。
 */

namespace support {
    class Redis
    {
        public static array $store = [];
        public static array $log = [];

        public static array $ttls = [];

        public static function reset(): void
        {
            self::$store = [];
            self::$log = [];
            self::$ttls = [];
        }

        public static function get(string $key): mixed
        {
            self::$log[] = "get:$key";
            return self::$store[$key] ?? null;
        }

        /** phpredis 的 set() 返回 bool，不是原值 */
        public static function set(string $key, mixed $value, int $ttl = 0): mixed
        {
            self::$log[] = "set:$key";
            self::$store[$key] = $value;
            self::$ttls[$key] = $ttl;
            return true;
        }

        /** phpredis 签名：setex(key, seconds, value) */
        public static function setex(string $key, int $ttl, mixed $value): bool
        {
            self::$log[] = "set:$key";
            self::$store[$key] = $value;
            self::$ttls[$key] = $ttl;
            return true;
        }

        public static function mget(array $keys): array
        {
            self::$log[] = 'mget';
            $out = [];
            foreach ($keys as $key) {
                $out[] = self::$store[$key] ?? null;
            }
            return $out;
        }

        public static function incr(string $key): int
        {
            self::$log[] = "incr:$key";
            self::$store[$key] = (int) (self::$store[$key] ?? 0) + 1;
            return self::$store[$key];
        }

        public static function decr(string $key): int
        {
            self::$log[] = "decr:$key";
            self::$store[$key] = (int) (self::$store[$key] ?? 0) - 1;
            return self::$store[$key];
        }

        public static function lPush(string $key, mixed $value): int
        {
            self::$log[] = "lpush:$key";
            self::$store[$key] ??= [];
            array_unshift(self::$store[$key], $value);
            return count(self::$store[$key]);
        }

        public static function rpop(string $key): mixed
        {
            self::$log[] = "rpop:$key";
            if (empty(self::$store[$key])) {
                return null;
            }
            return array_pop(self::$store[$key]);
        }

        public static function lrange(string $key, int $start, int $end): array
        {
            self::$log[] = "lrange:$key";
            $list = self::$store[$key] ?? [];
            if ($end < 0) $end += count($list);   // 负数下标同真实 Redis
            return array_slice($list, $start, $end - $start + 1);
        }

        public static function del(mixed ...$keys): int
        {
            self::$log[] = 'del';
            $n = 0;
            foreach ($keys as $key) {
                if (isset(self::$store[$key])) {
                    unset(self::$store[$key]);
                    $n++;
                }
            }
            return $n;
        }
    }

    class Log
    {
        public static array $errors = [];

        public static function reset(): void
        {
            self::$errors = [];
        }

        public static function error(string $message, array $context = []): void
        {
            self::$errors[] = $message;
        }
    }
}

namespace Webman {
    interface MiddlewareInterface
    {
        public function process(\Webman\Http\Request $request, callable $handler): \Webman\Http\Response;
    }
}

namespace Webman\Http {
    /**
     * 桩只覆盖本包适配器调用的部分，但**来源要拆开**：$params 是 query，body 由
     * $options['post'] 单独给。真实 webman 的 Request::get() 只读 query，而
     * all() 是 `get() + post()`（webman-framework v2.2.4 src/Http/Request.php:71），
     * 合成一份就测不出「同一个 key 两种取法不同源」。
     */
    class Request
    {
        private array $params;
        private array $post;
        private array $headers;
        private string $method;
        private string $host;
        private string $uri;
        private string $url;
        private string $ip;

        public function __construct(array $params = [], array $options = [])
        {
            $this->params = $params;
            $this->post = $options['post'] ?? [];
            $this->headers = $options['headers'] ?? [];
            $this->method = $options['method'] ?? 'GET';
            $this->host = $options['host'] ?? 'localhost';
            $this->uri = $options['uri'] ?? '/';
            $this->url = $options['url'] ?? 'http://localhost/';
            $this->ip = $options['ip'] ?? '127.0.0.1';
        }

        public function get(string $key, mixed $default = null): mixed
        {
            // 真实实现只读 query（`return $this->get[$name] ?? $default;`）
            return $this->params[$key] ?? $default;
        }

        public function post(string $key, mixed $default = null): mixed
        {
            return $this->post[$key] ?? $default;
        }

        public function all(): array
        {
            // 真实实现：`return $this->get() + $this->post();` —— query 胜出
            return $this->params + $this->post;
        }

        public function method(): string
        {
            return $this->method;
        }

        public function header(string $name): ?string
        {
            return $this->headers[$name] ?? null;
        }

        /**
         * 真实签名 host(bool $withoutPort = false): ?string（workerman 5.2.2
         * src/Protocols/Http/Request.php:291）：
         *   $host = $this->header('host');
         *   if ($host && $withoutPort) { return preg_replace('/:\d{1,5}$/', '', $host); }
         *   return $host;
         * 桩照抄这段：默认原样给 Host 头（**带端口**），$withoutPort=true 才剥，且只剥
         * 尾部 1-5 位纯数字端口 —— 实测 'example.com:8080'→'example.com'，而
         * 'example.com:'（冒号后非数字）原样返回。
         *
         * 桩不模拟「无 Host 头 → null」那一态（$options 缺省给 'localhost'），所以
         * Webman\RequestAdapter 里那句 `(string)` 兜底在单测里跑不到；要覆盖它得先让桩
         * 能表达「没有 Host 头」（显式传 'host' => null 的语义）。
         */
        public function host(bool $withoutPort = false): ?string
        {
            if ($this->host && $withoutPort) {
                return preg_replace('/:\d{1,5}$/', '', $this->host);
            }
            return $this->host;
        }

        public function uri(): string
        {
            return $this->uri;
        }

        public function url(): string
        {
            return $this->url;
        }

        public function getRealIp(bool $safe_mode = false): string
        {
            return $this->ip;
        }
    }

    /**
     * 真包是两层：webman-framework v2.2.4 src/Http/Response.php:26
     * `class Response extends \Workerman\Protocols\Http\Response`，只加了 file()/download()/
     * exception() 和 $exception；下面这些成员全在父类
     * workerman 5.2.2 src/Protocols/Http/Response.php 里，照它的**声明**搬：
     *
     *   - 构造参数顺序 (int $status, array $headers, string $body)（:267-271）
     *   - 唯一的 public 属性是 `?array $file`（:56），形状见 withFile()（:434）
     *   - $status / $headers / $body / $reason 都是 **protected**（:42、:268-270），
     *     取用只能走 getHeader()/getHeaders()/getStatusCode()/rawBody()
     *
     * 所以 `$res->headers['X-A']` 在真包上是 `Error: Cannot access protected property`，
     * 而 `$res->filePath` 这个属性真包上**根本不存在**（文件路径记在 `$file['file']` 里）。
     * 桩此前把 status/headers/body 开成 public 并自带一个 $filePath，两种写法在桩上都绿 ——
     * 桩比真包宽松，红不了就等于没检查（详见 memory: prove-the-check-fails-when-it-should）。
     *
     * 真包的 header()/withHeader()/withoutHeader()/getMimeType()/cookie()/__toString() 没搬：
     * 本包适配器与测试都不调它们。
     */
    class Response
    {
        /** 真包唯一的 public 属性（workerman 5.2.2 src/Protocols/Http/Response.php:56） */
        public ?array $file = null;

        protected int $status = 200;
        protected array $headers = [];
        protected string $body = '';
        /** 真名就是 $reason（:42 `protected ?string $reason = null;`） */
        protected ?string $reason = null;

        public function __construct(int $status = 200, array $headers = [], string $body = '')
        {
            $this->status = $status;
            $this->headers = $headers;
            $this->body = $body;
        }

        /** workerman :406-410 —— 就地改并返回 $this */
        public function withBody(string $body): static
        {
            $this->body = $body;
            return $this;
        }

        /**
         * 真实签名 withStatus(int $code, ?string $reasonPhrase = null): static
         * （workerman 5.2.2 src/Protocols/Http/Response.php:361），改的是 $this 并返回它。
         * 响应对象是**可变**的 —— 这正是本包 Webman 适配器能用「先设头后设状态」而不丢头
         * 的前提；少了它，适配器就只能 new 一个新响应，先设的头与正文全丢。
         * （对照：Hyperf 的同名方法走 PSR-7，返回**新**实例。）
         */
        public function withStatus(int $code, ?string $reasonPhrase = null): static
        {
            $this->status = $code;
            $this->reason = $reasonPhrase !== null ? str_replace(["\r", "\n"], '', $reasonPhrase) : null;
            return $this;
        }

        /**
         * workerman :304-308。注意真包用的是 array_merge_recursive（**不是** array_merge）：
         * 同名头再来一次会并成数组。think 那边正好相反（单数数组 + array_merge），两边别抄串。
         */
        public function withHeaders(array $headers): static
        {
            $this->headers = array_merge_recursive($this->headers, $headers);
            return $this;
        }

        /** workerman :328-331 —— 大小写敏感，没有这个头给 null（不是空串） */
        public function getHeader(string $name): array|string|null
        {
            return $this->headers[$name] ?? null;
        }

        /** workerman :338-341 */
        public function getHeaders(): array
        {
            return $this->headers;
        }

        /** workerman :373-376 */
        public function getStatusCode(): int
        {
            return $this->status;
        }

        /** workerman :417-420 */
        public function rawBody(): string
        {
            return $this->body;
        }

        /**
         * workerman :430-438，逐字照抄判别逻辑：
         *   文件不存在 → withStatus(404)->withBody('<h3>404 Not Found</h3>')，**不记路径**；
         *   存在       → $this->file = ['file'=>路径,'offset'=>0,'length'=>0]，正文**仍为空**
         *                （内容由 __toString()/ResponseEmitter 直接从磁盘流出去，不进对象）。
         * 旧桩两半都反着来（缺文件也记路径、正文塞文件内容），于是
         * WebmanTest::responseAdapterFile 断言的是桩的臆造行为，真包上两条都不成立。
         */
        public function withFile(string $file, int $offset = 0, int $length = 0): static
        {
            if (!is_file($file)) {
                return $this->withStatus(404)->withBody('<h3>404 Not Found</h3>');
            }
            $this->file = ['file' => $file, 'offset' => $offset, 'length' => $length];
            return $this;
        }

        /**
         * 真实 file()（webman-framework v2.2.4 src/Http/Response.php:38-44）先问
         * notModifiedSince($file)，命中就 withStatus(304)；那个判断读
         * `App::request()->header('if-modified-since')`（:68-78），单测里没有 App，
         * 桩直接走 withFile() —— 即「请求不带 If-Modified-Since」那条分支。
         */
        public function file(string $file): static
        {
            return $this->withFile($file);
        }
    }
}

namespace Illuminate\Support\Facades {
    class Redis
    {
        public static array $store = [];
        public static array $log = [];

        public static function reset(): void
        {
            self::$store = [];
            self::$log = [];
        }

        public static function __callStatic(string $name, array $args): mixed
        {
            self::$log[] = $name;
            $methods = [
                'get' => fn ($k) => self::$store[$k] ?? null,
                'set' => function ($k, $v, $ttl = 0) {
                    self::$store[$k] = $v;
                    return true;   // phpredis 返回 bool
                },
                'setex' => function ($k, $ttl, $v) {
                    self::$store[$k] = $v;
                    return true;
                },
                'mget' => function (array $keys) {
                    $out = [];
                    foreach ($keys as $k) {
                        $out[] = self::$store[$k] ?? null;
                    }
                    return $out;
                },
                'incr' => function ($k) {
                    self::$store[$k] = (int) (self::$store[$k] ?? 0) + 1;
                    return self::$store[$k];
                },
                'decr' => function ($k) {
                    self::$store[$k] = (int) (self::$store[$k] ?? 0) - 1;
                    return self::$store[$k];
                },
                'lpush' => function ($k, $v) {
                    self::$store[$k] ??= [];
                    array_unshift(self::$store[$k], $v);
                    return count(self::$store[$k]);
                },
                'rpop' => function ($k) {
                    if (empty(self::$store[$k])) {
                        return null;
                    }
                    return array_pop(self::$store[$k]);
                },
                'lrange' => function ($k, $s, $e) {
                    return array_slice(self::$store[$k] ?? [], $s, $e - $s + 1);
                },
                'del' => function (...$keys) {
                    $n = 0;
                    foreach ($keys as $k) {
                        if (isset(self::$store[$k])) {
                            unset(self::$store[$k]);
                            $n++;
                        }
                    }
                    return $n;
                },
            ];
            if (!isset($methods[$name])) {
                throw new \BadMethodCallException("Illuminate Redis stub: unsupported method $name");
            }
            return $methods[$name](...$args);
        }
    }

    class Log
    {
        public static array $errors = [];

        public static function reset(): void
        {
            self::$errors = [];
        }

        public static function __callStatic(string $name, array $args): void
        {
            self::$errors[] = $name . ': ' . $args[0];
        }
    }
}

namespace Illuminate\Http {
    /**
     * 与 Webman\Http\Request 同理拆开来源：$params 是 query，body 由 $options['post'] 给。
     * 真实优先级两边**相反**，这正是本包 Laravel 适配器要收敛的那处：
     *  - get()  → Symfony Request::get()：attributes → query → body，**query 胜出**
     *  - all()  → InteractsWithInput::all() = input() + allFiles()，而
     *             input() = `getInputSource()->all() + $this->query->all()` —— **body 胜出**
     */
    class Request
    {
        private array $params;
        private array $post;
        private array $headers;
        private string $method;
        private string $host;
        private string $uri;
        private string $url;
        private string $ip;

        public function __construct(array $params = [], array $options = [])
        {
            $this->params = $params;
            $this->post = $options['post'] ?? [];
            $this->headers = $options['headers'] ?? [];
            $this->method = $options['method'] ?? 'GET';
            $this->host = $options['host'] ?? 'localhost';
            $this->uri = $options['uri'] ?? '/';
            $this->url = $options['url'] ?? 'http://localhost/';
            $this->ip = $options['ip'] ?? '127.0.0.1';
        }

        public function get(string $key, mixed $default = null): mixed
        {
            if (array_key_exists($key, $this->params)) {
                return $this->params[$key];
            }
            return array_key_exists($key, $this->post) ? $this->post[$key] : $default;
        }

        public function post(string $key, mixed $default = null): mixed
        {
            return $this->post[$key] ?? $default;
        }

        public function all(): array
        {
            return $this->post + $this->params;
        }

        public function method(): string
        {
            return $this->method;
        }

        public function header(string $name, mixed $default = null): ?string
        {
            return $this->headers[$name] ?? $default;
        }

        public function getHost(): string
        {
            return $this->host;
        }

        public function getRequestUri(): string
        {
            return $this->uri;
        }

        public function url(): string
        {
            return $this->url;
        }

        public function ip(): string
        {
            return $this->ip;
        }
    }

    /**
     * 保真度（2026-09-25 修）：真实类 `Illuminate\Http\Response extends
     * Symfony\Component\HttpFoundation\Response`，`$headers` 就是 Symfony 的
     * **ResponseHeaderBag（对象，不是数组）**，`$res->headers['X-A']` 在真包上是致命错误；
     * `withHeaders()` 来自 ResponseTrait，内部同样是 `$this->headers->set($k, $v)`。
     * 桩此前把 headers 做成数组，于是「数组下标访问」这种真包不支持的写法在单测里恒绿。
     *
     * 另：`file()` **不在这个类上**（真实的 Response 没有它），它属于 ResponseFactory ——
     * 见同文件 Illuminate\Routing\ResponseFactory。桩此前把 file() 塞在这里，正是
     * 「桩比真包宽松」掩盖 `file($path)->withHeaders([...])` 会 500 的直接原因。
     *
     * 可见性收尾（2026-09-25，同批第三处的第四家）：存储的真名是 `$content` / `$statusCode`，
     * 两者都 **protected**，且声明在父类 Symfony 的 Response 上（symfony/http-foundation
     * v7.4.19 Response.php:110 / :112；父类 :108 的 `$headers` 才是 public）。取用只能走
     * `getContent()` / `getStatusCode()`。桩此前把 `$body` / `$status` 做成 public，
     * `$res->body` 于是恒绿 —— 实测真包（illuminate/http v13.33.0）给的是
     * `Warning: Undefined property: Illuminate\Http\Response::$body` 读成 null，
     * 断言随即 `Failed asserting that null is identical to 'hello'`。
     */
    class Response
    {
        public \Symfony\Component\HttpFoundation\ResponseHeaderBag $headers;
        protected string $content;
        protected int $statusCode;

        /**
         * 参数照真包（illuminate/http v13.33.0 src/Response.php:30 无类型），
         * 三步照父类 Symfony v7.4.19 Response.php:202 的顺序，省掉协议版本那步。
         */
        public function __construct($content = '', $status = 200, array $headers = [])
        {
            $this->headers = new \Symfony\Component\HttpFoundation\ResponseHeaderBag($headers);
            $this->setStatusCode($status);
            $this->setContent($content);
        }

        /** Symfony Response::getStatusCode(): int（v7.4.19 :500）。 */
        public function getStatusCode(): int
        {
            return $this->statusCode;
        }

        /** 真包覆写 getContent(): string|false（illuminate/http v13.33.0 src/Response.php:47，transform 兜成 ''）。 */
        public function getContent(): string|false
        {
            return $this->content;
        }

        /**
         * 真实签名 setContent(mixed $content): static（illuminate/http v13.33.0
         * src/Response.php:61 覆写 Symfony 的 setContent(?string)，就地改内容并返回 $this）。
         */
        public function setContent(mixed $content): self
        {
            $this->content = (string) $content;
            return $this;
        }

        /** 真实 ResponseTrait::withHeaders()：逐个 set() 进 bag（不是 array_merge） */
        public function withHeaders(array $headers): self
        {
            foreach ($headers as $name => $value) {
                $this->headers->set((string) $name, (string) $value);
            }
            return $this;
        }

        /** Symfony Response::setStatusCode(int $code, ?string $text = null): static（v7.4.19 :477）；省掉理由短语那个可选参数。 */
        public function setStatusCode(int $code): self
        {
            $this->statusCode = $code;
            return $this;
        }
    }
}

namespace Illuminate\Routing {
    /**
     * `response()` **不带参数**时返回的就是它（真实 helper：`return app(ResponseFactory::class)`，
     * 带参数才 `->make(...)`）。契约签名见 illuminate/contracts
     * Routing/ResponseFactory.php:123 `file($file, array $headers = [])`。
     *
     * `file()` 返回的是 `new Symfony\Component\HttpFoundation\BinaryFileResponse($file, 200, $headers)`
     * —— 一个**纯 Symfony 类**（illuminate/routing ResponseFactory::file()），
     * `method_exists(..., 'withHeaders') === false`：`withHeaders()` 是 Illuminate\ResponseTrait
     * 给的，只挂在 Illuminate\Http\Response 上。桩此前把 file() 放在那个也有 withHeaders()
     * 的类上，物理上表达不了这个差别，于是 Core\StaticController::serve() 里那句
     * `$response->file($path)->withHeaders([...])` 在单测里恒绿，在真 Laravel 上是
     * `Error: Call to undefined method`（资源请求 500、报告页无 CSS/JS）。
     */
    class ResponseFactory
    {
        public function make(string $content = '', int $status = 200, array $headers = []): \Illuminate\Http\Response
        {
            return new \Illuminate\Http\Response($content, $status, $headers);
        }

        /** 签名照契约；返回类型照实现 —— Symfony 的 BinaryFileResponse，没有 withHeaders()。 */
        public function file(string $file, array $headers = []): \Symfony\Component\HttpFoundation\BinaryFileResponse
        {
            return new \Symfony\Component\HttpFoundation\BinaryFileResponse($file, 200, $headers);
        }
    }
}

namespace Illuminate\Support {
    abstract class ServiceProvider
    {
        protected array $merged = [];
        protected array $published = [];

        protected function mergeConfigFrom(string $path, string $key): void
        {
            $this->merged[$key] = $path;
        }

        protected function publishes(array $paths, mixed $groups = null): void
        {
            $this->published[] = $paths;
        }
    }
}

namespace think\facade {
    class Config
    {
        public static array $data = [];

        public static function reset(): void
        {
            self::$data = [];
        }

        public static function get(string $key, mixed $default = null): mixed
        {
            $parts = explode('.', $key);
            $value = self::$data;
            foreach ($parts as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    return $default;
                }
                $value = $value[$part];
            }
            return $value;
        }
    }

    class Log
    {
        public static array $errors = [];

        public static function reset(): void
        {
            self::$errors = [];
        }

        public static function error(string $message): void
        {
            self::$errors[] = $message;
        }
    }

    class Cache
    {
        private static ?\think\CacheStore $store = null;

        /**
         * 模拟真实 ThinkPHP 6.1 的行为：应用未配置 stores.redis 时，
         * Cache::store() 会抛 InvalidArgumentException("Store [redis] not found.")。
         * 见 think\Cache::resolveConfig → getStoreConfig。
         */
        public static bool $throwOnStore = false;

        public static function reset(): void
        {
            self::$store = null;
            self::$throwOnStore = false;
        }

        public static function store(string $name = 'redis'): \think\CacheStore
        {
            if (self::$throwOnStore) {
                throw new \InvalidArgumentException("Store [$name] not found.");
            }
            return self::$store ??= new \think\CacheStore();
        }

        public static function get(string $key): mixed
        {
            return self::store()->get($key);
        }

        public static function set(string $key, mixed $value, int $ttl = 0): mixed
        {
            return self::store()->set($key, $value, $ttl);
        }

        public static function inc(string $key): int
        {
            return self::store()->inc($key);
        }
    }
}

namespace think {
    class CacheStore
    {
        public array $data = [];
        public object $handler;

        public function __construct()
        {
            $this->handler = new ThinkFakeHandler();
        }

        public function setHandler(object $handler): void
        {
            $this->handler = $handler;
        }

        public function handler(): object
        {
            return $this->handler;
        }

        public function get(string $key): mixed
        {
            return $this->data[$key] ?? null;
        }

        public function set(string $key, mixed $value, int $ttl = 0): mixed
        {
            $this->data[$key] = $value;
            return $value;
        }

        public function inc(string $key): int
        {
            $this->data[$key] = (int) ($this->data[$key] ?? 0) + 1;
            return $this->data[$key];
        }
    }

    class ThinkFakeHandler
    {
        public array $data = [];

        public function mget(array $keys): array
        {
            $out = [];
            foreach ($keys as $key) {
                $out[] = $this->data[$key] ?? null;
            }
            return $out;
        }

        public function lPush(string $key, mixed $value): int
        {
            $this->data[$key] ??= [];
            array_unshift($this->data[$key], $value);
            return count($this->data[$key]);
        }

        public function rPop(string $key): mixed
        {
            if (empty($this->data[$key])) {
                return null;
            }
            return array_pop($this->data[$key]);
        }

        public function lRange(string $key, int $start, int $end): array
        {
            return array_slice($this->data[$key] ?? [], $start, $end - $start + 1);
        }

        public function del(mixed ...$keys): int
        {
            $n = 0;
            foreach ($keys as $key) {
                if (isset($this->data[$key])) {
                    unset($this->data[$key]);
                    $n++;
                }
            }
            return $n;
        }

        public function decr(string $key): int
        {
            $this->data[$key] = (int) ($this->data[$key] ?? 0) - 1;
            return $this->data[$key];
        }
    }

    class Request
    {
        private array $params;
        private array $headers;
        private string $method;
        private string $host;
        private string $uri;
        private string $url;
        private string $ip;

        public function __construct(array $params = [], array $options = [])
        {
            $this->params = $params;
            $this->headers = $options['headers'] ?? [];
            $this->method = $options['method'] ?? 'GET';
            $this->host = $options['host'] ?? 'localhost';
            $this->uri = $options['uri'] ?? '/';
            $this->url = $options['url'] ?? 'http://localhost/';
            $this->ip = $options['ip'] ?? '127.0.0.1';
        }

        public function param(string $key = '', mixed $default = null): mixed
        {
            if ($key === '') {
                return $this->params;
            }
            return $this->params[$key] ?? $default;
        }

        public function method(): string
        {
            return $this->method;
        }

        public function header(string $name, mixed $default = null): ?string
        {
            return $this->headers[$name] ?? $default;
        }

        /**
         * 真实签名 host(bool $strict = false): string（topthink/framework 8.1.4
         * src/think/Request.php:1706），末行逐字照抄：
         *   return true === $strict && str_contains($host, ':') ? strstr($host, ':', true) : $host;
         * —— 默认原样给 Host 头（**带端口**），$strict=true 且含冒号时取冒号前那段。
         * 实测 'example.com:8080' → host() 给 'example.com:8080'、host(true) 给 'example.com'。
         * 真实实现还会先看 HTTP_X_FORWARDED_HOST 再退回 HTTP_HOST，桩不做这层。
         */
        public function host(bool $strict = false): string
        {
            $host = $this->host;

            return true === $strict && str_contains($host, ':') ? strstr($host, ':', true) : $host;
        }

        public function url(bool $full = false): string
        {
            return $full ? $this->url : $this->uri;
        }

        public function ip(): string
        {
            return $this->ip;
        }
    }

    /**
     * 真包：topthink/framework 8.1.4 src/think/Response.php。**没有任何 public 属性**，
     * 真名与可见性是 $data(:27) / $contentType(:33) / $charset(:39) / $code(:45) /
     * $header(:63) / $content(:69)，取用走 getHeader()/getContent()/getCode()。
     * 桩此前开的是 `public $headers/$body/$status` —— 可见性错，名字也不对
     * （真包是单数 $header、$content、$code），于是 `$res->headers['X-A']` 这种写法在桩上绿、
     * 在真包上是 `Undefined property`（实测 8.1.4：warning + `Trying to access array offset on null`）。
     *
     * 已知比真包**宽**的一处：真包第 21 行是 `abstract class Response`（不能直接 new，
     * 实测 `Cannot instantiate abstract class think\Response`），应用拿到的是
     * `think\response\Html`。桩做成可实例化的具体类 —— 否则本目录的 `response()` shim
     * 与各测试的 `new Response('ok')` 都得改成造 Html，而 shim 不在本次围栏内。
     * 只放宽了「构造」，所有断言走的面仍是真面。
     *
     * 构造：真包基类**没有构造器**，应用侧是 `Response::create($data,$type,$code)`（:105-108）
     * 经容器实例化 `think\response\Html`，而 Html 的构造器收一个 DI 出来的 Cookie 再调
     * init($data,$code)（src/think/response/Html.php:29-33）。桩省掉 Cookie 那一层，
     * 把 init() 直接挂在 `__construct($data = '', int $code = 200)` 上 —— 与真实构造路径
     * 共用同一个 init($data,$code)，参数顺序也一致。
     *
     * 没搬的真实成员：contentType()/lastModified()/expires()/eTag()/cacheControl()/data() 之外的
     * 取数器与 send()。注意 init() 的第三步是 contentType()，真包因此**天生带一个
     * `Content-Type: text/html; charset=utf-8` 头**，桩的头表初始为空 —— 本包适配器在
     * file()/报告页两条链上都显式钉 Content-Type，所以这个初值差异观察不到，但别据此写断言。
     */
    class Response
    {
        protected mixed $data = null;
        protected mixed $content = null;
        protected int $code = 200;
        protected array $header = [];

        /** 真实路径 Response::create() → Html::__construct(Cookie,$data,$code) → init($data,$code) */
        public function __construct($data = '', int $code = 200)
        {
            $this->init($data, $code);
        }

        /** 真实 init()（:89-96）的三步里，超类这条只搬前两步（第三步 contentType() 见类注释） */
        protected function init($data = '', int $code = 200): void
        {
            $this->data = $data;
            $this->code = $code;
        }

        /** ：254-259 —— 真包是单数 $header 且用 array_merge（workerman 那边是 array_merge_recursive） */
        public function header(array $header = [])
        {
            $this->header = array_merge($this->header, $header);
            return $this;
        }

        /** ：368-376 —— 给了名字取一个（没有这个头给 null），不给名字返回整张表 */
        public function getHeader(string $name = '')
        {
            if (!empty($name)) {
                return $this->header[$name] ?? null;
            }
            return $this->header;
        }

        /**
         * 真实签名 content($content)（topthink/framework 8.1.4 src/think/Response.php:267），
         * 就地设内容并返回 $this。真包对非字符串/不可转字符串的输入抛 InvalidArgumentException，
         * 桩不模拟那层校验。
         */
        public function content($content)
        {
            $this->content = (string) $content;
            return $this;
        }

        /**
         * ：392-407 —— $content 为 null 时才由 $data 推导（真包走 output()，Html 的 output()
         * 就是原样返回 $this->data）；推出来之后缓存进 $content。
         */
        public function getContent(): string
        {
            if (null === $this->content) {
                $this->content = (string) $this->data;
            }
            return $this->content;
        }

        /** ：289-294 */
        public function code(int $code)
        {
            $this->code = $code;
            return $this;
        }

        /** ：417-420 */
        public function getCode(): int
        {
            return $this->code;
        }
    }
}

namespace Hyperf\Contract {
    interface ConfigInterface
    {
        public function get(string $key, mixed $default = null): mixed;
    }
}

namespace Hyperf {
    class Config implements \Hyperf\Contract\ConfigInterface
    {
        private array $config;

        public function __construct(array $config = [])
        {
            $this->config = $config;
        }

        public function get(string $key, mixed $default = null): mixed
        {
            $parts = explode('.', $key);
            $value = $this->config;
            foreach ($parts as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    return $default;
                }
                $value = $value[$part];
            }
            return $value;
        }
    }
}

namespace Hyperf\HttpServer\Contract {
    interface RequestInterface
    {
        public function input(string $key, mixed $default = null): mixed;
        public function all(): array;
        public function getMethod(): string;
        public function header(string $name): ?string;
        // 真实 Hyperf 的 Contract\RequestInterface 没有 getHost()；
        // 主机名只能经 PSR-7 的 getUri()->getHost() 取。此处曾伪造 getHost()，
        // 掩盖了 Hyperf\RequestAdapter 的真实崩溃。
        public function getUri(): \Hyperf\HttpMessage\Uri\Uri;
        public function getRequestUri(): string;
        public function url(): string;
        public function getServerParams(): array;
    }

    /**
     * 真包（hyperf/http-server src/Contract/ResponseInterface.php:22-71）里**没有**这三个
     * withX：那张表只有 json/xml/raw/html/redirect/download/write/withCookie。三条 withX
     * 是 PSR-7 的面，由 `Hyperf\HttpServer\Response`（:48 `implements PsrResponseInterface,
     * ResponseInterface`）从 PSR-7 那边带来 —— 本包适配器调的正是这三条，所以桩保留它们。
     *
     * 但**不能带返回类型**：真包的 withBody/withHeader/withStatus 声明在类上（:381/:321/:415）
     * 且返回 MessageInterface / PsrResponseInterface，而这个 `self` 是指本接口 —— 子类无法
     * 同时满足两者（返回类型必须同时是 MessageInterface 与本接口的子类型），PHP 的检查是
     * `Declaration of ... must be compatible with ...` 的加载期 Fatal。
     */
    interface ResponseInterface
    {
        public function withBody(\Psr\Http\Message\StreamInterface $body);

        public function withHeader($name, $value);

        public function withStatus(int $status);
    }
}

namespace Hyperf\HttpServer {
    class Request implements \Hyperf\HttpServer\Contract\RequestInterface
    {
        private array $params;
        private array $headers;
        private array $server;
        private string $method;
        private string $host;
        private ?int $port;
        private string $uri;
        private string $url;

        public function __construct(array $params = [], array $options = [])
        {
            $this->params = $params;
            $this->headers = $options['headers'] ?? [];
            $this->server = $options['server'] ?? [];
            $this->method = $options['method'] ?? 'GET';
            $this->host = $options['host'] ?? 'localhost';
            $this->port = $options['port'] ?? null;
            $this->uri = $options['uri'] ?? '/';
            $this->url = $options['url'] ?? 'http://localhost/';
        }

        public function input(string $key, mixed $default = null): mixed
        {
            return $this->params[$key] ?? $default;
        }

        public function all(): array
        {
            return $this->params;
        }

        public function getMethod(): string
        {
            return $this->method;
        }

        public function header(string $name): ?string
        {
            return $this->headers[$name] ?? null;
        }

        public function getUri(): \Hyperf\HttpMessage\Uri\Uri
        {
            return new \Hyperf\HttpMessage\Uri\Uri($this->host, $this->port);
        }

        public function getRequestUri(): string
        {
            return $this->uri;
        }

        public function url(): string
        {
            return $this->url;
        }

        public function getServerParams(): array
        {
            return $this->server;
        }
    }

    /**
     * 真包：hyperf/http-server v3.2.0 src/Response.php —— **没有任何 public 属性**，
     * 状态/头/正文都在 `protected ?ResponsePlusInterface $response`（:55）里，
     * 取用一律走 PSR-7 访问器：getHeaders()(:252)、getHeader()(:282)、getHeaderLine()(:303)、
     * getBody()(:365)、getStatusCode()(:393)。所以 `$res->headers['X-A']` / `$res->status`
     * 在真包上是未定义属性；桩此前把它们开成 public，两种写法在桩上都绿。
     *
     * 另一处形状差异：**PSR-7 的 with*() 返回新实例，不改原对象**。真包这三个方法都是
     * `return $this->call(__FUNCTION__, func_get_args());`（:321/:381/:415），而
     * call() 是 `new static($response->{$name}(...$arguments))`（:447-456）—— 换壳不换底。
     * 桩照这个语义用 clone 实现：丢掉返回值就等于丢掉这次修改（本包 Hyperf 适配器每一处
     * 都写了 `$this->response = $this->response->with...`，所以单测仍绿）。
     * 对照：workerman/think 的 with*() 是就地改，那两家的桩必须可变。
     *
     * 放宽的两处（真包签名 vs 桩）：
     *   - withBody(StreamInterface $body): MessageInterface（:381）→ 桩收 mixed：
     *     桩的 SwooleStream 只实现了 __toString()，没有实现 Psr7.php 里那个 13 个方法的
     *     StreamInterface，标不出这个类型。
     *   - getBody(): StreamInterface（:365）→ 桩返回原样存进去的对象（测试用 (string) 取内容）。
     *
     * 桩把状态/头/正文放在 private $status/$headerValues/$body 里：真包这三个属性一个都不存在
     * （真包里只有一个 protected ?ResponsePlusInterface $response）。私有字段故意不叫 $headers ——
     * 这样 `$res->headers[...]` 在桩上和在真包上得到的是同一种失败（Undefined property），
     * 而不是桩自己造一个「Cannot access private property」出来。
     */
    /**
     * 真包：hyperf/http-server src/Response.php:48
     * `class Response implements PsrResponseInterface, ResponseInterface` —— 它**就是**一个
     * PSR-7 响应（`Hyperf\HttpServer\Contract\ResponseInterface` 自己不继承 PSR-7，见
     * src/Contract/ResponseInterface.php:22，两者是并列的两个 implements）。
     *
     * 桩此前只声明了那个 Contract，于是「入口类短路后把本响应 return 出去」在桩上是
     * `TypeError: Return value must be of type Psr\Http\Message\ResponseInterface`
     * —— 而 process() 的返回类型正是 PSR-7，真包上这条回归永远绿不了。
     *
     * 逐字照抄真包声明（行号为 src/Response.php）：
     *   - 四个 mutator 的**静态**返回类型是 MessageInterface / PsrResponseInterface，
     *     运行时返回的却是新实例（:321/:340/:355/:381/:415 都经 call() 造新对象，不是 $this）
     *   - withStatus($code, $reasonPhrase = '') 的 $code **没有** int 类型（:415）
     *   - getBody() 给 StreamInterface（:365），withBody() 收 StreamInterface（:381）
     */
    class Response implements \Psr\Http\Message\ResponseInterface, \Hyperf\HttpServer\Contract\ResponseInterface
    {
        private string $protocolVersion = '1.1';

        /** PSR-7：头一律是「名字 → 值数组」，getHeader() 缺省给空数组而不是 null */
        private array $headerValues = [];

        private int $status = 200;

        private string $reasonPhrase = '';

        /** 真包构造出来的响应总有正文流（空流也是流），所以这里不等价于 null */
        private ?\Psr\Http\Message\StreamInterface $body = null;

        /** ：210 */
        public function getProtocolVersion(): string
        {
            return $this->protocolVersion;
        }

        /** ：226 */
        public function withProtocolVersion($version): \Psr\Http\Message\MessageInterface
        {
            $new = clone $this;
            $new->protocolVersion = (string) $version;
            return $new;
        }

        /** ：252 */
        public function getHeaders(): array
        {
            return $this->headerValues;
        }

        /** ：265 —— 头名大小写不敏感 */
        public function hasHeader($name): bool
        {
            foreach (array_keys($this->headerValues) as $key) {
                if (strcasecmp($key, (string) $name) === 0) {
                    return true;
                }
            }
            return false;
        }

        /**
         * ：282 —— PSR-7 规定：没有这个头返回**空数组**。
         * 桩此前是 `$this->headerValues[$name] ?? []`（**大小写敏感**的裸下标），
         * 于是 `getHeader('x-a')` 在桩上给 []、在真包上给 ['1']。
         */
        public function getHeader($name): array
        {
            foreach ($this->headerValues as $key => $values) {
                if (strcasecmp($key, (string) $name) === 0) {
                    return $values;
                }
            }
            return [];
        }

        /** ：303 —— 多个值用 ', ' 连接 */
        public function getHeaderLine($name): string
        {
            return implode(', ', $this->getHeader($name));
        }

        /** ：321 —— 真包 withHeader($name, $value) 是**替换**该头的值（追加用 withAddedHeader()） */
        public function withHeader($name, $value): \Psr\Http\Message\MessageInterface
        {
            $new = clone $this;
            foreach (array_keys($new->headerValues) as $key) {
                if (strcasecmp($key, (string) $name) === 0) {
                    unset($new->headerValues[$key]);
                }
            }
            $new->headerValues[(string) $name] = is_array($value) ? array_values($value) : [(string) $value];
            return $new;
        }

        /** ：340 */
        public function withAddedHeader($name, $value): \Psr\Http\Message\MessageInterface
        {
            $new = clone $this;
            $existing = $new->getHeader($name);
            foreach (array_keys($new->headerValues) as $key) {
                if (strcasecmp($key, (string) $name) === 0) {
                    unset($new->headerValues[$key]);
                }
            }
            $new->headerValues[(string) $name] = array_merge(
                $existing,
                is_array($value) ? array_values($value) : [(string) $value]
            );
            return $new;
        }

        /** ：355 */
        public function withoutHeader($name): \Psr\Http\Message\MessageInterface
        {
            $new = clone $this;
            foreach (array_keys($new->headerValues) as $key) {
                if (strcasecmp($key, (string) $name) === 0) {
                    unset($new->headerValues[$key]);
                }
            }
            return $new;
        }

        /** ：365 */
        public function getBody(): \Psr\Http\Message\StreamInterface
        {
            return $this->body ??= new SwooleStream('');
        }

        /** ：381 */
        public function withBody(\Psr\Http\Message\StreamInterface $body): \Psr\Http\Message\MessageInterface
        {
            $new = clone $this;
            $new->body = $body;
            return $new;
        }

        /** ：393 */
        public function getStatusCode(): int
        {
            return $this->status;
        }

        /** ：415 —— $code 真的没有 int 类型（PSR-7 的 withStatus(int ...) 由重写放宽） */
        public function withStatus($code, $reasonPhrase = ''): \Psr\Http\Message\ResponseInterface
        {
            $new = clone $this;
            $new->status = (int) $code;
            $new->reasonPhrase = (string) $reasonPhrase;
            return $new;
        }

        /** ：432 */
        public function getReasonPhrase(): string
        {
            return $this->reasonPhrase;
        }
    }
}

namespace Hyperf\HttpMessage\Stream {
    /**
     * 真包：hyperf/http-message src/Stream/SwooleStream.php:21
     * `class SwooleStream implements StreamInterface, Stringable` —— 它是 PSR-7 的流，
     * 这正是 `Hyperf\HttpServer\Response::withBody(StreamInterface $body)`（src/Response.php:381）
     * 收得下 `new SwooleStream($html)` 的原因。
     *
     * 桩此前是个只带 __toString() 的裸类，而 PSR-7 版的 Response 桩要声明
     * `getBody(): StreamInterface`，两者对不上。直接复用 Psr7.php 里那份 FakeStream
     * （同一条 PSR-7 流语义，不另写第二份）。
     */
    class SwooleStream extends \ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeStream
    {
        public function __construct(string $content = '')
        {
            parent::__construct($content);
        }
    }
}

namespace Hyperf\HttpMessage\Uri {
    /** PSR-7 URI 的最小实现；真实 Hyperf 的 Request::getUri() 返回它。 */
    class Uri
    {
        private string $host;
        private ?int $port;

        public function __construct(string $host = 'localhost', ?int $port = null)
        {
            $this->host = $host;
            $this->port = $port;
        }

        public function getHost(): string
        {
            return $this->host;
        }

        public function getPort(): ?int
        {
            return $this->port;
        }

        public function __toString(): string
        {
            return 'http://' . $this->host . ($this->port ? ':' . $this->port : '') . '/';
        }
    }
}

namespace Hyperf\Redis {
    class Redis
    {
        public array $store = [];

        public function get(string $key): mixed
        {
            return $this->store[$key] ?? null;
        }

        public function set(string $key, mixed $value, int $ttl = 0): mixed
        {
            $this->store[$key] = $value;
            return true;   // phpredis 返回 bool
        }

        public function setex(string $key, int $ttl, mixed $value): bool
        {
            $this->store[$key] = $value;
            return true;
        }

        public function mget(array $keys): array
        {
            $out = [];
            foreach ($keys as $key) {
                $out[] = $this->store[$key] ?? null;
            }
            return $out;
        }

        public function incr(string $key): int
        {
            $this->store[$key] = (int) ($this->store[$key] ?? 0) + 1;
            return $this->store[$key];
        }

        public function decr(string $key): int
        {
            $this->store[$key] = (int) ($this->store[$key] ?? 0) - 1;
            return $this->store[$key];
        }

        public function lPush(string $key, mixed $value): int
        {
            $this->store[$key] ??= [];
            array_unshift($this->store[$key], $value);
            return count($this->store[$key]);
        }

        public function rPop(string $key): mixed
        {
            if (empty($this->store[$key])) {
                return null;
            }
            return array_pop($this->store[$key]);
        }

        public function lRange(string $key, int $start, int $end): array
        {
            $list = $this->store[$key] ?? [];
            if ($end < 0) $end += count($list);   // 负数下标同真实 Redis
            return array_slice($list, $start, $end - $start + 1);
        }

        public function del(mixed ...$keys): int
        {
            $n = 0;
            foreach ($keys as $key) {
                if (isset($this->store[$key])) {
                    unset($this->store[$key]);
                    $n++;
                }
            }
            return $n;
        }
    }
}

namespace Hyperf\Context {
    class Context
    {
        private static array $data = [];

        public static function reset(): void
        {
            self::$data = [];
        }

        public static function set(string $key, mixed $value): void
        {
            self::$data[$key] = $value;
        }

        public static function get(string $key, mixed $default = null): mixed
        {
            return self::$data[$key] ?? $default;
        }
    }

    class Container
    {
        public array $bindings = [];

        public function set(string $id, object $obj): void
        {
            $this->bindings[$id] = $obj;
        }

        public function get(string $id): object
        {
            if (!isset($this->bindings[$id])) {
                throw new \RuntimeException("No binding for $id");
            }
            return $this->bindings[$id];
        }
    }

    class ApplicationContext
    {
        private static ?Container $container = null;

        public static function reset(): void
        {
            self::$container = null;
        }

        public static function getContainer(): Container
        {
            return self::$container ??= new Container();
        }
    }
}

// 注意：不要在这里补 Hyperf\Framework\ApplicationContext。
// 真实 Hyperf 3.x 没有这个类（src/framework/src 下只有 ApplicationFactory/
// Bootstrap/ConfigProvider/Event/Exception/Logger），旧版 src/Core/Xhprof.php
// 正是在探测它才导致 Hyperf 分支永不命中。此前 stub 伪造了该类，
// 使"错误探测"与"正确探测"走同一分支，autoDetect 的修复无法被测试区分。

namespace Psr\Log {
    interface LoggerInterface
    {
        public function error(string $message, array $context = []): void;
    }
}

// 注意：`Psr\Http\Message` 一族已整体移到 tests/Stubs/Framework/Psr7.php
// （真实签名 + 可用 fake，由 tools/contracts 的 L0 case 守着）。此处不得再声明，
// 同名接口重复声明的后果是加载期 fatal。

namespace Psr\Http\Server {
    interface MiddlewareInterface
    {
        public function process(\Psr\Http\Message\ServerRequestInterface $request, \Psr\Http\Server\RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface;
    }

    interface RequestHandlerInterface
    {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface;
    }
}

namespace {
    /**
     * 全局辅助函数（webman/Laravel/ThinkPHP 均在全局命名空间提供同名 helper）。
     */
    function request(): \Webman\Http\Request
    {
        return new \Webman\Http\Request();
    }

    function base_path(): string
    {
        return \ErikWang2013\Xhprof\Tests\Stubs\Registry::$basePath;
    }

    function copy_dir(string $source, string $dest): bool
    {
        \ErikWang2013\Xhprof\Tests\Stubs\Registry::$copied[] = $source . ' => ' . $dest;
        return true;
    }

    function remove_dir(string $path): bool
    {
        \ErikWang2013\Xhprof\Tests\Stubs\Registry::$removed[] = $path;
        return true;
    }

    function config_path(string $path = ''): string
    {
        return \ErikWang2013\Xhprof\Tests\Stubs\Registry::$basePath . '/config/' . ltrim($path, '/');
    }
}

namespace ErikWang2013\Xhprof\Webman {
    function response(string $content = '', int $status = 200): \Webman\Http\Response
    {
        return new \Webman\Http\Response($status, [], $content);
    }
}

namespace ErikWang2013\Xhprof\Webman\Adapter {
    function response(string $content = '', int $status = 200): \Webman\Http\Response
    {
        return new \Webman\Http\Response($status, [], $content);
    }

    function config(?string $key = null): mixed
    {
        if ($key === null) {
            return \ErikWang2013\Xhprof\Tests\Stubs\Registry::$webmanConfig;
        }
        return \ErikWang2013\Xhprof\Tests\Stubs\Registry::resolve(
            \ErikWang2013\Xhprof\Tests\Stubs\Registry::$webmanConfig,
            $key
        );
    }
}

namespace ErikWang2013\Xhprof\Laravel {
    /**
     * 真实 helper `response()`：**无参**返回 ResponseFactory（`file()` 这类工厂方法挂在它上面），
     * 带参返回 `$factory->make($content, $status)`。桩此前两种形态都返回 Response，
     * 于是把 `response()->file()` 与 `response('')->file()` 表达成同一件事。
     */
    function response(string $content = '', int $status = 200): \Illuminate\Http\Response|\Illuminate\Routing\ResponseFactory
    {
        if (func_num_args() === 0) {
            return new \Illuminate\Routing\ResponseFactory();
        }
        return new \Illuminate\Http\Response($content, $status);
    }
}

namespace ErikWang2013\Xhprof\Laravel\Adapter {
    /** 同 ErikWang2013\Xhprof\Laravel\response()：无参 → ResponseFactory，带参 → Response。 */
    function response(string $content = '', int $status = 200): \Illuminate\Http\Response|\Illuminate\Routing\ResponseFactory
    {
        if (func_num_args() === 0) {
            return new \Illuminate\Routing\ResponseFactory();
        }
        return new \Illuminate\Http\Response($content, $status);
    }

    function config(string $key, mixed $default = null): mixed
    {
        return \ErikWang2013\Xhprof\Tests\Stubs\Registry::resolve(
            \ErikWang2013\Xhprof\Tests\Stubs\Registry::$laravelConfig,
            $key
        ) ?? $default;
    }
}

namespace ErikWang2013\Xhprof\Thinkphp {
    function response(string $content = '', int $status = 200): \think\Response
    {
        return new \think\Response($content, $status);
    }
}

namespace ErikWang2013\Xhprof\Thinkphp\Adapter {
    function response(string $content = '', int $status = 200): \think\Response
    {
        return new \think\Response($content, $status);
    }
}
