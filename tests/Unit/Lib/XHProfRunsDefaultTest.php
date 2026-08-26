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
    protected FakeCache $cache;
    protected FakeRequest $request;
    protected FakeResponse $response;
    protected FakeConfig $config;
    protected FakeLogger $logger;

    protected function setUp(): void
    {
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
        $runId = XHProfRunsDefault::save_run($data, 'xhprof_foo');

        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $runId);
        self::assertSame([$runId], $this->cache->lRange('xhprof:run_id', 0, -1));
        self::assertSame(1, $this->cache->get('xhprof:run_id_num'));

        $row = json_decode($this->cache->get('xhprof:request_log:' . $runId), true);
        self::assertSame('https://example.com/order', $row['request_uri']);
        self::assertSame('POST', $row['method']);
        self::assertSame(1.2346, $row['wt']);
        self::assertSame(0.002, $row['mu']);
        self::assertSame('1.2.3.4', $row['ip']);
        self::assertIsInt($row['create_time']);

        $stored = $this->cache->get('xhprof:xhprof_log:' . $runId);
        self::assertSame($data, unserialize($stored));
    }

    #[Test]
    public function checkLogNumTrimsOldestRunWhenOverLimit(): void
    {
        Xhprof::$log_num = 1;
        $this->cache->set('xhprof:run_id_num', 1);
        $this->cache->lPush('xhprof:run_id', 'oldrun0000000001');
        $this->cache->set('xhprof:request_log:oldrun0000000001', '{}');
        $this->cache->set('xhprof:xhprof_log:oldrun0000000001', serialize(['main()' => ['wt' => 1]]));

        $runId = XHProfRunsDefault::save_run($this->sampleData(), 'xhprof_foo');

        self::assertSame(1, $this->cache->get('xhprof:run_id_num'));
        self::assertNull($this->cache->get('xhprof:request_log:oldrun0000000001'));
        self::assertNull($this->cache->get('xhprof:xhprof_log:oldrun0000000001'));
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
        self::assertStringContainsString('?all=1&run=a1a1a1a1a1a1a1a1&source=xhprof_foo&requrl=', $html);
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
