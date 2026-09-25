<?php

declare(strict_types=1);

/**
 * Joomla 桩（仅单测用）。只覆盖本包 Joomla 适配器与入口类实际触到的 API 面。
 *
 * 分两半，性质完全不同，别混着看：
 *
 *  A. **可安装侧**（`Joomla\Event\*`、`Joomla\Input\Input`、`Joomla\Registry\Registry`、
 *     `Joomla\Uri\UriHelper`）：这里的声明都带 `class_exists`/`interface_exists` 守卫，
 *     因为验证环会把**真实包与本桩加载进同一个进程**（tools/contracts/cases/Joomla.php §5）——
 *     先到者赢，环里必须真实包赢。桩的这一半由该 case 的 L0 逐字段对比（签名/常量/参数默认值）
 *     钉住：桩若与真实包不一致，环会红。
 *
 *  B. **CMS 侧**（`Joomla\CMS\*`）：没有任何 composer 包提供这些类（`Joomla\CMS\*` 只存在于
 *     CMS 仓库），所以这一半是**我们唯一一份声明**，没有真实对照物 —— 桩的忠实性无法在本环
 *     自证，对应 Joomla 卡的 SKIP 1/2/4。这里每处都注明证据来源，改之前先去看那个来源。
 *
 * 桩里**没有**建模的真实行为（本包都不依赖，故不影响结论；真需要时先加断言再说）：
 *  - `Input` 的子输入只做超全局回退，不建模 `Joomla\Input\Cookie/Files/Json`（需要文件系统/JSON body）；
 *  - `Input::__call()` 的 getXxx() 魔法、`set()`/`def()`/`count()`：本包一律显式 `get($k, $d, 'raw')`；
 *  - `InputFilter::cleanString()` 的 XSS 白名单（真实实现有几百行标签/属性表，这里只去标签）；
 *  - `Log` 的 $date 参数、监听器与落盘（只记 $entries 供断言）；
 *  - `CMSPlugin` 的 dispatcher 只存不注册（真实 5.x 里是 PluginHelper 自己调 registerListeners()，
 *    4.4 里也没有自动注册）；
 *  - `CMSApplication::close($code)` 在真实 CMS 里是 exit($code)，桩里只记调用 —— 环里同理。
 */

namespace Joomla\Event {

    if (!interface_exists(SubscriberInterface::class)) {
        /**
         * 真实声明（joomla/event 3.0.2 SubscriberInterface.php）：
         * `public static function getSubscribedEvents(): array;` —— 有返回类型，且是**静态**的。
         * `Dispatcher::addSubscriber()` 拿本方法返回数组的**键**当事件名注册。
         */
        interface SubscriberInterface
        {
            public static function getSubscribedEvents(): array;
        }
    }

    if (!interface_exists(EventInterface::class)) {
        /**
         * 4 个方法的签名与 joomla/event 3.0.2 一致（环里逐字段比对过）。
         * 本包只在事件回调签名 `?EventInterface $event = null` 里用到这个类型，不调用其方法。
         */
        interface EventInterface
        {
            public function getArgument($name, $default = null);

            public function getName();

            public function isStopped();

            public function stopPropagation(): void;
        }
    }

    if (!class_exists(Priority::class)) {
        /**
         * 常量值抄自 joomla/event 3.0.2 Priority.php（全表，非只抄用到的那两个）：
         * 环里 `JOOMLA_DUMP_SPEC` 对 Priority 只列常量不列方法，逐个比对值。
         */
        class Priority
        {
            public const MIN = -3;
            public const LOW = -2;
            public const BELOW_NORMAL = -1;
            public const NORMAL = 0;
            public const ABOVE_NORMAL = 1;
            public const HIGH = 2;
            public const MAX = 3;
        }
    }

    if (!interface_exists(DispatcherInterface::class)) {
        /**
         * 9 个方法抄自 joomla/event 3.0.2 DispatcherInterface.php（含参数默认值与返回类型）。
         * 本包只用 `addSubscriber()`（在验证环里由真实 Dispatcher 执行），其余方法列在这里是为了
         * 让接口面完整 —— 桩若缺一个方法，任何 `implements` 它的类都会在加载期 fatal。
         */
        interface DispatcherInterface
        {
            public function dispatch(string $name, ?EventInterface $event = null): EventInterface;

            public function addListener(string $eventName, callable $callback, int $priority = 0): bool;

            public function clearListeners($event = null);

            public function countListeners($event);

            public function getListeners(?string $event = null);

            public function hasListener(callable $callback, ?string $eventName = null);

            public function removeListener(string $eventName, callable $listener): void;

            public function addSubscriber(SubscriberInterface $subscriber): void;

            public function removeSubscriber(SubscriberInterface $subscriber): void;
        }
    }
}

namespace Joomla\Input {

    if (!class_exists(Input::class)) {
        /**
         * 忠实移植 joomla/input 3.0.2 的构造 / __get() / get() / getArray() / exists() / getMethod()：
         *  - `__construct($source = null, ...)`：$source 为 null 时取 `$_REQUEST`（**不是** $_GET）；
         *  - `__get('server')`：先找 `Joomla\Input\Server` 类，找不到就回退到超全局 `$_SERVER`，
         *    结果**缓存**在 $inputs 里（真实实现还会 trigger_error，桩里省略）；
         *  - `exists()` 用 `isset($this->data[$name])`：null 值视为不存在，且键是**字面量**（不做点号展开）；
         *  - `get()` 命中才过滤，否则给默认值；
         *  - `getArray()` 无参时把 `$this->data` 的**值当成过滤器名**逐个 clean（真实行为：
         *    '<b>a b</b>' 这个过滤器名不认识 → 落到 cleanString → 'a b'），所以适配器 all()
         *    只拿它的**键**、值另用 'raw' 重读；
         *  - `getMethod()` = `strtoupper($this->server->getCmd('REQUEST_METHOD'))`：server 里没有
         *    REQUEST_METHOD 时真实实现拿到 null → strtoupper(null)，结果不可用，
         *    故 RequestAdapter::method() 先用 exists() 挡一道。
         */
        class Input
        {
            /** 真实实现：private const ALLOWED_GLOBALS = ['REQUEST','GET','POST','FILES','SERVER','ENV']; */
            private const ALLOWED_GLOBALS = ['REQUEST', 'GET', 'POST', 'FILES', 'SERVER', 'ENV'];

            protected array $options = [];

            protected array $data = [];

            /** @var array<string, Input> */
            protected array $inputs = [];

            public function __construct($source = null, array $options = [])
            {
                $this->data = $source ?? $_REQUEST;
                $this->options = $options;
            }

            public function __get($name)
            {
                if (isset($this->inputs[$name])) {
                    return $this->inputs[$name];
                }

                // 真实实现先试 `Joomla\Input\<Ucfirst($name)>` 类（Cookie/Files/Json），
                // 桩里没有那几个类，故只做超全局回退。
                $superGlobal = '_' . strtoupper($name);

                if (\in_array(strtoupper($name), self::ALLOWED_GLOBALS, true) && isset($GLOBALS[$superGlobal])) {
                    return $this->inputs[$name] = new self($GLOBALS[$superGlobal], $this->options);
                }

                return null;
            }

            public function get($name, $default = null, $filter = 'cmd')
            {
                return $this->exists($name) ? $this->clean($this->data[$name], $filter) : $default;
            }

            public function getArray(array $vars = [], $datasource = null)
            {
                if ($vars === [] && $datasource === null) {
                    $vars = $this->data;
                }

                $results = [];
                foreach ($vars as $k => $v) {
                    if (\is_array($v)) {
                        $results[$k] = $datasource === null
                            ? $this->getArray($v, $this->get($k, null, 'array'))
                            : $this->getArray($v, $datasource[$k] ?? null);
                    } else {
                        $results[$k] = $datasource === null
                            ? $this->get($k, null, $v)
                            : (isset($datasource[$k]) ? $this->clean($datasource[$k], $v) : null);
                    }
                }

                return $results;
            }

            public function exists($name)
            {
                return isset($this->data[$name]);
            }

            public function getMethod()
            {
                $server = $this->server;

                return $server === null ? '' : strtoupper((string) $server->get('REQUEST_METHOD', '', 'cmd'));
            }

            /**
             * 真实过滤在 `Joomla\Filter\InputFilter::clean()` 里，这里只实现单测触到的分支：
             *  - 'raw'   原样；'array' 转数组；
             *  - 数组值递归（真实实现如此）；
             *  - 'cmd'   只留 [A-Za-z0-9_.-] 再去掉前导点（抄自 cleanCmd()）；
             *  - 其余（含未知名）落到 cleanString()：真实是几百行的 XSS 白名单，桩里只去标签。
             */
            protected function clean(mixed $value, string $filter): mixed
            {
                $type = ucfirst(strtolower((string) $filter));

                if ($type === 'Array') {
                    return (array) $value;
                }
                if ($type === 'Raw') {
                    return $value;
                }
                if (\is_array($value)) {
                    $out = [];
                    foreach ($value as $k => $v) {
                        $out[$k] = $this->clean($v, $type);
                    }

                    return $out;
                }
                if (!\is_string($value)) {
                    return $value;
                }
                if ($type === 'Cmd') {
                    return ltrim((string) preg_replace('/[^A-Z0-9_\.-]/i', '', $value), '.');
                }

                return strip_tags($value);
            }
        }
    }
}

namespace Joomla\Registry {

    if (!class_exists(Registry::class)) {
        /**
         * 忠实移植 joomla/registry 3.0 的构造与 get()：
         *  - 关联数组/对象节点转成 stdClass（**列表保持数组**，判据是 ArrayHelper::isAssociative
         *    的「键 !== 下标」规则），故 get('xhprof') 返回 stdClass 而 get('xhprof.assets_url')
         *    返回叶子；
         *  - 叶子为 null 或 '' 时返回 $default（false / 0 / [] 原样返回）；
         *  - 路径不含分隔符时直接取顶层属性。
         *
         * 桩只声明 __construct 与 get()：真实类还 implements ArrayAccess/Countable/IteratorAggregate/
         * JsonSerializable/Stringable（比桩宽），本包一个都没用到 —— 方向安全（单测比真实更严），
         * 验证环会把这条差异打印出来备查。
         */
        class Registry
        {
            protected \stdClass $data;

            protected string $separator = '.';

            public function __construct($data = null, string $separator = '.')
            {
                $this->separator = $separator;
                $this->data = new \stdClass();

                if (\is_array($data) || \is_object($data)) {
                    $this->bindData($this->data, $data);
                }
            }

            public function get($path, $default = null)
            {
                if (empty($path)) {
                    return $default;
                }

                if ($this->separator === '' || !strpos($path, $this->separator)) {
                    return (isset($this->data->$path) && $this->data->$path !== null && $this->data->$path !== '')
                        ? $this->data->$path
                        : $default;
                }

                $node = $this->data;
                $found = false;

                foreach (explode($this->separator, trim((string) $path)) as $n) {
                    if (\is_array($node) && isset($node[$n])) {
                        $node = $node[$n];
                        $found = true;

                        continue;
                    }

                    if (!\is_object($node) || !isset($node->$n)) {
                        return $default;
                    }

                    $node = $node->$n;
                    $found = true;
                }

                if (!$found || $node === null || $node === '') {
                    return $default;
                }

                return $node;
            }

            protected function bindData($parent, $data): void
            {
                $data = \is_object($data) ? get_object_vars($data) : (array) $data;

                foreach ($data as $k => $v) {
                    if ((\is_array($v) && self::isAssociative($v)) || \is_object($v)) {
                        if (!isset($parent->$k)) {
                            $parent->$k = new \stdClass();
                        }

                        $this->bindData($parent->$k, $v);

                        continue;
                    }

                    $parent->$k = $v;
                }
            }

            /** 与 Joomla\Utilities\ArrayHelper::isAssociative() 同规则：任意「键 !== 下标」即为关联数组。 */
            private static function isAssociative($array): bool
            {
                if (\is_array($array)) {
                    foreach (array_keys($array) as $k => $v) {
                        if ($k !== $v) {
                            return true;
                        }
                    }
                }

                return false;
            }
        }
    }
}

namespace Joomla\Uri {

    if (!class_exists(UriHelper::class)) {
        /**
         * 移植 joomla/uri 3.0 的 UriHelper::parse_url()：UTF-8 安全的 parse_url，
         * **失败返回 false 而不抛**（这正是 RequestAdapter 不用 `new Uri()` 的原因）。
         * mbstring 缺失时走 strtr(urlencode()) 分支——本机与 CI 都有 mbstring，
         * 非 UTF-8 输入等价于 parse_url。
         *
         * 真实实现把保留字符表写成函数内局部变量（$reservedUriCharactersMap），这里提成了
         * private const；环里的常量对比只看**公开**常量，故这条差异不算不一致（调用方观测不到）。
         */
        class UriHelper
        {
            private const RESERVED = [
                '%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')',
                '%3B' => ';', '%3A' => ':', '%40' => '@', '%26' => '&', '%3D' => '=',
                '%24' => '$', '%2C' => ',', '%2F' => '/', '%3F' => '?', '%23' => '#',
                '%5B' => '[', '%5D' => ']',
            ];

            public static function parse_url($url, $component = -1)
            {
                if (extension_loaded('mbstring') && mb_convert_encoding($url, 'ISO-8859-1', 'UTF-8') === $url) {
                    return parse_url($url, $component);
                }

                $parts = parse_url(strtr(urlencode($url), self::RESERVED), $component);

                return $parts ? array_map('urldecode', $parts) : $parts;
            }
        }
    }
}

namespace Joomla\CMS\Application {

    /**
     * 复合接口：真实 CMS 里 `getInput()` 来自 CMSApplicationTrait、`setHeader()`/`sendHeaders()` 来自
     * `Joomla\Application\WebApplication`（joomla/application 包）、`close()` 来自 AbstractApplication。
     * 本包只用到这 4 个方法，故在桩里合成一个接口，让入口类与假应用都能被类型检查覆盖。
     *
     * 环里没有这份声明之外的对照物（CMS 不可安装），故方法的真实签名只能靠源码阅读（SKIP 1）。
     */
    interface CMSApplicationInterface
    {
        public function getInput(): \Joomla\Input\Input;

        public function setHeader(string $name, string $value, bool $replace = false): void;

        public function sendHeaders(): void;

        public function close($code = 0): void;
    }
}

namespace Joomla\CMS\Plugin {

    if (!class_exists(CMSPlugin::class)) {
        /**
         * 构造器签名取 4.4 与 5.x 的**最严交集**：
         *  - 4.4：`__construct(&$subject, $config = [])` —— 第一个参数必填、按引用、且尾部
         *    `setDispatcher($subject)` 带型别约束，所以 dispatcher 在 4.4 上实质必填；
         *  - 5.x：`__construct($config = [])` —— 识别第一个参数是不是 DispatcherInterface。
         * 「必填 + 非 null」两边都成立，故桩这么声明；**按引用**是调用点属性，桩无法建模，
         * 由 services/provider.php 里「先用变量接住容器结果再传」的写法兜住（注释在那里）。
         *
         * getApplication() 是 protected 且返回 **可空**：真实 4.4/5.x 声明是
         * `?CMSApplicationInterface`，没有 Factory 兜底 —— 桩里若写成非空，单测就会放过
         * 「没设应用也敢调」这种在生产上会 fatal 的写法。
         */
        abstract class CMSPlugin
        {
            protected \Joomla\Event\DispatcherInterface $dispatcher;

            protected ?\Joomla\CMS\Application\CMSApplicationInterface $application = null;

            public function __construct(\Joomla\Event\DispatcherInterface $dispatcher, array $config = [])
            {
                // 真实实现把 dispatcher 存起来（4.4 还会 setDispatcher()）；桩只存不用：
                // 单测里传的是 JoomlaNoopDispatcher，任何真分发都会抛 LogicException。
                $this->dispatcher = $dispatcher;
            }

            public function setApplication(\Joomla\CMS\Application\CMSApplicationInterface $app): void
            {
                $this->application = $app;
            }

            protected function getApplication(): ?\Joomla\CMS\Application\CMSApplicationInterface
            {
                return $this->application;
            }
        }
    }
}

namespace Joomla\CMS\Log {

    if (!class_exists(Log::class)) {
        /**
         * Log 是**位掩码**（真实 Joomla\CMS\Log\Log 与 PSR-3 的 0..7 完全不是一回事：
         * EMERGENCY=1、ALERT=2、CRITICAL=4、ERROR=8、WARNING=16、NOTICE=32、INFO=64、DEBUG=128，
         * ALL 是它们的并按位或 30719）。LogAdapter 写级别时用 Log::ERROR，抄错一次就是静默写错级别。
         *
         * add() 是 5 参签名（$message, $priority, $category, $date, array $context）—— 与真实一致。
         * 桩只记 $entries 供断言，不建模 date/监听器/落盘。
         */
        class Log
        {
            public const ALL = 30719;

            public const EMERGENCY = 1;

            public const ALERT = 2;

            public const CRITICAL = 4;

            public const ERROR = 8;

            public const WARNING = 16;

            public const NOTICE = 32;

            public const INFO = 64;

            public const DEBUG = 128;

            /** @var list<array{message: mixed, priority: int, category: string, context: array}> */
            public static array $entries = [];

            public static function add($message, $priority = self::INFO, $category = '', $date = null, array $context = []): void
            {
                self::$entries[] = [
                    'message' => $message,
                    'priority' => $priority,
                    'category' => $category,
                    'context' => $context,
                ];
            }

            public static function reset(): void
            {
                self::$entries = [];
            }
        }
    }
}

namespace ErikWang2013\Xhprof\Tests\Stubs\Framework {

    use Joomla\CMS\Application\CMSApplicationInterface;
    use Joomla\Event\DispatcherInterface;
    use Joomla\Event\EventInterface;
    use Joomla\Event\SubscriberInterface;
    use Joomla\Input\Input;

    /**
     * 单测用的 dispatcher：任何一次真分发都是**测试写错了**，直接抛。
     *
     * 真实分发路径（`addSubscriber()` 拿键当事件名 → `dispatch()` → 按优先级调监听器）
     * 由验证环用**真实的** Joomla\Event\Dispatcher 覆盖（tools/contracts/cases/Joomla.php §5e），
     * 单测这里只需要知道「入口类没在构造期偷偷注册/分发」。
     */
    class JoomlaNoopDispatcher implements DispatcherInterface
    {
        public const WHY = '单测里不该真分发事件：真实分发由验证环的真实 Joomla\\Event\\Dispatcher 覆盖';

        public function dispatch(string $name, ?EventInterface $event = null): EventInterface
        {
            throw new \LogicException(self::WHY . "（dispatch('{$name}')）");
        }

        public function addListener(string $eventName, callable $callback, int $priority = 0): bool
        {
            throw new \LogicException(self::WHY);
        }

        public function clearListeners($event = null)
        {
            throw new \LogicException(self::WHY);
        }

        public function countListeners($event)
        {
            throw new \LogicException(self::WHY);
        }

        public function getListeners(?string $event = null)
        {
            throw new \LogicException(self::WHY);
        }

        public function hasListener(callable $callback, ?string $eventName = null)
        {
            throw new \LogicException(self::WHY);
        }

        public function removeListener(string $eventName, callable $listener): void
        {
            throw new \LogicException(self::WHY);
        }

        public function addSubscriber(SubscriberInterface $subscriber): void
        {
            throw new \LogicException(self::WHY);
        }

        public function removeSubscriber(SubscriberInterface $subscriber): void
        {
            throw new \LogicException(self::WHY);
        }
    }

    /**
     * 假 CMS 应用：只记「被要求发什么」，不真发（单测里没有 HTTP 层）。
     *
     * 输入数据从构造参数进（真实 CMS 里就是 `$app->getInput()` 返回的那个 Input），
     * 不读超全局 —— 这样用例能精确控制应用看到的请求，而 `$_SERVER` 留给 `Input::__get('server')` 那条真实路径。
     */
    class JoomlaFakeApplication implements CMSApplicationInterface
    {
        /** @var array<string, array{value: string, replace: bool}> */
        public array $headers = [];

        public int $sendHeadersCalls = 0;

        public int $closeCalls = 0;

        public int $closeCode = 0;

        /** @param array<string, mixed> $inputData */
        public function __construct(private array $inputData = [])
        {
        }

        public function getInput(): Input
        {
            return new Input($this->inputData);
        }

        public function setHeader(string $name, string $value, bool $replace = false): void
        {
            // 真实 CMS：$replace 为 false 且该头已存在时，是**追加**成多值头（逗号拼接发送）。
            if (!$replace && isset($this->headers[$name])) {
                $value = $this->headers[$name]['value'] . ', ' . $value;
            }

            $this->headers[$name] = ['value' => $value, 'replace' => $replace];
        }

        public function sendHeaders(): void
        {
            $this->sendHeadersCalls++;
        }

        public function close($code = 0): void
        {
            // 真实实现是 exit($code)（AbstractApplication::close()），桩只记不算 —— 环里同理，
            // 所以「close() 之后不许再输出」这条只能靠代码评审，见 Joomla 卡 SKIP 1。
            $this->closeCalls++;
            $this->closeCode = (int) $code;
        }

        public function headerValue(string $name): ?string
        {
            return $this->headers[$name]['value'] ?? null;
        }
    }
}
