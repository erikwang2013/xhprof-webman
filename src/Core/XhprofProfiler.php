<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XhprofLib;

class XhprofProfiler
{
    /**
     * 按需触发采样的请求头名（Tideways 的 X-Tideways 模式）。固定值、刻意不做配置键：
     * 改头名没有使用场景，密钥才是每个项目要变的东西。
     */
    private const TRIGGER_HEADER = 'X-Xhprof-Token';

    private static ?array $config = null;

    /**
     * 本进程是否有一对尚未配对的 start()。幂等保护下沉到 Core 的落点：
     * 各入口类自己那份 `$stopped` 守卫保留（它们要防的是自己的重复钩子），
     * 但**约定之外**的调用方（新入口、业务代码手动 start/stop、测试）不该靠
     * 「记得别多调一次」来保证正确。
     */
    private static bool $running = false;

    public static function start(): void
    {
        if (!self::shouldSample()) {
            // 抽签没中：不调 xhprof_enable()，也不置位 $running——随后必然到来的
            // stop() 因幂等守卫自然变成 no-op（这正是 $running 存在的意义）。
            // 跳过时**不**改写 $running：那会误伤嵌套调用（入口已 start 成功、业务
            // 代码再手动 start 而这次没抽中）时正在跑的那次采样。
            return;
        }
        xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
        self::$running = true;
    }

    public static function stop(): void
    {
        // 幂等：没有配对的 start()（或已经 stop 过）就什么都不做。
        // 以前无条件 xhprof_disable()：未采样时它返回 null，而写路径照样把一条
        // 「0 耗时 / 0 内存」的空 run 写进列表与报告页——多调一次 stop() 就是
        // 一条脏数据，还会把列表里真实的那条挤老（log_num 上限是硬裁剪）。
        if (!self::$running) {
            return;
        }
        // 先清标记再 disable：即便 disable 抛异常（扩展被卸载等极端情况），
        // 这次 stop 也已经算发生过，不能让下一次 stop 再写一条空 run。
        self::$running = false;
        $xhprof_data = xhprof_disable();
        try {
            XHProfRunsDefault::save_run($xhprof_data, "xhprof_foo");
        } catch (\Throwable $e) {
            // 落库是尽力而为的旁路。中间件在 finally 里调用本方法，phpredis 在
            // 连接中断/认证失败/超时时会抛 RedisException——不加这道防线，
            // 一个本来健康的请求会变成 500，且业务异常会被顶替掉。
            Xhprof::getLogger()?->error('Xhprof save_run failed: ' . $e->getMessage());
        }
    }

    public static function bootstrap(): void
    {
        $config = Xhprof::getConfig();
        if ($config === null) {
            return;
        }
        $pluginConfig = $config->get('xhprof', []);
        self::$config = $pluginConfig;
        Xhprof::$ignore_url_arr = $pluginConfig['ignore_url_arr'] ?? ['/xhprof'];
        Xhprof::$time_limit = (int) ($pluginConfig['time_limit'] ?? 0);
        Xhprof::$log_num = (int) ($pluginConfig['log_num'] ?? 1000);
        Xhprof::$view_wtred = (int) ($pluginConfig['view_wtred'] ?? 3);
        Xhprof::$key_prefix = (string) ($pluginConfig['key_prefix'] ?? 'xhprof');
        Xhprof::$log_ttl = (int) ($pluginConfig['log_ttl'] ?? 86400 * 7);
        // 源码链接模板：XhprofDisplay::print_source_link() 把它与 `?symbol=<urlencoded
        // 函数名>` 拼起来渲染成链接。未配（null）与形态不对（写了数组）都落到静态量的
        // 原初值 ""——print_source_link() 对空串不加链接，与加本键之前的行为逐字一致。
        // 顺手用 is_string 拦下形态错误：`(string)` 强转数组会立 "Array to string
        // conversion" warning（升异常的宿主上报告页 500），而这里连不上强转的必要。
        $lookup = $pluginConfig['symbol_lookup_url'] ?? null;
        Xhprof::$symbol_lookup_url = is_string($lookup) ? $lookup : '';
    }

    public static function isEnabled(): bool
    {
        return (bool) self::configValue('enable', false);
    }

    /**
     * 本次请求要不要真的开采样：先看按需触发（`trigger_token` + X-Xhprof-Token 头），
     * 没触发再走按比例抽签（`sample_rate`）。
     *
     * 取值 (0,1]：每个走到这里的请求以该概率被采样。1.0 是默认值，也是加本键之前的
     * 唯一行为，走快路径不打随机；<=0.0 直接跳过（0 = 明确关闭）。中间值用
     * random_int 抽签——它不可播种，测试只钉这两个确定性边界，不做统计断言。
     *
     * 退化口径：布尔按开关处理（false = 不采，true = 全采；Drupal 的 install yml 由
     * Symfony YAML 解析，裸写 `off`/`no` 就是 false，若按"非数值→默认"处理会变成
     * 全采，与意图相反）；负数 clamp 成 0（=不采）；其余非数值（写错的字符串等）
     * 按默认 1.0。两个方向的代价不对称：错成全采只是回到旧行为（ignore_url_arr
     * 仍是兜底），错成全不采会让报告页静默空白、看起来像插件坏了；但显式写
     * false/0/负数 是明确意图，照办。
     */
    private static function shouldSample(): bool
    {
        // A#4：被排除路径（ignore_url_arr 命中）在**采样前**就短路——原先它跑完整趟
        // 采样、到 save_run() 才被丢弃。语义不变：判定完全交给 XhprofLib::isIgnore()
        // （对 uri() 子串匹配、空 URI 看 sample_cli），save_run() 里那道同款检查**保留**
        // 作兜底。零新增开销的对象正是「被排除路径」：不再付 xhprof_enable()/disable()
        // 与整趟采样的钱；其余请求多一次子串匹配（微秒级）。
        // 判定依赖 bootstrap 已完成（12 家入口都在 xhprofStart() 之前 bootstrap()，
        // 报告页/资源路径在更早处短路）。request 为 null（未 bootstrap）或判定自身
        // 出错时**不**在这里拦：退回旧路径，由 save_run() 的同一道检查兜底（stop()
        // 的 try/catch 会把那里的失败记成日志），不让这处优化新增一个 500 面。
        try {
            if (Xhprof::getRequest() !== null && !XhprofLib::isIgnore()) {
                return false;
            }
        } catch (\Throwable $e) {
            // 有意不记日志：旧行为里这类失败（如 ignore_url_arr 元素写成数组，strtolower
            // 抛 TypeError）的唯一留痕点是 save_run() 的 catch，且只在采样真发生时。
            // 在这里记一条，会让「配置坏了但这次没抽中」凭空多出日志，与旧行为不一致。
        }

        // 按需触发（生产「平时不采、要查时采」）：trigger_token 配成非空字符串，且请求头
        // X-Xhprof-Token 与它逐字节相等 → 强制采样，无视 sample_rate（含 rate=0）。
        //
        // 只认请求头，**不认 query**：query 会被写进访问日志、Referer 与浏览器历史，
        // 密钥跟着 run_id 一起泄露；请求头不进这些地方。
        //
        // 威胁模型：这个头是「可对任意请求强制全采样」的口子。没配 trigger_token（null/空）
        // 时该头完全被无视——默认零攻击面；一旦配置，拿到密钥的人就能刷满采样，把合法 run
        // 逐出 log_num（上限是硬裁剪，rPop 掉最老的）。所以密钥必须是够长的随机串，
        // 且只能发给可信的人。比较用 hash_equals 防时序侧信道；形状照
        // Xhprof.php 的 auth_token 先例（配置空 = 关闭）。
        // 触发**不**绕过 ignore_url_arr：ignore 管「哪些路径永不记录」，触发管「这次要不要
        // 记录」，两条独立轴——各入口对报告页/ignore 路径的守卫在 start() 之前，带密钥
        // 也一样跳过。
        // 配置侧的空判定与 (string) 强转逐字照 auth_token 先例（数字密钥不加引号也能用）；
        // 请求侧 header(): ?string 由全部 12 家适配器声明保证（数组会被 PHP 的返回类型
        // 在适配器边界上截成 TypeError，漏不进来），is_string 需要的是「有值」这一半。
        $expected = self::configValue('trigger_token', null);
        if ($expected !== null && $expected !== '') {
            $header = Xhprof::getRequest()?->header(self::TRIGGER_HEADER);
            if (is_string($header) && hash_equals((string) $expected, $header)) {
                return true;
            }
        }

        // 自适应预算（`max_runs_per_minute`，int|null，代码内默认 null = 关闭）：给
        // 「每分钟最多记录几条」设个硬顶，防止自动采样把 log_num 的槽位与 Redis 写带宽
        // 灌满。实现是「当前分钟桶」计数：键 <key_prefix>:budget:<YmdHi>，incr 后**首次**
        // set TTL 120s（Redis 的 incr 不设 TTL，不显式 set 桶键会被永久留下；120s 覆盖
        // 分钟边界即可，过期桶自然清零）。计数算的是**走到这一步的请求数**（含没抽中的），
        // 不是实际落库数——判在抽签之前，连抽签的随机开销一起省。非正数 / 非数值按关闭
        // 处理（写坏的代价最小化 = 回到旧行为，与 sample_rate 的退化方向同一口径）。
        // 触发路径在它之前 return、预算管不到：预算是给**自动流量**设的上限，不该把
        // 拿着密钥来排查的人挡在门外（「要查时查不到」比「多写几条 run」更难排查）。
        // 失败开口（fail-open）：缓存没绑（null）/取不到/抛异常 → 照常按 sample_rate
        // 走——采样是尽力而为的旁路，让预算机制把采样整条弄崩或静默停摆，比偶尔超预算
        // 坏得多。审计警告的「把 start() 与 Redis 耦合」就落在这里：耦合只发生在本键
        // 显式开启时，失败方向也是回到旧行为，而不是让请求失败。
        $budget = self::configValue('max_runs_per_minute', null);
        if (is_numeric($budget) && (int) $budget > 0) {
            $cache = Xhprof::getCache();
            if ($cache !== null) {
                try {
                    $bucket = Xhprof::$key_prefix . ':budget:' . date('YmdHi');
                    $used = $cache->incr($bucket);
                    if ($used === 1) {
                        $cache->set($bucket, $used, 120);
                    }
                    if ($used > (int) $budget) {
                        return false;
                    }
                } catch (\Throwable $e) {
                    // fail-open：记不下账就照常采样，绝不因预算机制向外抛
                }
            }
        }

        $rate = self::configValue('sample_rate', 1.0);
        if (is_bool($rate)) {
            return $rate;
        }
        if (!is_numeric($rate)) {
            return true;
        }
        $rate = (float) $rate;
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }
        // 百万分位抽签：整数比较，没有浮点边界问题。rate 极小时阈值为 0 → 恒不采，
        // 极大时（如 0.9999999）→ 恒采，单调且不越界。
        $scale = 1_000_000;
        return random_int(0, $scale - 1) < (int) round($rate * $scale);
    }

    /**
     * 读一个 xhprof 配置项：优先 bootstrap() 缓存的整块配置；没有（未 bootstrap）
     * 就现读 Xhprof::getConfig() 的 'xhprof' 块；都取不到才用 $default。
     * 与 isEnabled() 原先的内联回退逐字等价。
     */
    private static function configValue(string $key, mixed $default): mixed
    {
        $config = self::$config;
        if ($config === null) {
            $cfg = Xhprof::getConfig();
            if ($cfg === null) {
                return $default;
            }
            $config = $cfg->get('xhprof', []);
        }
        return $config[$key] ?? $default;
    }
}
