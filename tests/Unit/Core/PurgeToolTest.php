<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core;

require_once __DIR__ . '/../../../tools/purge.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 只实现了 scan / del / exists 的假 phpredis。
 *
 * **故意不实现 keys()**：清理工具若改用 `KEYS` 会在这些用例里直接 Error（调用不存在的方法），
 * 于是「必须用 SCAN」这条约定有可执行的守卫，而不只是文件头的一句注释。
 */
final class FakeScanRedis
{
    /** @var array<string, true> */
    private array $keys = [];

    /** @var list<string|null> 每次 scan 收到的 pattern，按调用顺序 */
    public array $scanPatterns = [];

    /** @var list<array<int, string>|string> 每次 del 收到的参数，用来钉「整批一次 DEL」 */
    public array $delCalls = [];

    private int $batch;

    /** @param list<string> $keys */
    public function __construct(array $keys, int $batch = 2)
    {
        foreach ($keys as $key) {
            $this->keys[$key] = true;
        }
        $this->batch = $batch;
    }

    /** 与 phpredis 同形：游标引用传入，迭代结束时置 0 并返回 false。 */
    public function scan(&$cursor, ?string $pattern = null, int $count = 0): array|false
    {
        $this->scanPatterns[] = $pattern;
        $matched = [];
        foreach (array_keys($this->keys) as $key) {
            if ($pattern === null || fnmatch($pattern, $key)) {
                $matched[] = $key;
            }
        }
        $offset = $cursor === null ? 0 : (int) $cursor;
        $slice = array_slice($matched, $offset, $this->batch);
        if ($offset + count($slice) >= count($matched)) {
            $cursor = 0;
            return $slice === [] ? false : $slice;   // 空批 = 迭代结束（phpredis 的收尾路径）
        }
        $cursor = $offset + count($slice);
        return $slice;
    }

    /** @param array<int, string>|string $keys */
    public function del(array|string $keys): int
    {
        $this->delCalls[] = $keys;
        $n = 0;
        foreach ((array) $keys as $key) {
            if (isset($this->keys[$key])) {
                unset($this->keys[$key]);
                $n++;
            }
        }
        return $n;
    }

    public function exists(string $key): int
    {
        return isset($this->keys[$key]) ? 1 : 0;
    }

    /** @return list<string> */
    public function remaining(): array
    {
        return array_keys($this->keys);
    }

    /** @return list<string> */
    public function deletedKeys(): array
    {
        $flat = [];
        foreach ($this->delCalls as $call) {
            foreach ((array) $call as $key) {
                $flat[] = $key;
            }
        }
        return $flat;
    }
}

final class PurgeToolTest extends TestCase
{
    private const SEED = [
        'xhp:request_log:aaa',
        'xhp:request_log:bbb',
        'xhp:xhprof_log:aaa',
        'xhp:run_id',
        'other:request_log:ccc',   // 别人家的前缀：不许碰
        'xhp:other:zzz',           // 同前缀但不在三族里：也不许碰
    ];

    #[Test]
    public function dryRunCountsEachFamilyAndDeletesNothing(): void
    {
        $redis = new FakeScanRedis(self::SEED);

        $result = \XhprofPurge::purge($redis, 'xhp', false);

        $this->assertSame(['request_log' => 2, 'xhprof_log' => 1, 'run_id' => 1], $result['found']);
        $this->assertSame(['request_log' => 0, 'xhprof_log' => 0, 'run_id' => 0], $result['deleted']);
        $this->assertSame([], $redis->delCalls, '没加 --yes 不许有任何 DEL');
        $this->assertSame(self::SEED, $redis->remaining());
    }

    #[Test]
    public function yesDeletesAllThreeFamiliesInBatches(): void
    {
        $redis = new FakeScanRedis(self::SEED);

        $result = \XhprofPurge::purge($redis, 'xhp', true);

        $this->assertSame($result['found'], $result['deleted'], '逐族删除数应当等于统计数');
        $this->assertSame(['other:request_log:ccc', 'xhp:other:zzz'], $redis->remaining());
        $this->assertSame(
            ['xhp:request_log:aaa', 'xhp:request_log:bbb', 'xhp:xhprof_log:aaa', 'xhp:run_id'],
            $redis->deletedKeys()
        );
        foreach ($redis->delCalls as $call) {
            $this->assertIsArray($call, 'DEL 必须按批传数组：逐键删在大库上是几十万次 RTT');
        }
    }

    #[Test]
    public function scanUsesExactlyTheTwoFamilyPatternsAndRunIdKey(): void
    {
        $redis = new FakeScanRedis(self::SEED);

        \XhprofPurge::purge($redis, 'xhp', false);

        // 逐字钉 pattern：退化成 `xhp:*` 会把同前缀下不属于三族的键一起扫进来（上面的
        // `xhp:other:zzz` 就是判别性输入）。
        $this->assertSame(['xhp:request_log:*', 'xhp:xhprof_log:*'], $redis->scanPatterns);
    }

    #[Test]
    public function multiBatchScanConvergesInsteadOfLosingKeysAcrossCursors(): void
    {
        $keys = [];
        for ($i = 0; $i < 5; $i++) {
            $keys[] = 'xhp:request_log:' . $i;
        }
        $redis = new FakeScanRedis($keys, 2);   // 每批 2 个 → 单趟要 3 次 scan

        // 边扫边删会让游标后的键位置漂移（假 redis 与真 Redis 同形：删除后 rehash，
        // 扫描可能跳过键）。工具对此的承诺是「打印匹配数 + 重跑收敛」，不是一趟清空。
        $rounds = 0;
        do {
            $result = \XhprofPurge::purge($redis, 'xhp', true);
            $rounds++;
            $this->assertSame(
                $result['found'],
                $result['deleted'],
                '每一趟的删除数都要与统计数一致（静默少删是不允许的）'
            );
        } while (array_sum($result['found']) > 0 && $rounds < 5);

        $this->assertSame([], $redis->remaining(), '重跑必须收敛到清空');
        $this->assertLessThan(5, $rounds, '不该需要这么多趟');
        $this->assertSame(0, $result['found']['run_id'], '夹具里没有 run_id 键');
    }

    #[Test]
    public function emptyDatabaseCountsZeroAndDoesNotCallDel(): void
    {
        $redis = new FakeScanRedis([]);

        $result = \XhprofPurge::purge($redis, 'xhp', true);

        $this->assertSame(['request_log' => 0, 'xhprof_log' => 0, 'run_id' => 0], $result['found']);
        $this->assertSame(['request_log' => 0, 'xhprof_log' => 0, 'run_id' => 0], $result['deleted']);
        $this->assertSame([], $redis->delCalls);
    }

    #[Test]
    public function prefixIsHonoured(): void
    {
        $redis = new FakeScanRedis(['demo:request_log:1', 'xhprof:request_log:1', 'demo:run_id']);

        $result = \XhprofPurge::purge($redis, 'demo', true);

        $this->assertSame(1, $result['found']['request_log']);
        $this->assertSame(1, $result['found']['run_id']);
        $this->assertSame(['xhprof:request_log:1'], $redis->remaining());
    }

    #[Test]
    public function parseArgsDefaultsMatchTheDocumentedOnes(): void
    {
        $this->assertSame([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => 0,
            'prefix' => 'xhprof',
            'yes' => false,
        ], \xhprof_purge_parse_args(['purge.php']));
    }

    #[Test]
    public function parseArgsAcceptsEqualsAndSpaceForms(): void
    {
        $opt = \xhprof_purge_parse_args([
            'purge.php', '--host=10.0.0.1', '--port', '6390', '--password=se cret',
            '--database', '3', '--prefix', 'demo', '--yes',
        ]);

        $this->assertSame([
            'host' => '10.0.0.1',
            'port' => 6390,
            'password' => 'se cret',
            'database' => 3,
            'prefix' => 'demo',
            'yes' => true,
        ], $opt);
    }

    /** @return iterable<string, array{0: list<string>}> */
    public static function badArgsProvider(): iterable
    {
        yield 'unknown option' => [['purge.php', '--nope']];
        yield 'missing value' => [['purge.php', '--host']];
        yield 'port not a number' => [['purge.php', '--port=abc']];
        yield 'port out of range' => [['purge.php', '--port=70000']];
        yield 'negative database' => [['purge.php', '--database=-1']];
        yield 'empty prefix' => [['purge.php', '--prefix=']];
        yield 'glob in prefix' => [['purge.php', '--prefix=xh*']];
        yield 'stray positional' => [['purge.php', 'purge']];
    }

    /** @param list<string> $argv */
    #[Test]
    #[DataProvider('badArgsProvider')]
    public function parseArgsRejectsBadInput(array $argv): void
    {
        $this->assertNull(\xhprof_purge_parse_args($argv));
    }

    #[Test]
    public function badArgsMakeMainReturnTheUsageExitCode(): void
    {
        // 退出码 2 = 参数/连接错误（文档写在文件头）；这条不碰 Redis
        $this->assertSame(2, \xhprof_purge_main(['purge.php', '--nope']));
    }
}
