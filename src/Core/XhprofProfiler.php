<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;

class XhprofProfiler
{
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
    }

    public static function isEnabled(): bool
    {
        return (bool) self::configValue('enable', false);
    }

    /**
     * 本次请求要不要真的开采样（`sample_rate`）。
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
