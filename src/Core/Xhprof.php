<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\CacheInterface;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;
use ErikWang2013\Xhprof\Core\Analysis\Analyzer;
use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XhprofLib;

class Xhprof
{
    public static $time_limit = 0;
    public static $ignore_url_arr = ["/xhprof"];
    public static $key_prefix = 'xhprof';
    public static $log_num = 1000;
    public static $log_ttl = 86400 * 7;
    public static $view_wtred = 3;
    public static $ui_html = '';
    public static $symbol_lookup_url = "";

    public static ?RequestInterface $request = null;
    public static ?ResponseInterface $response = null;
    public static ?ConfigInterface $config = null;
    public static ?CacheInterface $cache = null;
    public static ?LoggerInterface $logger = null;

    private static bool $_hyperf = false;

    /** 进程级日志节流：已记过日志的路径标识 → true（见 logOnce()）。 */
    private static array $_logged_paths = [];

    /**
     * 声明当前运行在 Hyperf 协程环境，使 bootstrap() 把适配器写入协程 Context
     * 而不是共享的静态属性。
     *
     * 必须由 Hyperf 中间件在 bootstrap() 之前显式调用：autoDetect() 里那处赋值
     * 只在无参 bootstrap() 时才会执行，而 Hyperf 中间件始终传参，
     * 因此旧代码的 $_hyperf 在生产路径上恒为 false——协程隔离从未生效，
     * 常驻 worker 内并发协程会互相覆盖 request/response/cache 适配器。
     */
    public static function markHyperfContext(): void
    {
        self::$_hyperf = true;
    }

    /**
     * 当前进程有没有被声明成 Hyperf 协程环境（markHyperfContext() 或 autoDetect 置位）。
     *
     * Core 里要决定「写静态属性还是写协程存储」的地方问 coroutineContextClass()，别各自
     * 去读那个私有标志：本进程一旦置位就不可逆（常驻 worker 里本来也该一直是 true）。
     */
    public static function isHyperfContext(): bool
    {
        return self::$_hyperf;
    }

    /**
     * 本次调用该把适配器 / 渲染状态写进哪个协程上下文后端；null = 写进程静态属性。
     *
     * 两个后端、一套调用面（`$ctx::get($key)` / `$ctx::set($key, $value)`）：
     *   - `\Hyperf\Context\Context`：Hyperf，由 markHyperfContext() 声明（进程闩，见上）。
     *   - `\Workerman\Coroutine\Context`：workerman/webman 常驻 worker。**必须每次现问
     *     `isCoroutine()`**，不能像 Hyperf 那样置闩：实测真服务器上 onWorkerStart 在协程
     *     里、onMessage 不在（Select 事件循环，stock webman 的形状），启动时探一次会得到
     *     **反的**答案。而 Select 循环下 workerman 的 Fiber 驱动退化成**进程级**存储
     *     （真 `\Fiber::getCurrent()` 为 null 的那一支），切过去只是把共享状态换块地方放，
     *     隔离收益为零 —— 所以非协程一律留在静态属性（= 今天的行为，逐字不变）。
     *     跑在协程里时（Fiber 事件循环每回调一层 Fiber、Swoole/Swow 每请求一协程）这份
     *     存储按协程分桶，才是真的每请求一份。
     *
     * 装不上 workerman/coroutine 的环境（PHP 8.0、非 webman 应用、本仓单测）里
     * class_exists() 为 false，走静态属性，与重构前一致。类名只以字符串形态出现，
     * 不会把没装的类拉进 autoload。
     */
    public static function coroutineContextClass(): ?string
    {
        if (self::$_hyperf) {
            return class_exists(\Hyperf\Context\Context::class) ? \Hyperf\Context\Context::class : null;
        }
        if (class_exists(\Workerman\Coroutine::class) && \Workerman\Coroutine::isCoroutine()) {
            return \Workerman\Coroutine\Context::class;
        }

        return null;
    }

    public static function getRequest(): ?RequestInterface
    {
        $ctx = self::coroutineContextClass();

        return $ctx !== null ? $ctx::get('xhprof.request') : self::$request;
    }

    public static function getResponse(): ?ResponseInterface
    {
        $ctx = self::coroutineContextClass();

        return $ctx !== null ? $ctx::get('xhprof.response') : self::$response;
    }

    public static function getCache(): ?CacheInterface
    {
        $ctx = self::coroutineContextClass();

        return $ctx !== null ? $ctx::get('xhprof.cache') : self::$cache;
    }

    public static function getLogger(): ?LoggerInterface
    {
        $ctx = self::coroutineContextClass();

        return $ctx !== null ? $ctx::get('xhprof.logger') : self::$logger;
    }

    /**
     * 取配置适配器。**Hyperf 分支下 Context 缺键就是 null，刻意不回落 `self::$config`。**
     *
     * 常驻 worker 里 `self::$config` 是跨协程共享量（哪个协程最后 bootstrap() 就写谁的），
     * 回落等于让「没 bootstrap 的执行路径」静默用上别的请求配置（assets_url / auth_token /
     * log_ttl / view_wtred 都是按请求来的），而且没人能从页面上看出来。缺键只有一个含义：
     * 这条路径没走 bootstrap()，调用方按 null 走默认值即可（Xhprof::index()、
     * StaticController::uriPrefix() 都是这么写的）。另外四个 getter 同形同义
     * ——「闩开了以后 Context 是唯一来源」是共同契约，不给任何一个是例外。
     *
     * 曾考虑「缺键就回落 `self::$config`」被否：① 救不了场——同一次 bootstrap() 才写这五个
     * 键，缺 config 时 request 也缺，鉴权那里 `$req->get('token')` 照样炸；② 会诱导后来人
     * 把另外四个**请求级** getter 一起统一（拿别的协程的 request/response 比 null 更坏）。
     * 缺 request 那种状态由 index() 开头的显式 500 守卫负责说清楚，不在这里补救。
     */
    public static function getConfig(): ?ConfigInterface
    {
        $ctx = self::coroutineContextClass();

        return $ctx !== null ? $ctx::get('xhprof.config') : self::$config;
    }

    public static function index(): mixed
    {
        // 报告页要读缓存，而所有 CacheInterface 实现最终都要 `new \Redis()`
        // （缺扩展时是 "Class Redis not found" 的 Fatal error，浏览器上就是一片白加
        // 一行栈）。装了什么比「坏了」更该说清楚，所以这里先给一句能读的提示。
        // 与各入口类的「缺扩展就跳过采样」是同一件事的两半：那一半管写，这一半管读。
        if (!extension_loaded('redis')) {
            return self::deny('500 xhprof: ext-redis is not installed, so the report page cannot read profile data.', 500);
        }

        $req = self::getRequest();
        // 「读不出来」不能长得跟「不用读」一样：闩开了的进程里，若这个协程没 bootstrap()，
        // Context 里就没有 xhprof.request，`getRequest()` 返回 null。此前这里会静默跳过
        // 下面的 403 判定（`$cfg` 同样是 null），再在 `$req->get('run')` 处变成
        // "Call to a member function get() on null"——同样是 500、同样不吐数据，但读不出成因，
        // 而且「没配 auth_token」与「根本读不到配置」在日志里长得一模一样。显式拒绝并说清原因。
        if ($req === null) {
            return self::deny('500 xhprof: no request adapter in this coroutine\'s Hyperf Context — bootstrap() did not run here, so the request and its auth token cannot be verified. Refusing to render the report page.', 500);
        }
        $cfg = self::getConfig();
        // IP 白名单（默认关闭）。放在凭据校验之前：这是网络层闸门，凭据对不对都要先过它。
        if (!self::ipIsAllowed($req, $cfg)) {
            return self::deny('403 Forbidden', 403);
        }
        // 鉴权：`?token=xxx`（auth_token）与 HTTP Basic（auth_basic）**任一配置即生效**，
        // 两者是「或」——任一凭据校验通过即放行；配置了的凭据全不通过才拒绝。
        $authToken = $cfg !== null ? $cfg->get('xhprof.auth_token', null) : null;
        $authBasic = $cfg !== null ? $cfg->get('xhprof.auth_basic', null) : null;
        // 未配（null / 空串）与形态不对（非字符串，写了数组）都按「没配」处理，**不做**
        // `(string)` 强转：强转数组会立 "Array to string conversion" warning（升异常的
        // 宿主上 403 变 500）。形态校验（下面 is_string($token)）与是否配了凭据无关。
        $tokenConfigured = is_string($authToken) && $authToken !== '';
        $basicConfigured = is_string($authBasic) && $authBasic !== '';
        // token 与 run/source 同形的类型守卫：`?token[]=x` 以数组到达，`(string) $array`
        // 会在 hash_equals 之前立 "Array to string conversion" warning。必须在鉴权**之前**：
        // 鉴权拿它做比较，放后面等于没防。
        $token = $req->get('token', '');
        if (!is_string($token)) {
            return self::deny('400 Bad Request', 400);
        }
        $authorized = ($tokenConfigured && hash_equals($authToken, $token))
            || ($basicConfigured && self::basicCredentialsMatch($req, $authBasic));
        if (!$authorized) {
            if ($basicConfigured) {
                // 配了 Basic 就用 401 + WWW-Authenticate（RFC 7617）：这也是浏览器弹出
                // 凭据框的唯一触发方式——返回 403 的话浏览器永远不会提示输入。
                // 配置本身没有冒号时永远匹配不上，留一条可归因的日志，否则运维只看到
                // 一个 401、不知道是密码错还是配置写错了。
                if (!str_contains($authBasic, ':')) {
                    self::logOnce('auth_basic_malformed', 'xhprof: xhprof.auth_basic must look like "user:password" (the first colon separates; '
                        . 'the password may contain colons). The configured value has no colon, so no credentials '
                        . 'can ever match and the report page stays locked.');
                }
                return self::deny('401 Unauthorized', 401, array('WWW-Authenticate' => 'Basic realm="xhprof"'));
            }
            if ($tokenConfigured) {
                return self::deny('403 Forbidden', 403);
            }
            // 两个凭据都没配：报告页对任何人可读。默认不鉴权是拍板的既定行为，**不改**，
            // 但「裸奔」这件事必须留痕，节流到每进程一条（见 logOnce()）。文案向 deny()
            // 的英文串看齐。
            self::logOnce('auth_unconfigured', 'xhprof: neither xhprof.auth_token nor xhprof.auth_basic is configured, so the report page '
                . 'renders without authentication. Set xhprof.auth_token to require ?token=xxx, or '
                . 'xhprof.auth_basic ("user:password") to require HTTP Basic credentials.');
        }
        // run_id / source 白名单校验，防止任意 key 读取
        $run = $req->get('run');
        $run1 = $req->get('run1');
        $run2 = $req->get('run2');
        $source = $req->get('source');
        foreach ([$run, $run1, $run2] as $rp) {
            if ($rp === null || $rp === '') continue;
            if (!is_string($rp)) {
                return self::deny('400 Bad Request', 400);
            }
            foreach (explode(',', $rp) as $rid) {
                if (!XHProfRunsDefault::xhprof_valid_run_id($rid)) {
                    return self::deny('400 Bad Request', 400);
                }
            }
        }
        if ($source !== null && !XHProfRunsDefault::xhprof_valid_source($source)) {
            return self::deny('400 Bad Request', 400);
        }
        $wts = $req->get('wts');
        $symbol = $req->get('symbol');
        $sort = $req->get('sort');
        // 这三个也来自查询串，形态可以是数组（`?sort[]=wt`）。以前它们被原样透传，
        // 直到 `isset($arr[$array])` / `explode(",", $array)` 抛 TypeError → 500。
        // 而同一批参数里 `sort` 传非法**字符串**是被优雅处理的（回落 wt + 记日志），
        // 说明数组形态只是没人想到过。类型不对就是坏请求，与 run/source 同样 400。
        foreach ([$wts, $symbol, $sort] as $scalar_param) {
            if ($scalar_param !== null && !is_string($scalar_param)) {
                return self::deny('400 Bad Request', 400);
            }
        }
        // 只读导出（`?format=json|csv`）在**鉴权与参数白名单之后**分支：导出必须与报告页
        // 受同一套闸门（token/basic、IP 白名单、run/source 白名单）约束，不能因为换个
        // format 就绕过。非法值（含数组形态 `?format[]=json`）与未知值一样是坏请求。
        $format = $req->get('format');
        if ($format !== null) {
            if (!is_string($format) || !in_array($format, ['json', 'csv'], true)) {
                return self::deny('400 Bad Request', 400);
            }
            // symbol 是报告页的**单函数明细**视图，导出没有这个语义。以前它被静默忽略，
            // 调用方拿到一份全量平铺、还以为是自己要的那一个函数——拿到与请求语义不符的
            // 数据比报错更坏。带了 symbol（空值也算「要 symbol 视图」）就是坏请求。
            if ($symbol !== null) {
                return self::deny('400 Bad Request', 400);
            }
            // 导出与渲染共用同一个上报失败的出口（见 reportStoreUnavailable()）。
            try {
                return self::exportReport($format, $run, $run1, $run2, $source, $wts, $sort);
            } catch (\Throwable $e) {
                return self::reportStoreUnavailable($e);
            }
        }
        $params = $req->all();
        // 报告页语言：?lang= > 配置 xhprof.locale > Accept-Language > 兜底中文。
        // 四级都拿不到认识的语言码时 resolve() 返回 zh_CN，绝不抛异常。
        I18n::setLocale(I18n::resolve($req, $cfg));
        $echo_page = '<html lang="' . I18n::htmlLang() . '"'
            . (I18n::dir() === 'rtl' ? ' dir="rtl"' : '') . '>';
        $assetsUrl = '';
        if ($cfg !== null) {
            $assetsUrl = $cfg->get('xhprof.assets_url', '');
        }
        if ($assetsUrl === '') {
            $assetsUrl = self::$ui_html ?: '/xhprof-assets';
        }
        $echo_page .= "<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>" . I18n::plain('report.title') . "</title>";
        $echo_page .= XhprofDisplay::xhprof_include_js_css($assetsUrl);
        $echo_page .= "</head>";
        $echo_page .= "<body>";
        try {
            $echo_page .= XhprofDisplay::displayXHProfReport(
                $params,
                $source,
                $run,
                $wts,
                $symbol,
                $sort,
                $run1,
                $run2
            );
        } catch (\Throwable $e) {
            // 已拼好的一半页面直接丢弃：宁可 503 也不能回半张白屏。
            return self::reportStoreUnavailable($e);
        }
        $echo_page .= "</body>";
        $echo_page .= "</html>";
        return $echo_page;
    }

    /**
     * @param array<string, string> $headers 额外响应头（如 401 的 WWW-Authenticate）。
     *   12 家 ResponseAdapter 的 withHeaders() 都接受 `array<string,string>` 并逐条 set。
     *   无 Response 绑定的兜底分支保持既有形态（只设状态码 + no-store、返回 body）：
     *   那条路径下连 deny() 的既有调用者也拿不到**其他**响应头，不为它单独发明一套；
     *   no-store 是响应类别的属性（见下），不是某个调用者的额外头，两个分支都给。
     */
    private static function deny(string $body, int $status, array $headers = []): mixed
    {
        // token 在 query 串里，拒绝页与数据页同理不该被任何中间层缓存；与 respond()
        // 口径对齐（`+=`：调用方给了就不覆盖）。
        $headers += ['Cache-Control' => 'no-store'];
        $res = self::getResponse();
        if ($res !== null) {
            // withHeaders() 无条件调（与 respond() 对齐）：上面那行 `+=` 保证 `$headers`
            // 至少含 no-store，原先的 `if ($headers !== [])` 在新不变量下是死分支
            // （phpstan: notIdentical.alwaysTrue）。
            return $res->withStatus($status)->withHeaders($headers)->withBody($body)->send();
        }
        http_response_code($status);
        header('Cache-Control: no-store');
        return $body;
    }

    /**
     * 进程级日志节流：同一条路径每进程只记一次（粒度照抄 SamplingGuard 的先例）。
     *
     * 被节流的五条日志**全部能被匿名请求打到**：三条在鉴权之前（白名单形态错、转发头
     * 不可验证、白名单拒绝），两条在「凭据校验没过」分支里（auth_basic 无冒号、未配
     * 任何凭据）。常驻进程（Webman/Swoole/RoadRunner/FrankenPHP）下每请求一条等于给
     * 攻击者一个日志放大器——变着 XFF 打就能刷。这些日志是「运维去修配置」的路标，
     * 不是审计流水，丢重复的正是不变的那个事实。
     *
     * `$logger === null` 时不置位：这一次没写成，别把「本进程已经说过」记成事实。
     */
    private static function logOnce(string $path, string $message): void
    {
        $logger = self::getLogger();
        if ($logger === null || isset(self::$_logged_paths[$path])) {
            return;
        }
        self::$_logged_paths[$path] = true;
        $logger->error($message);
    }

    /**
     * 读路径（导出 / 报告页渲染）里未捕获的异常 → 503 + 人话，而不是让框架吐 500 白屏。
     *
     * 写路径早有兜底（XhprofProfiler.php:54-61），读路径此前只有「扩展没装」有人话提示
     * （index() 开头）：扩展在、Redis 连不上时 get_run()/list_runs() 抛的 RedisException
     * 会一路冒到框架。`catch (\Throwable)` 的边界：连渲染 bug（TypeError 等）也会变成
     * 503——刻意如此（宁可 503 不可白屏）；区分职责交给日志里的异常类名（RedisException
     * 是部署故障，TypeError 是代码缺陷）。日志**不**节流：同一进程里先后抛出两种异常时，
     * 后者的类名不该被前者的节流键吞掉。
     *
     * 未实测：Hyperf 协程分支（本机没装 hyperf/redis）下连接失败的具体异常类，可能经
     * 包装层（如 Hyperf\Redis\Exception\RedisException）抛出。catch (\Throwable) 不依赖
     * 具体类型，兜底照常生效；日志里的类名以实际抛出者为准。
     */
    private static function reportStoreUnavailable(\Throwable $e): mixed
    {
        self::getLogger()?->error(
            'xhprof: the report page failed while reading or rendering profile data: '
            . get_class($e) . ': ' . $e->getMessage()
        );
        return self::deny('503 xhprof: the report store is unavailable (' . get_class($e) . ').', 503);
    }

    /**
     * Authorization: Basic 凭据校验（RFC 7617）。
     *
     * `$configured` 形如 `user:password`，按**第一个**冒号切两段（`explode(…, 2)`：
     * 用户名里出现冒号时，密码侧保留全部剩余字符）。客户端凭据同理解析。
     *
     * 12 家 RequestAdapter 的 header() 逐个核实过（2026-10）：签名都是 `?string`
     * （strict_types=1，返回别的类型会 TypeError），返回裸头值或 null。is_string 守卫是
     * 防未来适配器换签名的廉价保险，顺带把「拿不到头」与「头值非字符串」都归为
     * 「没有凭据」。
     *
     * 部署注意：CGI/FastCGI 下 Apache **默认剥离 Authorization 头**（PHP 在
     * php-fpm/CGI 收不到它，除非 `CGIPassAuth On`——2.4.13+；或前端用 SetEnvIf 把
     * 它转成 REDIRECT_ 变量）。Native 适配器走 getallheaders() /
     * `$_SERVER['HTTP_AUTHORIZATION']` 还原，WordPress 适配器直接读
     * `$_SERVER['HTTP_AUTHORIZATION']`——头被剥离时这两条路径都拿到 null，结果是
     * 401（拒绝），不是放行。nginx + php-fpm 默认会把头传进来，不受此限。
     */
    private static function basicCredentialsMatch(RequestInterface $req, string $configured): bool
    {
        $header = $req->header('Authorization');
        if (!is_string($header)) {
            return false;
        }
        // scheme 大小写不敏感（RFC 7617）；base64 凭据不含空白字符。
        if (preg_match('/^\s*Basic\s+(\S+)\s*$/i', $header, $m) !== 1) {
            return false;
        }
        // 严格模式：非法字符 / 坏填充直接 false，不静默丢弃字符后再解出一个「凭据」。
        $plain = base64_decode($m[1], true);
        if ($plain === false || !str_contains($plain, ':')) {
            return false;
        }
        $given  = explode(':', $plain, 2);
        $expect = explode(':', $configured, 2);
        if (count($expect) !== 2) {
            return false;   // 配置本身没有冒号：任何输入都不匹配（调用点会记一条日志）
        }
        // 两段都比较完再合并结果：`&&` 短路会让「用户名错」提前返回，用时间差可以把
        // 用户名试出来（密码那半则始终比过 hash_equals）。
        $userOk = hash_equals($expect[0], $given[0]);
        $passOk = hash_equals($expect[1], $given[1]);
        return $userOk && $passOk;
    }

    /**
     * IP 白名单闸门（`xhprof.ip_allowlist`，代码内默认 `[]` = 整个特性关闭）。
     *
     * 判定来源是 `RequestInterface::getRealIp()`——契约里**没有** REMOTE_ADDR 访问器，
     * 12 家的实现逐个读过（2026-10）。其中 Laravel / Thinkphp / Yii2 / Symfony / Drupal
     * 是**委托给框架方法**（ip() / getUserIP() / getClientIp()），本机没装这几家框架，
     * 框架内部取法未逐行复核——下面把它们记在「随框架配置而定」那一档。真正兜底的是
     * 下面的转发头判别（不看适配器名单，只看值是否等于客户端自报的转发头）：
     *   - Webman 是唯一取真实 socket 对端地址的（workerman `getRealIp(true)` = 安全模式，
     *     不看转发头）；
     *   - Symfony / Laravel / Drupal / Yii2 走框架自身的可信代理逻辑，默认（未配可信
     *     代理时）同样给出 REMOTE_ADDR；Thinkphp 的 `Request::ip()` 按框架语义优先取
     *     HTTP_X_FORWARDED_FOR（本机未复核其源码，见上）。信任链成立与否由框架配置负责；
     *   - 其余（Native / WordPress / Joomla / Hyperf / Slim / Yii3）在本仓适配器源码里
     *     就能看到：收到 `X-Forwarded-For` / `X-Real-IP` 时**无条件**取转发头（XFF 取首段）。
     *
     * 也就是说：对多数适配器，客户端自己发一个 X-Forwarded-For 就能塑造 getRealIp()。
     * 直接拿它比对白名单会让白名单形同虚设（任何地址都能自报）。所以这里加一道
     * 「这个值是不是来自转发头」的判别：`getRealIp()` 恰好等于请求自带的 XFF 首段或
     * X-Real-IP 时视为**不可验证**，要求 `xhprof.trusted_proxies` 非空（部署声明
     * 「我前面有可信代理」，见该键注释）；判别不成立（适配器忽略了转发头、或框架
     * 自己算过信任链）时按真实来源判定。
     *
     * 已知边界（要在契约层根治得给 RequestInterface 加一个 `remoteAddr()`，不在本次
     * 围栏内，已交接）：
     *   - 声明 trusted_proxies 之后仍无法逐个核对**中间跳数**里哪一跳可信——判别只是
     *     把「不可验证」变成「部署声明了可接受」，文档必须写明：仅当部署在可信代理
     *     之后才安全；
     *   - 仅做逐字字符串比对：不支持 CIDR 网段，也不做 IPv6 规范化（`2001:0db8::1`
     *     与 `2001:db8::1` 是两个不同的字符串）。
     */
    private static function ipIsAllowed(RequestInterface $req, ?ConfigInterface $cfg): bool
    {
        $allow = $cfg !== null ? $cfg->get('xhprof.ip_allowlist', []) : [];
        if ($allow === null || $allow === []) {
            return true;   // 未配 = 关闭（代码内默认值，不进 12 份配置文件）
        }
        if (!is_array($allow)) {
            // 形态写错（写成字符串）时静默关闭会**丢掉一层安全控制**，与 fail closed 相反，
            // 所以这里选择拒绝并留日志。
            self::logOnce('ip_allowlist_malformed', 'xhprof: xhprof.ip_allowlist must be an array of IP strings; got ' . gettype($allow)
                . '. Refusing the request (fail closed) until the configuration is fixed.');
            return false;
        }
        $trusted = $cfg->get('xhprof.trusted_proxies', []);
        if (!is_array($trusted)) {
            $trusted = [];
        }
        $ip = $req->getRealIp();
        if ($trusted === [] && self::ipLookedForwarded($req, $ip)) {
            self::logOnce('ip_forwarded_unverifiable', 'xhprof: xhprof.ip_allowlist is enabled, but the client IP was taken from a forwarded header '
                . '(X-Forwarded-For / X-Real-IP) and xhprof.trusted_proxies is empty, so the value cannot be '
                . 'verified. Refusing the request. Set xhprof.trusted_proxies when (and only when) the app runs '
                . 'behind a proxy you control, or turn xhprof.ip_allowlist off.');
            return false;
        }
        if (!in_array($ip, $allow, true)) {
            // 键只按路径不含 IP：否则「变着 XFF 打」又能把日志刷起来。代价是长驻进程里
            // 只留下第一个被拒的 IP——这条日志是「去修白名单」的路标，不是审计流水。
            self::logOnce('ip_not_allowlisted', 'xhprof: request rejected by xhprof.ip_allowlist (client IP: ' . $ip . ').');
            return false;
        }
        return true;
    }

    /**
     * `$ip` 是不是可能来自**客户端可伪造**的转发头。
     *
     * 判别手法：与请求自带的 X-Forwarded-For 首段 / X-Real-IP 逐字比较。相等 ⇒ 某个
     * 适配器无条件取了转发头（或框架信任链算出了同一个值），这个值就可能是客户端
     * 自己写的。适配器忽略了转发头（Webman 安全模式、框架未配可信代理）时两者的值
     * 通常不同，照常按真实来源判定——两头的误判方向都是拒绝，不是放行。
     */
    private static function ipLookedForwarded(RequestInterface $req, string $ip): bool
    {
        foreach (['X-Forwarded-For', 'X-Real-IP'] as $name) {
            $value = $req->header($name);
            if (is_string($value) && $value !== '' && trim(explode(',', $value)[0]) === $ip) {
                return true;
            }
        }
        return false;
    }

    /**
     * `?format=json|csv` 只读导出。
     *
     * 位置由 index() 保证在**鉴权与参数白名单之后**：导出与报告页看的是同一份校验过的
     * 入参，token/basic 与 IP 白名单对导出同样有效——不能因为换个 format 就绕过闸门。
     *
     * 输出只含**稳定字段**（数字 / run_id / 规则名 / 函数名）：Analyzer 的 title/detail
     * 是 13 种语言的句子，进了契约等于把本地化文案固化成 API。
     *
     * 数据读取全部复用报告页的现成路径（get_run / xhprof_aggregate_runs /
     * xhprof_compute_flat_info / xhprof_compute_diff），列与排序语义 = 平铺报告：
     * json 的 functions[] 每行 = 平铺表一行（fn + ct + 各指标 + excl_*）；csv 的列 =
     * 平铺表的列，diff 模式下值列统一带 `delta_` 前缀（delta = run2 − run1，与页面同）。
     */
    private static function exportReport(
        string $format,
        ?string $run,
        ?string $run1,
        ?string $run2,
        ?string $source,
        ?string $wts,
        ?string $sort
    ): mixed {
        $run  = ($run === null || $run === '') ? null : $run;
        $run1 = ($run1 === null || $run1 === '') ? null : $run1;
        $run2 = ($run2 === null || $run2 === '') ? null : $run2;
        $source = $source ?? '';

        $diffMode = false;
        $runs = [];
        if ($run !== null) {
            $runs = explode(',', $run);
        } elseif ($run1 !== null && $run2 !== null) {
            $diffMode = true;
        } elseif ($run1 !== null || $run2 !== null) {
            // 半个 diff（只给 run1 或 run2）是坏请求。**必须排在列表分支之前**：
            // json 无 run 现在会返回列表，别把调用方给的半个 diff 静默吞掉。
            return self::deny('400 Bad Request', 400);
        } elseif ($format === 'json') {
            // 没给任何 run 的 json：返回 runs 列表（前提变更见 exportRunsList()）。
            return self::exportRunsList();
        } else {
            // csv 无 run：列表 CSV 没有消费场景，仍是坏请求（拍板不变）。
            return self::deny('400 Bad Request', 400);
        }

        $findings = [];
        $badRuns = [];
        $description = '';
        if ($diffMode) {
            $data1 = XHProfRunsDefault::get_run($run1, $source, $description);
            $data2 = XHProfRunsDefault::get_run($run2, $source, $description);
            if ($data1 === false || $data2 === false) {
                return self::deny('404 Not Found', 404);
            }
            // 与 profiler_diff_report() 同序：init_metrics() 决定 display_calls/metrics，
            // 必须先于 compute_flat_info()（后者经 XhprofDisplay 读这份渲染状态）。
            XhprofLib::init_metrics($data2, '', $sort, true);
            $delta = XhprofLib::xhprof_compute_diff($data1, $data2);
            $symbolTab = XhprofLib::xhprof_compute_flat_info($delta, $totals);
            // 诊断只在非 diff 视图产出（与报告页同一条守卫，理由见 profiler_report()：
            // diff 的增量做分母配原始边表做分子会得出负耗时）。
        } else {
            if (count($runs) === 1) {
                $data = XHProfRunsDefault::get_run($runs[0], $source, $description);
            } else {
                $wtsArray = ($wts === null || $wts === '') ? null : explode(',', $wts);
                $agg = XhprofLib::xhprof_aggregate_runs($runs, $wtsArray, $source, false);
                $data = $agg['raw'];
                $badRuns = isset($agg['bad_runs']) && is_array($agg['bad_runs']) ? $agg['bad_runs'] : [];
            }
            if ($data === false || $data === null) {
                return self::deny('404 Not Found', 404);
            }
            XhprofLib::init_metrics($data, '', $sort, false);
            $symbolTab = XhprofLib::xhprof_compute_flat_info($data, $totals);
            $findings = Analyzer::analyze($symbolTab, $data, $totals);
        }

        // 平铺行 + 与报告页同一套排序（sort 已在 index() 过形态校验；非法字符串由
        // init_metrics() 记日志后回落到 wt——与页面行为一致）。
        $flat = [];
        foreach ($symbolTab as $symbol => $info) {
            $flat[] = ['fn' => (string) $symbol] + (is_array($info) ? $info : []);
        }
        usort($flat, [XhprofDisplay::class, 'sort_cbk']);

        if ($format === 'json') {
            $payload = [
                'mode' => $diffMode ? 'diff' : (count($runs) > 1 ? 'aggregate' : 'single'),
                'source' => $source,
            ];
            if ($diffMode) {
                $payload['run1'] = $run1;
                $payload['run2'] = $run2;
            } elseif (count($runs) > 1) {
                // 聚合时被丢弃的坏 run（过期/损坏）单独列出：不然「少聚合了一条」是无声的。
                $payload['runs'] = $runs;
                $payload['bad_runs'] = $badRuns;
            } else {
                $payload['run'] = $runs[0];
                // 该 run 的请求元数据（列表页那一行的同源数据）。request_log 过期只剩
                // 悬空索引项时是 null——键在、值为 null，机器消费方形状稳定。
                $payload['request'] = self::runRequestMetadata($runs[0]);
            }
            $payload['totals'] = $totals;
            $payload['findings'] = [];
            foreach ($findings as $f) {
                // 只留稳定字段：title/detail 是 13 语言的文案，不得进契约。
                $payload['findings'][] = [
                    'rule' => $f->rule,
                    'severity' => $f->severity,
                    'symbol' => $f->symbol,
                    'score' => $f->score,
                ];
            }
            $payload['functions'] = $flat;
            return self::respondJson($payload);
        }

        // CSV：列 = 平铺报告的列语义（fn + 调用次数 + 各指标 + 各自耗时），diff 时值列
        // 统一带 `delta_` 前缀（页面里这一列就是 run2 − run1，列名自述其义）。
        $metrics = (array) XhprofDisplay::metrics();
        $columns = ['fn'];
        if (XhprofDisplay::display_calls()) {
            $columns[] = 'ct';
        }
        foreach ($metrics as $metric) {
            $columns[] = (string) $metric;
        }
        foreach ($metrics as $metric) {
            $columns[] = 'excl_' . $metric;
        }
        $header = $columns;
        if ($diffMode) {
            foreach ($header as $i => $name) {
                if ($name !== 'fn') {
                    $header[$i] = 'delta_' . $name;
                }
            }
        }
        $lines = [self::csvRow($header)];
        foreach ($flat as $row) {
            $cells = [];
            foreach ($columns as $name) {
                $cells[] = array_key_exists($name, $row) ? $row[$name] : '';
            }
            $lines[] = self::csvRow($cells);
        }
        $filename = $diffMode
            ? 'xhprof-' . $run1 . '-vs-' . $run2 . '.csv'
            : 'xhprof-' . str_replace(',', '_', (string) $run) . '.csv';
        return self::respond(implode("\n", $lines) . "\n", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** 200 + 给定响应头 + body 的直出（与 deny() 同一条链，状态固定 200）。 */
    private static function respond(string $body, array $headers): mixed
    {
        // token 在 query 串里，导出响应与 HTML 页同理不该被任何中间层缓存；HTML 路径的
        // no-cache 在适配器侧，这里与 deny() 口径对齐（`+=`：调用方给了就不覆盖）。
        $headers += ['Cache-Control' => 'no-store'];
        $res = self::getResponse();
        if ($res !== null) {
            return $res->withStatus(200)->withHeaders($headers)->withBody($body)->send();
        }
        http_response_code(200);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        return $body;
    }

    /**
     * `?format=json` 且没给 run/run1/run2：runs 列表 JSON。
     *
     * 2026-10 语义变更：此前是 400，那个拍板的**前提**是「导出只有 run 级」——列表
     * JSON 对机器消费方有意义后前提不再成立（「有哪些 run 可以导」本身就是合法查询）；
     * csv 无 run 仍是 400（列表 CSV 无消费场景），拍板不变。
     *
     * 一次读取喂两者：lRange + mget 只跑一趟，rows 与头部五个字段（count/limit/
     * oldest/newest/ttl，形状与语义逐字抄 XHProfRunsDefault::runsOverview()——count
     * 取索引长度含悬空项，oldest/newest 只统计 mget 得到的行）同源。**不调**
     * list_runs()/runsOverview()：两者各自再读一遍 Redis 就是双调（它们共用的
     * runsIndexAndLogs() 是 Display 批的私有助手，本文件够不着）。
     *
     * sort/wts 在这里**暂不生效、有意不 400**：它们是「当前未实现、将来可实现」的
     * 列表取向参数（按 wts 排序是合理的未来特性），400 会把路堵死；与 symbol 不同——
     * symbol 是报告页单函数视图，导出没有这个语义，给了就是语义不符。
     */
    private static function exportRunsList(): mixed
    {
        $runIds = Xhprof::getCache()->lRange(Xhprof::$key_prefix . ':run_id', 0, Xhprof::$log_num);
        $keys = array_map(static function ($runId) {
            return Xhprof::$key_prefix . ':request_log:' . $runId;
        }, $runIds);
        $values = array_values(Xhprof::getCache()->mget($keys));

        $rows = [];
        $oldest = null;
        $newest = null;
        foreach ($runIds as $i => $runId) {
            // 与 list_runs() 同一条行过滤：非法 id / 读不到 / 坏 JSON 的行不出现
            if (!XHProfRunsDefault::xhprof_valid_run_id($runId)) continue;
            $row = self::requestRow($values[$i] ?? null);
            if ($row === null) continue;
            $t = $row['create_time'];
            if ($oldest === null || $t < $oldest) $oldest = $t;
            if ($newest === null || $t > $newest) $newest = $t;
            $rows[] = ['run_id' => $runId] + $row;
        }

        return self::respondJson([
            'mode'   => 'runs',
            'count'  => count($runIds),
            'limit'  => (int) Xhprof::$log_num,
            'oldest' => $oldest,
            'newest' => $newest,
            'ttl'    => (int) Xhprof::$log_ttl,
            'runs'   => $rows,
        ]);
    }

    /**
     * request_log 的原始 JSON 串 → 稳定字段行（只含数字/字符串）；读不到 / 坏 JSON
     * 返回 null。单 run 导出的 `request` 键与列表 JSON 的 runs[] 行共用这一个形状。
     */
    private static function requestRow(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $row = json_decode($raw, true);
        if (!is_array($row)) {
            return null;
        }
        return [
            'method'      => (string) ($row['method'] ?? ''),
            'request_uri' => (string) ($row['request_uri'] ?? ''),
            'create_time' => (int) ($row['create_time'] ?? 0),
            'wt'          => (float) ($row['wt'] ?? 0),
            'mu'          => (float) ($row['mu'] ?? 0),
            'ip'          => (string) ($row['ip'] ?? ''),
        ];
    }

    /** 单 run 的 request 元数据：一次 GET request_log；缺失/坏值 → null。 */
    private static function runRequestMetadata(string $runId): ?array
    {
        return self::requestRow(Xhprof::getCache()->get(Xhprof::$key_prefix . ':request_log:' . $runId));
    }

    /** payload → JSON 直出（encode flags 与失败口径：单 run/diff/aggregate/列表共用一份）。 */
    private static function respondJson(array $payload): mixed
    {
        // INVALID_UTF8_SUBSTITUTE：坏符号名（非 UTF-8）替换成 U+FFFD，不让整份导出失败；
        // PARTIAL_OUTPUT_ON_ERROR：INF/NAN 这类 JSON 无法表达的值不会让 encode 返回 false。
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if ($json === false) {
            // 上面几个 flag 兜底后基本不可能发生；真发生宁可 500 让调用方看见，
            // 也不能回一个空 body 当成功。
            return self::deny('500 xhprof: the report data could not be encoded as JSON.', 500);
        }
        return self::respond($json, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * RFC 4180 的一行 CSV：含 `,` `"` CR/LF 的单元格加引号并翻倍引号。
     *
     * 不用 fputcsv()：它的浮点格式化在小数点上是 **locale 相关**的（de_DE 下 1.5 →
     * "1,5"，整列错位），而 PHP 的 float→string 强转与 locale 无关（恒为 `.`）。
     */
    private static function csvRow(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            $s = is_string($cell) ? $cell : (string) $cell;
            if (strpbrk($s, ",\"\r\n") !== false) {
                $s = '"' . str_replace('"', '""', $s) . '"';
            }
            $out[] = $s;
        }
        return implode(',', $out);
    }

    public static function xhprofStart(): void
    {
        // 扩展检查由各框架 middleware 负责，走到这里说明已通过
        XhprofProfiler::start();
    }

    public static function xhprofStop(): void
    {
        XhprofProfiler::stop();
    }

    public static function bootstrap(
        ?RequestInterface $request = null,
        ?ResponseInterface $response = null,
        ?ConfigInterface $config = null,
        ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null
    ): void {
        if ($request !== null) {
            self::$request = $request;
            if ($response !== null) self::$response = $response;
            if ($config !== null) self::$config = $config;
            if ($cache !== null) self::$cache = $cache;
            if ($logger !== null) self::$logger = $logger;
        } else {
            self::autoDetect();
        }
        // 协程安全：有协程后端就写本协程的 Context，否则留在静态属性上（见 coroutineContextClass()）
        $ctx = self::coroutineContextClass();
        if ($ctx !== null) {
            $ctx::set('xhprof.request', self::$request);
            $ctx::set('xhprof.response', self::$response);
            $ctx::set('xhprof.config', self::$config);
            $ctx::set('xhprof.cache', self::$cache);
            $ctx::set('xhprof.logger', self::$logger);
        }
        XhprofProfiler::bootstrap();
    }

    private static function autoDetect(): void
    {
        if (class_exists(\Webman\App::class)) {
            self::$request = new \ErikWang2013\Xhprof\Webman\Adapter\RequestAdapter(request());
            self::$response = new \ErikWang2013\Xhprof\Webman\Adapter\ResponseAdapter(response());
            self::$config = new \ErikWang2013\Xhprof\Webman\Adapter\ConfigAdapter();
            self::$cache = new \ErikWang2013\Xhprof\Webman\Adapter\RedisAdapter();
            self::$logger = new \ErikWang2013\Xhprof\Webman\Adapter\LogAdapter();
        } elseif (class_exists(\Illuminate\Foundation\Application::class)) {
            self::$request = new \ErikWang2013\Xhprof\Laravel\Adapter\RequestAdapter(app('request'));
            // 必须传 ''，不能无参：Laravel 的 response() 在 func_num_args()===0 时
            // 返回的是 ResponseFactory 而非响应对象，而适配器里 `$response ?? response('')`
            // 拦不住它（工厂非 null）。此后 Xhprof::deny() 调 withStatus() 会命中
            // Macroable::__call 抛 BadMethodCallException —— 403/400 变成 500。
            self::$response = new \ErikWang2013\Xhprof\Laravel\Adapter\ResponseAdapter(response(''));
            self::$config = new \ErikWang2013\Xhprof\Laravel\Adapter\ConfigAdapter();
            self::$cache = new \ErikWang2013\Xhprof\Laravel\Adapter\RedisAdapter();
            self::$logger = new \ErikWang2013\Xhprof\Laravel\Adapter\LogAdapter();
        } elseif (class_exists(\think\App::class)) {
            self::$request = new \ErikWang2013\Xhprof\Thinkphp\Adapter\RequestAdapter(app('request'));
            self::$response = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ResponseAdapter(response());
            self::$config = new \ErikWang2013\Xhprof\Thinkphp\Adapter\ConfigAdapter();
            self::$cache = new \ErikWang2013\Xhprof\Thinkphp\Adapter\RedisAdapter();
            self::$logger = new \ErikWang2013\Xhprof\Thinkphp\Adapter\LogAdapter();
        // 注意：Hyperf 3.x 没有 \Hyperf\Framework\ApplicationContext（该命名空间下
        // 只有 ApplicationFactory/Bootstrap/ConfigProvider/Event/Exception/Logger）。
        // 旧代码探测的是这个不存在的类，导致本分支永不命中，README 里无参
        // Xhprof::bootstrap() 的用法会直接抛 "Unsupported framework"。
        } elseif (class_exists(\Hyperf\Context\ApplicationContext::class)) {
            self::$_hyperf = true;
            $container = \Hyperf\Context\ApplicationContext::getContainer();
            self::$request = new \ErikWang2013\Xhprof\Hyperf\Adapter\RequestAdapter($container->get(\Hyperf\HttpServer\Request::class));
            self::$response = new \ErikWang2013\Xhprof\Hyperf\Adapter\ResponseAdapter($container->get(\Hyperf\HttpServer\Response::class));
            self::$config = new \ErikWang2013\Xhprof\Hyperf\Adapter\ConfigAdapter($container->get(\Hyperf\Contract\ConfigInterface::class));
            self::$cache = new \ErikWang2013\Xhprof\Hyperf\Adapter\RedisAdapter($container->get(\Hyperf\Redis\Redis::class));
            self::$logger = new \ErikWang2013\Xhprof\Hyperf\Adapter\LogAdapter($container->get(\Psr\Log\LoggerInterface::class));
        } else {
            throw new \RuntimeException('ErikWang2013\Xhprof: Unsupported framework. Use Xhprof::bootstrap() to inject adapters manually.');
        }
    }
}
