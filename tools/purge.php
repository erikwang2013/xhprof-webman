<?php

declare(strict_types=1);

/**
 * tools/purge.php —— 清空 xhprof 落在 Redis 里的三类键（**纯 CLI**）。
 *
 * 用法：
 *   php tools/purge.php                          # 只统计（默认 host 127.0.0.1 / port 6379 / db 0）
 *   php tools/purge.php --host=127.0.0.1 --port=6379 --password=xxx --database=0 --prefix=xhprof
 *   php tools/purge.php --yes                    # 真的删
 *
 * 退出码：0 = 已删除；1 = 只统计、没删（缺 --yes）；2 = 参数/连接错误。
 *
 * 为什么**不开 HTTP 入口**：报告页的鉴权（token / Basic）是「读到全部采样数据」的
 * 最小凭据，泄露的后果是数据泄露。删除是不可逆的，若把同一套凭据接到一个 HTTP 删除
 * 入口上，一次凭据泄露就从「读全部」升级成「删全部」，而这层升级对受害者没有任何
 * 可见性——报告页不会因为数据被删而报错，只会变成一张空列表页。清理数据是运维动作，
 * 放在有 shell 权限的人手里（server 上 / crontab 里），不给远端一个理由。
 *
 * 实现只用 SCAN：KEYS <pattern> 在线上库是 O(N) 的单线程阻塞命令，几十万键的库上会
 * 把整个 Redis 卡住（连带正在写日志的业务请求）。SCAN 分批游标推进，代价有两条：
 *   - 迭代期间**新写入**的键可能落在游标身后，这一趟看不到；
 *   - 一边扫一边删时，Redis 内部 rehash 会让个别还没返回的键被跳过（Redis 对 SCAN
 *     的承诺只覆盖迭代期间不被修改的键集合；把整族先收进内存再删可以避免，但那要
 *     按「7 天 TTL 内的全部采样」占用内存，不划算）。
 * 两条的后果都是**可能剩一点**，不会是无限循环或删错：所以每族都会打印匹配数，残留
 * 一眼可见，重跑一次即收敛（该工具定位是主动提前清，TTL 到期也会自行清理）。
 * 删是幂等的：重复执行不会更糟。
 *
 * 前缀里的 glob 元字符（`*` `?` `[` `]` `\`）会让 pattern 失去本意，直接拒绝而不是
 * 转义：转义规则由 Redis 的 glob 语义决定，猜错一次就是删了别人家的键。
 */

/** 一次 SCAN 的 COUNT 提示值（Redis 只当提示，返回可能多也可能少）。 */
const XHPROF_PURGE_SCAN_COUNT = 500;

final class XhprofPurge
{
    /** 三族键的显示顺序（run_id 是精确键，单独处理） */
    public const FAMILIES = ['request_log', 'xhprof_log', 'run_id'];

    /**
     * 统计并（`$delete` 为真时）删除 `<prefix>:request_log:*`、`<prefix>:xhprof_log:*`
     * 与 `<prefix>:run_id`。
     *
     * @param object $redis phpredis 实例（只用到 scan / del / exists）
     * @return array{found: array<string, int>, deleted: array<string, int>}
     */
    public static function purge(object $redis, string $prefix, bool $delete): array
    {
        $found = [];
        $deleted = [];
        foreach (['request_log', 'xhprof_log'] as $family) {
            $sweep = self::sweep($redis, $prefix . ':' . $family . ':*', $delete);
            $found[$family] = $sweep['found'];
            $deleted[$family] = $sweep['deleted'];
        }

        // run_id 是**精确键**（列表，只存指针），用 exists/del 直取：不用通配符是刻意的，
        // 少一条「pattern 写错就扫到邻居」的路径。
        $runIdKey = $prefix . ':run_id';
        $hasRunId = (bool) $redis->exists($runIdKey);
        $found['run_id'] = $hasRunId ? 1 : 0;
        $deleted['run_id'] = ($hasRunId && $delete) ? (int) $redis->del([$runIdKey]) : 0;

        return ['found' => $found, 'deleted' => $deleted];
    }

    /**
     * SCAN 走完一个 pattern，按批 del。
     *
     * @return array{found: int, deleted: int}
     */
    private static function sweep(object $redis, string $pattern, bool $delete): array
    {
        $cursor = null;   // phpredis：引用传入，null = 从头开始迭代
        $found = 0;
        $deleted = 0;
        while (true) {
            $keys = $redis->scan($cursor, $pattern, XHPROF_PURGE_SCAN_COUNT);
            if (!is_array($keys)) {
                break;    // false = 迭代结束（phpredis 的收尾约定），其它非数组形态一律收手
            }
            if ($keys !== []) {
                $found += count($keys);
                if ($delete) {
                    // 整批一次 DEL：phpredis 的 del(array) 是一条命令删多个键，
                    // 逐键删在几十万键上就是几十万次 RTT。
                    $deleted += (int) $redis->del($keys);
                }
            }
            if ((int) $cursor === 0) {
                break;    // 游标归零 = 迭代结束（返回 false 的 phpredis 版本走上面那条）
            }
        }
        return ['found' => $found, 'deleted' => $deleted];
    }
}

/**
 * 解析命令行。
 *
 * 支持 `--key=value` 与 `--key value` 两种写法。
 *
 * @param list<string> $argv
 * @return array{host: string, port: int, password: string, database: int, prefix: string, yes: bool}|null
 *         null = 参数有误（打用法、退出 2）
 */
function xhprof_purge_parse_args(array $argv): ?array
{
    $opt = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => '',
        'database' => 0,
        'prefix' => 'xhprof',
        'yes' => false,
    ];
    $args = array_values(array_slice($argv, 1));
    $n = count($args);
    for ($i = 0; $i < $n; $i++) {
        $arg = (string) $args[$i];
        if ($arg === '--yes') {
            $opt['yes'] = true;
            continue;
        }
        if (preg_match('/^--(host|port|password|database|prefix)(?:=(.*))?$/', $arg, $m) !== 1) {
            fwrite(STDERR, '未知参数：' . $arg . "\n");
            return null;
        }
        if (isset($m[2])) {
            $value = $m[2];
        } else {
            if ($i + 1 >= $n) {
                fwrite(STDERR, '参数 ' . $arg . " 缺少取值\n");
                return null;
            }
            $value = (string) $args[++$i];
        }
        $opt[$m[1]] = $value;
    }

    if (!preg_match('/^\d+$/', (string) $opt['port']) || (int) $opt['port'] < 1 || (int) $opt['port'] > 65535) {
        fwrite(STDERR, "--port 必须是 1-65535 的整数\n");
        return null;
    }
    if (!preg_match('/^\d+$/', (string) $opt['database'])) {
        fwrite(STDERR, "--database 必须是非负整数\n");
        return null;
    }
    $opt['port'] = (int) $opt['port'];
    $opt['database'] = (int) $opt['database'];

    if ($opt['prefix'] === '' || preg_match('/[*?\[\]\\\\]/', (string) $opt['prefix']) === 1) {
        fwrite(STDERR, "--prefix 不能为空，也不能含 glob 元字符（* ? [ ] \\）\n");
        return null;
    }
    return $opt;
}

function xhprof_purge_usage(): string
{
    return <<<TXT
xhprof 数据清理（只统计用；真的删要加 --yes）

  php tools/purge.php [--host=127.0.0.1] [--port=6379] [--password=] [--database=0] [--prefix=xhprof] [--yes]

清理三类键：<prefix>:request_log:*、<prefix>:xhprof_log:*、<prefix>:run_id。
不加 --yes 只统计（退出码 1），加了才删除（退出码 0）；参数/连接错误退出码 2。

TXT;
}

/** @param list<string> $argv */
function xhprof_purge_main(array $argv): int
{
    $opt = xhprof_purge_parse_args($argv);
    if ($opt === null) {
        fwrite(STDERR, xhprof_purge_usage());
        return 2;
    }

    try {
        $redis = new Redis();
        if (!$redis->connect($opt['host'], $opt['port'], 2.0)) {
            throw new RuntimeException('connect() 返回 false');
        }
        if ($opt['password'] !== '') {
            $redis->auth($opt['password']);
        }
        if ($opt['database'] !== 0) {
            $redis->select($opt['database']);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '连接 Redis ' . $opt['host'] . ':' . $opt['port'] . ' 失败：' . $e->getMessage() . "\n");
        return 2;
    }

    $result = XhprofPurge::purge($redis, $opt['prefix'], $opt['yes']);
    foreach (XhprofPurge::FAMILIES as $family) {
        $found = $result['found'][$family];
        printf(
            "%-12s 匹配 %d 个键%s\n",
            $family,
            $found,
            $opt['yes'] ? '，已删 ' . $result['deleted'][$family] : ''
        );
    }
    if (!$opt['yes']) {
        echo "未删除任何键（缺少 --yes）。确认上面是自己要清的数据后重跑：php tools/purge.php --yes\n";
        return 1;
    }
    echo "完成。\n";
    return 0;
}

// 只在被当脚本直接执行时进入 main：被 require（如单测）时 argv[0] 是本文件的
// realpath 之外的东西，不能在这里跑起来。
if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath((string) $_SERVER['argv'][0]) === realpath(__FILE__)) {
    exit(xhprof_purge_main(array_map('strval', (array) $_SERVER['argv'])));
}
