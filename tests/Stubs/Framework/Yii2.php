<?php

declare(strict_types=1);

/**
 * Yii2 桩（仅单测用）。只覆盖本包适配器与入口类实际触到的 API 面：
 * `yii\base\{BootstrapInterface,Component,Event,Application,ExitException,InvalidConfigException}`、
 * `yii\web\{Application,Request,Response,HeaderCollection,HtmlResponseFormatter}`、
 * `yii\console\{Application,Request}`。
 *
 * 保真性不靠这份文件自证：`tools/contracts/cases/Yii2.php` 用真实的 yiisoft/yii2 跑**同一批
 * 语义**（生命周期、格式化器覆盖头、转发头过滤、`end()` 的 ExitException），桩若与真包不一致，
 * 验证环会红。行号引用的是 2.0.55。
 *
 * 桩里**故意**建模到位的四处（缺任何一处，对应用例就是空转）：
 *  1. `Application::run()/end()` 的状态机与「AFTER_REQUEST 在 send 之前触发」（`base/Application.php:375`/`:647`）；
 *  2. `Response::prepare()` 的两个分支 + `defaultFormatters()` 里**没有 raw**（`web/Response.php:1099`/`:1072`）
 *     与 `HtmlResponseFormatter::format()` **无条件** set Content-Type（`web/HtmlResponseFormatter.php:37`）
 *     —— 没有它们，「withBody 必须置 FORMAT_RAW」这条断言测不出任何东西；
 *  3. `HeaderCollection` 大小写不敏感、`get()` 缺省 null、`set()` 内部 `(array) $value`（`web/HeaderCollection.php:76-115`）；
 *  4. `Request::getHeaders()` 按 `secureHeaders`/`trustedHosts` 过滤转发头（`web/Request.php:337`/`:395`），
 *     故 `getUserIP()` 默认落到 REMOTE_ADDR（`web/Request.php:1264`）。
 *
 * 桩里**没有**建模的真实行为（本包不依赖，或由验证环在真包上覆盖）：
 *  - `Yii::createObject`/`Yii::$container` 的依赖注入（真包对非 Configurable 类是「构造后按公有属性
 *    赋值」——那一条由验证环用真容器钉）；
 *  - `Response::sendHeaders()` 与 `HeadersAlreadySentException`（单测进程里 PHPUnit 已经输出过，
 *    真发头会撞 `headers_sent()`；桩只做 `sendContent()` 的 echo 与 `isSent` 置位）；
 *  - 错误处理器、路由、UrlManager、session、cookies；
 *  - `getUserIP()` 的 CIDR 匹配（桩只认精确 IP 与 `*`），真实算法按信任段从右往左走（`web/Request.php:1286`）；
 *  - `getBodyParams()` 的解析器（真包按 `parsers` 配置解析原始 body；桩用「注入的返回值或注入的异常」表达同一格）。
 *
 * 每一个声明都带 `class_exists`/`interface_exists` 守卫：Yii2 是可安装包，验证环里必须先到者赢。
 */

namespace yii\base {

    use yii\web\Response;

    if (!interface_exists(BootstrapInterface::class)) {
        /**
         * `base/BootstrapInterface.php`：`bootstrap($app)`，由 `Application::bootstrap()`
         * 在建应用时调用（数组定义形态见 `Application::bootstrap()` 的注释）。
         */
        interface BootstrapInterface
        {
            public function bootstrap($app);
        }
    }

    if (!class_exists(Event::class)) {
        class Event
        {
            /** @var string */
            public $name;

            /** @var object|null */
            public $sender;

            /** @var mixed */
            public $data;

            /** @var bool */
            public $handled = false;
        }
    }

    if (!class_exists(Component::class)) {
        /**
         * 只保留事件三件套（on/off/trigger/hasEventHandlers）与「构造后赋值」的配置形态。
         *
         * 真包 `Component::trigger()`（`base/Component.php`）会先建 Event、把 sender 设为
         * 触发者，再逐个调用 handler；这里逐条照抄，因为入口类的两处断言（`$event->sender`
         * 是应用、`on()` 的追加顺序）都建在这上面。
         */
        class Component
        {
            /** @var array<string, array<int, array{0: mixed, 1: mixed}>> */
            private array $_events = [];

            /**
             * @param array<string, mixed> $config 按公有属性赋值（真包走 `Yii::configure()`）
             */
            public function __construct(array $config = [])
            {
                foreach ($config as $name => $value) {
                    // 真包 `BaseObject::__set()`：有 `setX()` 就走 setter，否则写公有属性，
                    // 都没有则 UnknownPropertyException。桩照这个顺序（测试要用 setter 注入
                    // request/response，而它们是私有属性 + setter）。
                    $setter = 'set' . $name;
                    if (method_exists($this, $setter)) {
                        $this->$setter($value);
                        continue;
                    }
                    // 真包对未知属性抛 UnknownPropertyException；桩跟着抛，免得静默吃掉拼错的键
                    if (!property_exists($this, (string) $name)) {
                        throw new UnknownPropertyException('Setting unknown property: ' . static::class . '::' . $name);
                    }
                    $this->$name = $value;
                }

                $this->init();
            }

            public function init(): void
            {
            }

            public function on($name, $handler, $data = null, $append = true): void
            {
                if ($append || empty($this->_events[$name])) {
                    $this->_events[$name][] = [$handler, $data];
                } else {
                    array_unshift($this->_events[$name], [$handler, $data]);
                }
            }

            public function off($name, $handler = null): void
            {
                if (empty($this->_events[$name])) {
                    return;
                }
                if ($handler === null) {
                    unset($this->_events[$name]);

                    return;
                }
                foreach ($this->_events[$name] as $i => $event) {
                    if ($event[0] === $handler) {
                        unset($this->_events[$name][$i]);
                    }
                }
                $this->_events[$name] = array_values($this->_events[$name]);
            }

            public function hasEventHandlers($name): bool
            {
                return !empty($this->_events[$name]);
            }

            public function trigger($name, ?Event $event = null): void
            {
                if (empty($this->_events[$name])) {
                    return;
                }
                if ($event === null) {
                    $event = new Event();
                }
                if ($event->sender === null) {
                    $event->sender = $this;
                }
                $event->handled = false;
                $event->name = $name;
                foreach ($this->_events[$name] as $handler) {
                    $event->data = $handler[1];
                    call_user_func($handler[0], $event);
                    if ($event->handled) {
                        return;
                    }
                }
            }
        }
    }

    if (!class_exists(UnknownPropertyException::class)) {
        class UnknownPropertyException extends \RuntimeException
        {
        }
    }

    if (!class_exists(InvalidConfigException::class)) {
        class InvalidConfigException extends \RuntimeException
        {
        }
    }

    if (!class_exists(InvalidParamException::class)) {
        /**
         * 注意真实层级：`yii\base\InvalidParamException extends \BadMethodCallException`
         * （`base/InvalidParamException.php:17`），而 `InvalidArgumentException extends InvalidParamException`
         * —— 它**不是** PHP 内置的 `\InvalidArgumentException`。这个差别是验证环在真包上抓出来的：
         * 桩若直接抛内置的那个，单测里 `expectException(\InvalidArgumentException::class)`
         * 会通过，而真实运行抛的类对不上。
         */
        class InvalidParamException extends \BadMethodCallException
        {
        }
    }

    if (!class_exists(InvalidArgumentException::class)) {
        class InvalidArgumentException extends InvalidParamException
        {
        }
    }

    if (!class_exists(ExitException::class)) {
        /**
         * `base/ExitException.php`：真实用法是 `end()` 在测试环境里抛出它代替 `exit()`。
         */
        class ExitException extends \Exception
        {
            /** @var int */
            public $statusCode;

            public function __construct($status = 0, $message = null, $code = 0, ?\Throwable $previous = null)
            {
                $this->statusCode = (int) $status;
                parent::__construct((string) $message, (int) $code, $previous);
            }
        }
    }

    if (!class_exists(Application::class)) {
        /**
         * 只保留生命周期与两个组件 getter；`bootstrap()` 的数组定义展开也保留，
         * 因为入口类的注册形状就建在它上面。
         */
        class Application extends Component
        {
            public const EVENT_BEFORE_REQUEST = 'beforeRequest';
            public const EVENT_AFTER_REQUEST = 'afterRequest';

            public const STATE_BEGIN = 0;
            public const STATE_INIT = 1;
            public const STATE_BEFORE_REQUEST = 2;
            public const STATE_HANDLING_REQUEST = 3;
            public const STATE_AFTER_REQUEST = 4;
            public const STATE_SENDING_RESPONSE = 5;
            public const STATE_END = 6;

            /** @var int|null */
            public $state;

            /** @var bool 观测点：业务处理有没有真的被走到（短路用例靠它） */
            public bool $handleRequestCalled = false;

            /** @var array<int, mixed> */
            public array $bootstrap = [];

            /** @var Response|null 真包是组件属性（`Yii::$app->response`），桩用公有属性 + setter */
            public ?Response $response = null;

            /** @var object|null 真包是组件属性（`Yii::$app->request`） */
            public $request = null;

            /**
             * @param array<string, mixed> $config
             */
            public function __construct(array $config = [])
            {
                // 真包：preInit() 里先落 id/basePath，再 init() → bootstrap()。
                // 桩只展开 bootstrap 数组（这是入口类唯一依赖的那一段）。
                $bootstrap = [];
                if (isset($config['bootstrap']) && is_array($config['bootstrap'])) {
                    $bootstrap = $config['bootstrap'];
                    unset($config['bootstrap']);
                }
                parent::__construct($config);

                foreach ($bootstrap as $definition) {
                    // 真包是 `Yii::createObject($class)`（`base/Application::bootstrap()`），
                    // 而容器对**不**实现 `Configurable` 的类是「构造之后把其余键逐个赋给公有属性」
                    // （`di/Container.php:389` 的 `foreach ($config as $name => $value) $object->$name = $value;`）。
                    // 桩照这一步做——包括「键走属性、不走构造参数」这个关键语义。
                    // 真容器本身由验证环在真包上跑。
                    if (is_string($definition)) {
                        $component = new $definition();
                    } else {
                        $class = $definition['class'];
                        unset($definition['class']);
                        $component = new $class();
                        foreach ($definition as $name => $value) {
                            if (!property_exists($component, (string) $name)) {
                                throw new UnknownPropertyException('Setting unknown property: ' . $class . '::' . $name);
                            }
                            $component->$name = $value;
                        }
                    }
                    if ($component instanceof BootstrapInterface) {
                        $component->bootstrap($this);
                    }
                }
            }

            public function getRequest()
            {
                return $this->request;
            }

            public function setRequest($request): void
            {
                $this->request = $request;
            }

            public function getResponse(): Response
            {
                if ($this->response === null) {
                    $this->response = new Response();
                }

                return $this->response;
            }

            public function setResponse(Response $response): void
            {
                $this->response = $response;
            }

            /**
             * `base/Application.php:375`（逐行同序）。
             */
            public function run()
            {
                try {
                    $this->state = self::STATE_BEFORE_REQUEST;
                    $this->trigger(self::EVENT_BEFORE_REQUEST);

                    $this->state = self::STATE_HANDLING_REQUEST;
                    $response = $this->handleRequest($this->getRequest());

                    $this->state = self::STATE_AFTER_REQUEST;
                    $this->trigger(self::EVENT_AFTER_REQUEST);

                    $this->state = self::STATE_SENDING_RESPONSE;
                    $response->send();

                    $this->state = self::STATE_END;

                    return $response->exitStatus;
                } catch (ExitException $e) {
                    $this->end($e->statusCode, isset($response) ? $response : null);
                    return $e->statusCode;
                }
            }

            /**
             * `base/Application.php:647`。真包最后是
             * `if (YII_ENV_TEST) { throw new ExitException($status); } exit($status);`
             * —— 桩**一律**抛 ExitException：单测进程不能被 `exit()` 杀掉。
             * 「测试环境抛、生产环境 exit」这个分叉由验证环在真包上钉。
             */
            public function end($status = 0, $response = null)
            {
                if ($this->state === self::STATE_BEFORE_REQUEST || $this->state === self::STATE_HANDLING_REQUEST) {
                    $this->state = self::STATE_AFTER_REQUEST;
                    $this->trigger(self::EVENT_AFTER_REQUEST);
                }

                if ($this->state !== self::STATE_SENDING_RESPONSE && $this->state !== self::STATE_END) {
                    $this->state = self::STATE_END;
                    $response = $response ?: $this->getResponse();
                    $response->send();
                }

                throw new ExitException($status);
            }

            /**
             * @param object|null $request
             */
            public function handleRequest($request)
            {
                $this->handleRequestCalled = true;

                return $this->getResponse();
            }
        }
    }
}

namespace yii\web {

    use yii\base\Component;
    use yii\base\InvalidArgumentException;
    use yii\base\InvalidConfigException;

    if (!class_exists(HeaderCollection::class)) {
        /**
         * 大小写不敏感（键统一小写）、`get()` 取第一个值且缺省返回 `$default`（null）、
         * `set()` 内部 `(array) $value` —— 三条都照 `web/HeaderCollection.php:76-115`。
         */
        class HeaderCollection extends Component
        {
            /** @var array<string, array<int, string>> */
            private array $_headers = [];

            /** @var array<string, string> */
            private array $_originalHeaderNames = [];

            public function get($name, $default = null, $first = true)
            {
                $normalizedName = strtolower((string) $name);
                if (isset($this->_headers[$normalizedName])) {
                    return $first ? reset($this->_headers[$normalizedName]) : $this->_headers[$normalizedName];
                }

                return $default;
            }

            public function set($name, $value = ''): self
            {
                $normalizedName = strtolower((string) $name);
                $this->_headers[$normalizedName] = (array) $value;
                $this->_originalHeaderNames[$normalizedName] = (string) $name;

                return $this;
            }

            public function has($name): bool
            {
                return isset($this->_headers[strtolower((string) $name)]);
            }

            public function remove($name): void
            {
                $normalizedName = strtolower((string) $name);
                unset($this->_headers[$normalizedName], $this->_originalHeaderNames[$normalizedName]);
            }

            public function removeAll(): void
            {
                $this->_headers = [];
                $this->_originalHeaderNames = [];
            }

            public function getCount(): int
            {
                return count($this->_headers);
            }

            /**
             * @return array<string, array<int, string>>
             */
            public function toArray(): array
            {
                return $this->_headers;
            }

            /**
             * @return array<string, string>
             */
            public function toOriginalArray(): array
            {
                return $this->_originalHeaderNames;
            }
        }
    }

    if (!interface_exists(ResponseFormatterInterface::class)) {
        interface ResponseFormatterInterface
        {
            public function format($response);
        }
    }

    if (!class_exists(HtmlResponseFormatter::class)) {
        /**
         * `web/HtmlResponseFormatter.php:32-41`：**无条件**设置 Content-Type —— 这正是
         * `ResponseAdapter::withBody()` 必须置 `FORMAT_RAW` 的原因（否则静态资源的
         * `text/css` 会被覆盖成 `text/html; charset=...`）。
         */
        class HtmlResponseFormatter extends Component implements ResponseFormatterInterface
        {
            /** @var string */
            public $contentType = 'text/html';

            public function format($response)
            {
                if (stripos($this->contentType, 'charset') === false) {
                    $this->contentType .= '; charset=' . $response->charset;
                }
                $response->getHeaders()->set('Content-Type', $this->contentType);
                if ($response->data !== null) {
                    $response->content = $response->data;
                }
            }
        }
    }

    if (!class_exists(Response::class)) {
        /**
         * `prepare()` / `defaultFormatters()` / `send()` 逐行照 `web/Response.php`；
         * 刻意不实现 `sendHeaders()`（真发头会在 PHPUnit 输出之后撞 `headers_sent()`），
         * 只保留 `sendContent()` 的 echo 与 `isSent` 置位。
         */
        class Response extends Component
        {
            public const FORMAT_RAW = 'raw';
            public const FORMAT_HTML = 'html';
            public const FORMAT_JSON = 'json';
            public const FORMAT_JSONP = 'jsonp';
            public const FORMAT_XML = 'xml';

            /** @var string */
            public $format = self::FORMAT_HTML;

            /** @var mixed */
            public $data;

            /** @var mixed */
            public $content;

            /** @var mixed */
            public $stream;

            /** @var string|null */
            public $charset = 'UTF-8';

            /** @var string */
            public $statusText = 'OK';

            /** @var string */
            public $version = '1.1';

            /** @var bool */
            public $isSent = false;

            /** @var int */
            public $exitStatus = 0;

            /** @var array<string, mixed> */
            public $formatters = [];

            private ?HeaderCollection $_headers = null;

            private int $_statusCode = 200;

            public function init(): void
            {
                $this->formatters = array_merge($this->defaultFormatters(), $this->formatters);
            }

            public function getHeaders(): HeaderCollection
            {
                if ($this->_headers === null) {
                    $this->_headers = new HeaderCollection();
                }

                return $this->_headers;
            }

            public function getStatusCode(): int
            {
                return $this->_statusCode;
            }

            public function setStatusCode($value, $text = null): void
            {
                $this->_statusCode = (int) $value;
                if ($text === null) {
                    $this->statusText = $this->httpStatusText($this->_statusCode);
                } else {
                    $this->statusText = (string) $text;
                }
            }

            /**
             * @return array<string, mixed>
             */
            protected function defaultFormatters(): array
            {
                // 与真包同一条：**没有** raw（`web/Response.php:1072`）。加进去就等于把
                // 「FORMAT_RAW 不经过格式化器」这个前提改掉，对应用例会立刻失去判别力。
                return [
                    self::FORMAT_HTML => ['class' => HtmlResponseFormatter::class],
                    self::FORMAT_XML => ['class' => HtmlResponseFormatter::class],
                    self::FORMAT_JSON => ['class' => HtmlResponseFormatter::class],
                    self::FORMAT_JSONP => ['class' => HtmlResponseFormatter::class, 'useJsonp' => true],
                ];
            }

            protected function prepare(): void
            {
                if (in_array($this->getStatusCode(), [204, 304], true)) {
                    $this->content = '';
                    $this->stream = null;

                    return;
                }

                if ($this->stream !== null) {
                    return;
                }

                if (isset($this->formatters[$this->format])) {
                    $formatter = $this->formatters[$this->format];
                    if (!is_object($formatter)) {
                        $class = $formatter['class'];
                        unset($formatter['class']);
                        $this->formatters[$this->format] = $formatter = new $class($formatter);
                    }
                    if ($formatter instanceof ResponseFormatterInterface) {
                        $formatter->format($this);
                    } else {
                        throw new InvalidConfigException("The '{$this->format}' response formatter is invalid. It must implement the ResponseFormatterInterface.");
                    }
                } elseif ($this->format === self::FORMAT_RAW) {
                    if ($this->data !== null) {
                        $this->content = $this->data;
                    }
                } else {
                    throw new InvalidConfigException("Unsupported response format: {$this->format}");
                }

                if (is_array($this->content)) {
                    throw new InvalidArgumentException('Response content must not be an array.');
                }
                if (is_object($this->content)) {
                    if (method_exists($this->content, '__toString')) {
                        $this->content = $this->content->__toString();
                    } else {
                        throw new InvalidArgumentException('Response content must be a string or an object implementing __toString().');
                    }
                }
            }

            public function send(): void
            {
                if ($this->isSent) {
                    return;
                }
                $this->prepare();
                $this->sendContent();
                $this->isSent = true;
            }

            protected function sendContent(): void
            {
                echo $this->content;
            }

            public function clear(): void
            {
                $this->_headers = null;
                $this->_statusCode = 200;
                $this->statusText = 'OK';
                $this->data = null;
                $this->stream = null;
                $this->content = null;
                $this->isSent = false;
            }

            private function httpStatusText(int $code): string
            {
                $map = [200 => 'OK', 400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 500 => 'Internal Server Error'];

                return $map[$code] ?? '';
            }
        }
    }

    if (!class_exists(Request::class)) {
        /**
         * 只覆盖适配器触到的面。真包里数据来自超全局与 `$_SERVER`；桩改为构造时注入
         * （同一个「请求输入」的两种来源），过滤与取值语义照抄。
         */
        class Request extends Component
        {
            /** @var string */
            public $method = 'GET';

            /**
             * 路径+query。真包由 `resolveRequestUri()`（`web/Request.php:1098`）从
             * `X-Rewrite-Url` / `$_SERVER['REQUEST_URI']` / `ORIG_PATH_INFO` 推；
             * 三者都没有时**抛 InvalidConfigException** —— 桩用 null 表达这一格。
             *
             * @var string|null
             */
            public $url = null;

            /** @var string|null scheme://host[:port]，真包由 Host 头与 $_SERVER 推 */
            public $hostInfo = null;

            /** @var array<string, mixed> */
            public array $queryParams = [];

            /** @var mixed 真包惰性解析原始 body；桩直接给结果 */
            public $bodyParams = [];

            /** @var \Throwable|null 非 null 时 getBodyParams() 抛它（畸形 JSON / 自定义 parser 那一格） */
            public ?\Throwable $bodyParamsThrows = null;

            /** @var array<string, string> 传入的头（含转发头） */
            public array $headers = [];

            /** @var array<int, string> 真包默认值（`web/Request.php:248`） */
            public array $secureHeaders = [
                'X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port',
                'Front-End-Https', 'X-Rewrite-Url', 'X-Original-Host',
            ];

            /** @var array<int, string> 空 = 一个转发头都不信任（真包默认，安全默认） */
            public array $trustedHosts = [];

            /** @var array<int, string> */
            public array $ipHeaders = ['X-Forwarded-For'];

            /** @var string|null 真包读 $_SERVER['REMOTE_ADDR'] */
            public $remoteAddr = null;

            private ?HeaderCollection $_headers = null;

            public function getMethod(): string
            {
                return (string) $this->method;
            }

            public function getUrl(): string
            {
                if ($this->url === null) {
                    throw new InvalidConfigException('Unable to determine the request URI.');
                }

                return (string) $this->url;
            }

            public function getAbsoluteUrl(): string
            {
                return (string) $this->hostInfo . $this->getUrl();
            }

            /**
             * `web/Request.php:833`：`parse_url(hostInfo, PHP_URL_HOST)`，hostInfo 为 null 时返回 null。
             */
            public function getHostName(): ?string
            {
                if ($this->hostInfo === null) {
                    return null;
                }
                $host = parse_url((string) $this->hostInfo, PHP_URL_HOST);

                return is_string($host) ? $host : null;
            }

            public function getHeaders(): HeaderCollection
            {
                if ($this->_headers === null) {
                    $collection = new HeaderCollection();
                    foreach ($this->headers as $name => $value) {
                        $collection->set($name, $value);
                    }
                    $this->filterHeaders($collection);
                    $this->_headers = $collection;
                }

                return $this->_headers;
            }

            public function getQueryParams(): array
            {
                return $this->queryParams;
            }

            public function getBodyParams()
            {
                if ($this->bodyParamsThrows !== null) {
                    throw $this->bodyParamsThrows;
                }

                return $this->bodyParams;
            }

            /**
             * `web/Request.php:1264` → 取转发头（受 trustedHosts 约束），拿不到退 REMOTE_ADDR。
             */
            public function getUserIP(): ?string
            {
                $ip = $this->getUserIpFromIpHeaders();
                if ($ip !== null) {
                    return $ip;
                }

                return $this->getRemoteIP();
            }

            public function getRemoteIP(): ?string
            {
                return $this->remoteAddr === null ? null : (string) $this->remoteAddr;
            }

            /**
             * `web/Request.php:337`：不在 `getTrustedHeaders()` 里的 `secureHeaders` 一律**删掉**。
             * 桩的信任判定简化为「精确 IP 或 `*`」（真包是 CIDR，由验证环在真包上跑）。
             */
            protected function filterHeaders(HeaderCollection $headerCollection): void
            {
                $trustedHeaders = $this->getTrustedHeaders();
                foreach ($this->secureHeaders as $secureHeader) {
                    if (!in_array($secureHeader, $trustedHeaders, true)) {
                        $headerCollection->remove($secureHeader);
                    }
                }
            }

            /**
             * @return array<int, string>
             */
            protected function getTrustedHeaders(): array
            {
                if (empty($this->trustedHosts)) {
                    return [];
                }
                foreach ($this->trustedHosts as $cidr) {
                    if ($cidr === '*' || $cidr === $this->remoteAddr) {
                        return $this->secureHeaders;
                    }
                }

                return [];
            }

            /**
             * 真算法（`web/Request.php:1286`）从右往左走、取第一个不可信地址；桩保留「右起、
             * 信任段内跳过」的形状，只是信任判定简化为精确匹配。
             */
            private function getUserIpFromIpHeaders(): ?string
            {
                if ($this->getTrustedHeaders() === []) {
                    return null;
                }

                foreach ($this->ipHeaders as $ipHeader) {
                    $collection = $this->getHeaders();
                    if (!$collection->has($ipHeader)) {
                        continue;
                    }
                    $ips = preg_split('/\s*,\s*/', trim((string) $collection->get($ipHeader)), -1, PREG_SPLIT_NO_EMPTY);
                    if (!is_array($ips) || $ips === []) {
                        continue;
                    }
                    $resultIp = null;
                    foreach (array_reverse($ips) as $ip) {
                        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                            break;
                        }
                        $resultIp = $ip;
                        if (!in_array($ip, $this->trustedHosts, true)) {
                            break;
                        }
                    }
                    if ($resultIp !== null) {
                        return $resultIp;
                    }
                }

                return null;
            }
        }
    }

    if (!class_exists(Application::class)) {
        /**
         * Web 应用：真包里 `bootstrap()` 会取 `getRequest()->getScriptFile()` 设别名
         * （`web/Application.php:63`），`handleRequest()` 做路由解析——两者本包都不依赖，
         * 桩只保留「handleRequest 被调用过」这一观测点（短路用例靠它证明请求没进业务）。
         */
        class Application extends \yii\base\Application
        {
        }
    }
}

namespace yii\console {

    use yii\base\Application as BaseApplication;

    if (!class_exists(Request::class)) {
        /**
         * 控制台请求：**故意没有 `getUrl()`**（真包也没有）。入口类若漏了
         * `instanceof \yii\web\Application` 守卫，`new RequestAdapter($consoleRequest)`
         * 这里就会 TypeError —— 那正是守卫用例的判别力来源。
         */
        class Request
        {
            public function resolve(): array
            {
                return ['help', []];
            }
        }
    }

    if (!class_exists(Application::class)) {
        /**
         * **不覆写 `run()`**（真包 `console/Application.php` 也没有覆写）：CLI 进程照样
         * 触发 `EVENT_BEFORE_REQUEST`（`base/Application.php:375`），这就是入口类必须
         * 判 Web 应用的原因。
         */
        class Application extends BaseApplication
        {
        }
    }
}
