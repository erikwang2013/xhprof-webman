<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Yii2\Adapter;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

/**
 * Yii2 请求适配器：包装 `yii\web\Request`（`Yii::$app->getRequest()`）。
 *
 * 三处与「直接读超全局」的框架不同，都是 Yii2 自己的语义，逐条写在对应方法上：
 *  - `uri()` / `host()` 用框架的 `getUrl()` / `getHostName()`，形状天然满足 R-1/R-2；
 *  - `getRealIp()` 用 `getUserIP()`：**未配 `trustedHosts` 时转发头是被滤掉的**
 *    （见 `header()` 的注释），这是 Yii2 的安全默认，比「无条件取 X-Forwarded-For 首段」
 *    更保守——与 Laravel/Symfony（走 Symfony 的 trusted-proxy 判定）同一类语义；
 *  - `get()/all()` 把 query 与 body 合并成一份，避免「校验一个值、渲染另一个值」。
 */
class RequestAdapter implements RequestInterface
{
    private \yii\web\Request $request;

    public function __construct(\yii\web\Request $request)
    {
        $this->request = $request;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // 与 all() 共用一份合并结果 —— 同一个契约不能因为访问方式不同而给不同答案。
        // 对比：yii\web\Request::getQueryParam() 用的是 isset()，而这里用 array_key_exists()，
        // 差别只在「键存在但值为 null」这一格；而 $_GET 里不可能有 null。
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function all(): array
    {
        // query 胜出，body 里独有的键补齐（与 Webman/Laravel/Thinkphp/Yii3 四家同一条规则）。
        // `+` 就是「左边优先且保持左边的键序」，正是这条语义；用 array_merge 会把键序变成
        // body 在前，拼出来的链接参数顺序就跟 URL 里不一致了。
        return $this->request->getQueryParams() + $this->bodyParams();
    }

    public function method(): string
    {
        return (string) $this->request->getMethod();
    }

    /**
     * R-3：header 可以没有，但**绝不能给声明 `?string` 之外的东西**。
     *
     * 注意 Yii2 的 `getHeaders()` 会经 `filterHeaders()`（`web/Request.php:337`）滤掉
     * `secureHeaders`（X-Forwarded-For / X-Forwarded-Proto / X-Real-* 等）——除非用户在
     * `trustedHosts` 里显式信任来源。所以反代部署下 `header('x-forwarded-for')` 默认是
     * null，而 `getRealIp()` 会落到 REMOTE_ADDR。这一条要改的是**应用的 request 配置**，
     * 不是本适配器：本包不替站点决定信任谁。
     */
    public function header(string $name): ?string
    {
        $value = $this->request->getHeaders()->get($name);

        return is_string($value) ? $value : null;
    }

    /**
     * R-2：只返回 host，**不含端口**。`getHostName()` 是 `parse_url(hostInfo, PHP_URL_HOST)`
     * （`web/Request.php:833`），天然无端口；但 hostInfo 无法判定时它会返回 **null**，
     * 而本方法声明 `: string`，故必须强转。
     */
    public function host(): string
    {
        return (string) $this->request->getHostName();
    }

    /**
     * R-1：只返回 路径+query，**绝不含 scheme/host**。`getUrl()`（`web/Request.php:1070`）
     * 走 `resolveRequestUri()`：优先 `X-Rewrite-Url` 头，其次 `$_SERVER['REQUEST_URI']`，
     * 再退 `ORIG_PATH_INFO`——三者都没有时**抛 `InvalidConfigException`**。
     *
     * 抛错的那一格在真实部署里几乎不会出现（IIS 之外 REQUEST_URI 一直在），但本方法在
     * 每个请求上都会被调用（报告页/资源短路的第一句），而 `XhprofLib::isIgnore()` 对空串的
     * 判定是「不落库」——退化成一个安静的「不采样」，比让一次请求 500 好。
     */
    public function uri(): string
    {
        try {
            return (string) $this->request->getUrl();
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function url(): string
    {
        return (string) $this->request->getAbsoluteUrl();
    }

    /**
     * R-3：任何分支都返回 string（缺省 127.0.0.1），不返回 null。
     *
     * `getUserIP()`（`web/Request.php:1264`）= 先从 `ipHeaders`（默认只有 X-Forwarded-For）
     * 里取，取不到退 `getRemoteIP()`；两者都拿不到时返回 null。而取转发头那一步依赖
     * `trustedHosts`：不配就一个转发头都看不到（见 `header()`）。配了之后也**不是**
     * 「取首段」——它按信任段从右往左走，取的是第一个不可信地址（`web/Request.php:1286`）。
     * 两种形态都是 Yii2 自己复核过的答案，本适配器照抄，不另立一套。
     */
    public function getRealIp(): string
    {
        $ip = $this->request->getUserIP();

        return is_string($ip) && $ip !== '' ? $ip : '127.0.0.1';
    }

    /**
     * body 参数，拿不到就空数组。
     *
     * `getBodyParams()`（`web/Request.php:600`）在配了 `parsers`（标准 REST 配置里就有
     * `application/json => yii\web\JsonParser`）时，**畸形 JSON 会抛 `BadRequestHttpException`**；
     * 自定义 parser 还可能抛 `Error`，所以这里 catch `\Throwable`。
     * 必须兜住的理由：本适配器在每个被采样请求上都会执行 `all()`，不能把一个「控制器本来
     * 不读 body、因此本来不会 500」的请求打成 500。
     *
     * 另外 parser 配 `asArray = false` 时返回的是对象而不是数组，而本方法声明 `: array`。
     */
    private function bodyParams(): array
    {
        try {
            $body = $this->request->getBodyParams();
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($body) ? $body : [];
    }
}
