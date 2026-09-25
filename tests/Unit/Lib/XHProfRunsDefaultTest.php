<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Lib;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FakeCache::lPush 存在 by-ref 传参 bug（array_unshift($this->lists[$key] ??= [], ...)），
 * 测试进程内无法修改 Fixtures，此处用修复版子类覆盖列表方法。
 */
class RunsFixedListCache extends FakeCache
{
    private array $myLists = [];

    public function lPush(string $key, mixed $value): int
    {
        $this->calls[] = "lPush:$key";
        $this->myLists[$key] ??= [];
        array_unshift($this->myLists[$key], $value);
        return count($this->myLists[$key]);
    }

    public function rPop(string $key): mixed
    {
        $this->calls[] = "rPop:$key";
        if (empty($this->myLists[$key])) {
            return null;
        }
        return array_pop($this->myLists[$key]);
    }

    public function lRange(string $key, int $start, int $end): array
    {
        $this->calls[] = "lRange:$key";
        $list = $this->myLists[$key] ?? [];
        $count = count($list);
        if ($start < 0) {
            $start = max(0, $count + $start);
        }
        if ($end < 0) {
            $end = $count + $end;
        }
        return array_slice($list, $start, max(0, $end - $start + 1));
    }
}

class XHProfRunsDefaultTest extends TestCase
{
    use XhprofStaticsSnapshot;

    /** setUp 开工前的静态量快照（tearDown 原样放回） */
    private array $saved = [];

    protected FakeCache $cache;
    protected FakeRequest $request;
    protected FakeResponse $response;
    protected FakeConfig $config;
    protected FakeLogger $logger;

    /** setUp 时的 Xhprof::$log_ttl，tearDown 原样还回去（静态量会跨用例残留） */
    protected int $originalLogTtl;

    protected function setUp(): void
    {
        // 先照单全收再改：本类下面会改写 `$ignore_url_arr` 等进程级静态量，
        // 不还原就会漏给后面的用例（实测：Core+Lib+Adapter 顺序下 Adapter 侧 3 条假红）。
        $this->saved = $this->snapshotXhprofStatics();

        $this->cache = new RunsFixedListCache();
        $this->request = new FakeRequest([], ['uri' => '/order', 'url' => 'http://xhprof.local/xhprof']);
        $this->response = new FakeResponse();
        $this->config = new FakeConfig([]);
        $this->logger = new FakeLogger();
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);
        // Hyperf 测试先跑会置 $_hyperf=true，按 bootstrap 同款逻辑刷新 Context，避免取到过期适配器
        if (class_exists(\Hyperf\Context\Context::class)) {
            \Hyperf\Context\Context::set('xhprof.request', $this->request);
            \Hyperf\Context\Context::set('xhprof.response', $this->response);
            \Hyperf\Context\Context::set('xhprof.config', $this->config);
            \Hyperf\Context\Context::set('xhprof.cache', $this->cache);
            \Hyperf\Context\Context::set('xhprof.logger', $this->logger);
        }
        Xhprof::$time_limit = 0;
        Xhprof::$ignore_url_arr = [];
        Xhprof::$key_prefix = 'xhprof';
        Xhprof::$log_num = 1000;
        Xhprof::$view_wtred = 3;
        $this->originalLogTtl = Xhprof::$log_ttl;
    }

    protected function tearDown(): void
    {
        // log_ttl 是静态量：有用例会把它改成非默认值来证明透传，改过就必须还原，
        // 否则泄漏给后续用例（顺序相关的假绿/假红都是这么来的）。
        Xhprof::$log_ttl = $this->originalLogTtl;

        // 其余静态量与 `_hyperf` 一并放回 setUp 前的样子（trait）。
        $this->restoreXhprofStatics($this->saved);
    }

    /** 替换请求时同步刷新 Hyperf Context，保证 $_hyperf=true 时 getRequest() 仍取到 fake */
    private function useRequest(FakeRequest $request): void
    {
        Xhprof::$request = $request;
        if (class_exists(\Hyperf\Context\Context::class)) {
            \Hyperf\Context\Context::set('xhprof.request', $request);
        }
    }

    private function sampleData(): array
    {
        return [
            'main()' => ['wt' => 1234567, 'mu' => 2048],
            'main()==>foo()' => ['ct' => 1, 'wt' => 100, 'mu' => 512],
        ];
    }

    #[Test]
    #[DataProvider('validRunIdProvider')]
    public function validRunIdAcceptsHexIds(string $runId): void
    {
        self::assertTrue(XHProfRunsDefault::xhprof_valid_run_id($runId));
    }

    public static function validRunIdProvider(): array
    {
        return [
            '16 hex chars' => ['a1b2c3d4e5f60718'],
            '13 hex chars' => ['abcdef0123456'],
            '32 hex chars' => [str_repeat('f', 32)],
        ];
    }

    #[Test]
    #[DataProvider('invalidRunIdProvider')]
    public function validRunIdRejectsInvalidIds(mixed $runId): void
    {
        self::assertFalse(XHProfRunsDefault::xhprof_valid_run_id($runId));
    }

    public static function invalidRunIdProvider(): array
    {
        return [
            'uppercase' => ['A1B2C3D4E5F60718'],
            'non hex char' => ['a1b2c3d4e5f607g'],
            'too short' => ['abcdef012345'],
            'too long' => [str_repeat('a', 33)],
            'empty string' => [''],
            'null' => [null],
            'integer' => [12345],
            'leading space' => [' a1b2c3d4e5f60718'],
        ];
    }

    #[Test]
    #[DataProvider('validSourceProvider')]
    public function validSourceAcceptsNames(string $source): void
    {
        self::assertTrue(XHProfRunsDefault::xhprof_valid_source($source));
    }

    public static function validSourceProvider(): array
    {
        return [
            'underscore' => ['xhprof_foo'],
            'single char' => ['a'],
            'dash and dot' => ['a-b.c_d'],
            '64 chars' => [str_repeat('x', 64)],
        ];
    }

    #[Test]
    #[DataProvider('invalidSourceProvider')]
    public function validSourceRejectsInvalidNames(mixed $source): void
    {
        self::assertFalse(XHProfRunsDefault::xhprof_valid_source($source));
    }

    public static function invalidSourceProvider(): array
    {
        return [
            'uppercase' => ['XHPROF_FOO'],
            'empty string' => [''],
            '65 chars' => [str_repeat('x', 65)],
            'space' => ['a b'],
            'slash' => ['a/b'],
            'null' => [null],
            'integer' => [123],
        ];
    }

    #[Test]
    public function getRunReturnsUnserializedData(): void
    {
        $data = $this->sampleData();
        $this->cache->set('xhprof:xhprof_log:a1b2c3d4e5f60718', serialize($data));
        $desc = null;
        $res = XHProfRunsDefault::get_run('a1b2c3d4e5f60718', 'xhprof_foo', $desc);
        self::assertSame($data, $res);
        self::assertSame('XHProf Run (Namespace=xhprof_foo)', $desc);
    }

    #[Test]
    public function getRunRejectsInvalidRunIdWithoutTouchingCache(): void
    {
        $desc = null;
        $res = XHProfRunsDefault::get_run('UPPER', 'xhprof_foo', $desc);
        self::assertFalse($res);
        self::assertSame([], $this->cache->calls);
    }

    #[Test]
    public function getRunRejectsInvalidSource(): void
    {
        $desc = null;
        $res = XHProfRunsDefault::get_run('a1b2c3d4e5f60718', 'XHPROF_FOO', $desc);
        self::assertFalse($res);
    }

    #[Test]
    public function getRunWithMissingKeyReturnsFalse(): void
    {
        // 原实现 unserialize(null) 在 strict_types 下抛 TypeError；
        // src 已加 is_string 守卫（见 git diff），现应返回 false。
        $desc = null;
        self::assertFalse(XHProfRunsDefault::get_run('a1b2c3d4e5f60718', 'xhprof_foo', $desc));
    }

    #[Test]
    public function saveRunSkipsWhenUnderTimeLimit(): void
    {
        Xhprof::$time_limit = 1; // 1 second
        $data = $this->sampleData();
        $data['main()']['wt'] = 500000; // 0.5s < 1s
        self::assertFalse(XHProfRunsDefault::save_run($data, 'xhprof_foo'));
        self::assertSame([], $this->cache->calls);
    }

    #[Test]
    public function saveRunPersistsWhenAboveTimeLimit(): void
    {
        Xhprof::$time_limit = 1;
        $data = $this->sampleData();
        $data['main()']['wt'] = 2000000; // 2s > 1s
        $runId = XHProfRunsDefault::save_run($data, 'xhprof_foo');
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $runId);
    }

    #[Test]
    public function saveRunSkipsIgnoredUrl(): void
    {
        Xhprof::$ignore_url_arr = ['/test'];
        $this->request = new FakeRequest([], ['uri' => '/test/foo']);
        $this->useRequest($this->request);
        self::assertFalse(XHProfRunsDefault::save_run($this->sampleData(), 'xhprof_foo'));
        self::assertSame([], $this->cache->calls);
    }

    #[Test]
    public function saveRunPersistsRunListAndLogs(): void
    {
        $this->request = new FakeRequest(
            [],
            [
                'method' => 'POST',
                'uri' => '/order',
                'host' => 'example.com',
                'ip' => '1.2.3.4',
                'headers' => ['x-forwarded-proto' => 'https'],
            ]
        );
        $this->useRequest($this->request);

        $data = $this->sampleData();
        // 刻意设成不等于默认值（86400*7 = 604800）的数：期望值若写成 Xhprof::$log_ttl，
        // 就与生产代码读的是同一个静态量——「自己等于自己」永真，把生产代码换成硬编码
        // 604800 的变异体照样绿。设成 3600 后，透传的是不是这个静态量才成为可观测事实。
        Xhprof::$log_ttl = 3600;
        $runId = XHProfRunsDefault::save_run($data, 'xhprof_foo');

        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $runId);
        self::assertSame([$runId], $this->cache->lRange('xhprof:run_id', 0, -1));

        $row = json_decode($this->cache->get('xhprof:request_log:' . $runId), true);
        self::assertSame('https://example.com/order', $row['request_uri']);
        self::assertSame('POST', $row['method']);
        self::assertSame(1.2346, $row['wt']);
        self::assertSame(0.002, $row['mu']);
        self::assertSame('1.2.3.4', $row['ip']);
        self::assertIsInt($row['create_time']);

        $stored = $this->cache->get('xhprof:xhprof_log:' . $runId);
        self::assertSame($data, unserialize($stored));

        // log_ttl 必须透传到两个写入点。此前 fake 直接丢弃 TTL，
        // 把配置改成 0 或干脆不传，整套测试依然全绿（数据永不失效也测不出来）。
        // 期望值是上面的字面量 3600，不是 Xhprof::$log_ttl——理由见那里的注释。
        self::assertSame(3600, $this->cache->ttls['xhprof:request_log:' . $runId]);
        self::assertSame(3600, $this->cache->ttls['xhprof:xhprof_log:' . $runId]);
    }

    #[Test]
    public function checkLogNumTrimsOldestRunWhenOverLimit(): void
    {
        Xhprof::$log_num = 1;
        $this->cache->lPush('xhprof:run_id', 'oldrun0000000001');
        $this->cache->set('xhprof:request_log:oldrun0000000001', '{}');
        $this->cache->set('xhprof:xhprof_log:oldrun0000000001', serialize(['main()' => ['wt' => 1]]));

        $runId = XHProfRunsDefault::save_run($this->sampleData(), 'xhprof_foo');

        self::assertNull($this->cache->get('xhprof:request_log:oldrun0000000001'));
        self::assertNull($this->cache->get('xhprof:xhprof_log:oldrun0000000001'));
        self::assertSame([$runId], $this->cache->lRange('xhprof:run_id', 0, -1));
    }

    /**
     * 残留的 run_id_num 计数器不得影响裁剪。
     *
     * 旧实现完全信任该计数器：一旦它高于 log_num（例如手动 DEL 掉 run_id 列表
     * "清理性能数据"之后），每轮 save 都会先 incr 再把刚写入的 run 删掉——
     * 采样永久静默失效，且每请求白付 6 次 Redis 往返。
     * 现在裁剪只依据 lPush 返回的真实列表长度，该键形同历史遗留数据。
     */
    #[Test]
    public function staleRunIdNumCounterCannotDeleteFreshRuns(): void
    {
        Xhprof::$log_num = 1000;
        $this->cache->set('xhprof:run_id_num', 1001);   // 高于上限的残留计数器

        $runId = XHProfRunsDefault::save_run($this->sampleData(), 'xhprof_foo');

        self::assertNotNull(
            $this->cache->get('xhprof:xhprof_log:' . $runId),
            '本次采样数据必须保留'
        );
        self::assertSame([$runId], $this->cache->lRange('xhprof:run_id', 0, -1));
    }

    #[Test]
    public function listRunsRendersRowsWithEscapingAndWarnClass(): void
    {
        $this->cache->lPush('xhprof:run_id', 'a1a1a1a1a1a1a1a1');
        $this->cache->lPush('xhprof:run_id', 'b2b2b2b2b2b2b2b2');
        $this->cache->lPush('xhprof:run_id', 'c3c3c3c3c3c3c3c3'); // no request_log -> skipped

        $this->cache->set('xhprof:request_log:a1a1a1a1a1a1a1a1', json_encode([
            'request_uri' => 'http://example.com/<script>alert(1)</script>',
            'method' => 'POST',
            'wt' => 5,
            'mu' => 2.5,
            'ip' => '9.9.9.9',
            'create_time' => 1700000000,
        ]));
        $this->cache->set('xhprof:request_log:b2b2b2b2b2b2b2b2', json_encode([
            'request_uri' => 'http://example.com/ok',
            'method' => 'GET',
            'wt' => 0.5,
            'mu' => 1.0,
            'ip' => '8.8.8.8',
            'create_time' => 1700000001,
        ]));

        $html = XHProfRunsDefault::list_runs();

        self::assertStringContainsString('POST', $html);
        self::assertStringContainsString('GET', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertSame(1, substr_count($html, 'xp-wt-warn'));
        // `&` 在属性里写成 `&amp;`：链接由 XhprofLib::report_url() 生成，它在构造时就按
        // 属性上下文转义（裸 `&` 会被 HTML 解析器当实体起头，参数名可被改掉）
        self::assertStringContainsString('?all=1&amp;run=a1a1a1a1a1a1a1a1&amp;source=xhprof_foo&amp;requrl=', $html);
        self::assertStringContainsString(
            'requrl=' . urlencode('http://example.com/<script>alert(1)</script>'),
            $html
        );
        self::assertStringNotContainsString('c3c3c3c3c3c3c3c3', $html);
        self::assertStringContainsString(date('Y-m-d H:i:s', 1700000000), $html);
    }

    #[Test]
    public function constructorUsesProvidedDirectory(): void
    {
        new XHProfRunsDefault('/custom/dir');
        self::assertSame('/custom/dir', XHProfRunsDefault::$dir);
    }

    #[Test]
    public function constructorFallsBackToTmpWhenIniUnset(): void
    {
        $ini = ini_get('xhprof.output_dir');
        new XHProfRunsDefault(null);
        self::assertSame($ini ?: '/tmp', XHProfRunsDefault::$dir);
        if (empty($ini)) {
            self::assertStringContainsString('Warning: Must specify directory', $this->logger->errors[0]);
        }
    }
}
