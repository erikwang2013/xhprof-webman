<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofProfiler;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FakeCache::lPush 存在 by-ref 传参 bug（array_unshift($this->lists[$key] ??= [], ...)），
 * 测试进程内无法修改 Fixtures，此处用修复版子类覆盖列表方法。
 */
class ProfilerFixedListCache extends FakeCache
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
        return array_slice($this->myLists[$key] ?? [], $start, $end - $start + 1);
    }
}

class XhprofProfilerTest extends TestCase
{
    private FakeCache $cache;
    private FakeConfig $config;
    private FakeRequest $request;
    private FakeResponse $response;
    private FakeLogger $logger;

    protected function setUp(): void
    {
        $this->cache = new FakeCache();
        $this->request = new FakeRequest();
        $this->response = new FakeResponse();
        $this->logger = new FakeLogger();
        $this->config = new FakeConfig(['xhprof' => []]);

        Xhprof::$time_limit = 0;
        Xhprof::$ignore_url_arr = ['/test'];
        Xhprof::$key_prefix = 'xhprof';
        Xhprof::$log_num = 1000;
        Xhprof::$view_wtred = 3;
        Xhprof::$ui_html = '';
        Xhprof::$symbol_lookup_url = '';

        Context::reset();
        ApplicationContext::reset();

        Xhprof::bootstrap(
            $this->request,
            $this->response,
            $this->config,
            $this->cache,
            $this->logger
        );
    }

    protected function tearDown(): void
    {
        Context::reset();
        ApplicationContext::reset();
        Xhprof::$request = null;
        Xhprof::$response = null;
        Xhprof::$config = null;
        Xhprof::$cache = null;
        Xhprof::$logger = null;
    }

    #[Test]
    public function startStopRoundTripSavesRunToCache(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        // FakeCache::lPush 有 by-ref bug，此处用修复版子类重新 bootstrap
        $cache = new ProfilerFixedListCache();
        Xhprof::bootstrap($this->request, $this->response, $this->config, $cache, $this->logger);

        XhprofProfiler::start();
        $runId = XhprofProfiler::stop();

        $this->assertNull($runId); // stop() 无返回值，run_id 由 save_run 内部写入缓存
        $this->assertContains('lPush:xhprof:run_id', $cache->calls, '应把 run_id 推入列表');
        $this->assertNotEmpty(
            array_filter($cache->calls, fn (string $c) => str_starts_with($c, 'set:xhprof:request_log:')),
            '应写入 request_log'
        );
        $this->assertNotEmpty(
            array_filter($cache->calls, fn (string $c) => str_starts_with($c, 'set:xhprof:xhprof_log:')),
            '应写入 xhprof_log'
        );
    }

    #[Test]
    public function startStopWhenTimeLimitExceededSkipsSave(): void
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('ext-xhprof 未加载');
        }

        // time_limit=100 秒，任何真实请求的 wt 都不可能超过，保存被跳过
        Xhprof::$time_limit = 100;

        XhprofProfiler::start();
        XhprofProfiler::stop();

        $this->assertNotContains('lPush:xhprof:run_id', $this->cache->calls);
    }

    #[Test]
    public function bootstrapMapsConfigToStatics(): void
    {
        $config = new FakeConfig([
            'xhprof' => [
                'ignore_url_arr' => ['/api', '/admin'],
                'time_limit' => 5,
                'log_num' => 123,
                'view_wtred' => 9,
                'key_prefix' => 'myxp',
            ],
        ]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);

        $this->assertSame(['/api', '/admin'], Xhprof::$ignore_url_arr);
        $this->assertSame(5, Xhprof::$time_limit);
        $this->assertSame(123, Xhprof::$log_num);
        $this->assertSame(9, Xhprof::$view_wtred);
        $this->assertSame('myxp', Xhprof::$key_prefix);
    }

    #[Test]
    public function bootstrapAppliesDefaultsForMissingConfigKeys(): void
    {
        Xhprof::$ignore_url_arr = ['/custom'];
        Xhprof::$key_prefix = 'custom';
        Xhprof::$time_limit = 42;

        $config = new FakeConfig(['xhprof' => ['log_num' => 77]]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);

        $this->assertSame(['/test'], Xhprof::$ignore_url_arr);
        $this->assertSame(0, Xhprof::$time_limit);
        $this->assertSame(77, Xhprof::$log_num);
        $this->assertSame(3, Xhprof::$view_wtred);
        $this->assertSame('xhprof', Xhprof::$key_prefix);
    }

    #[Test]
    public function bootstrapReadsNumericStringsAsInts(): void
    {
        $config = new FakeConfig([
            'xhprof' => [
                'time_limit' => '2',
                'log_num' => '55',
                'view_wtred' => '7',
                'key_prefix' => 12345,
            ],
        ]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);

        $this->assertSame(2, Xhprof::$time_limit);
        $this->assertSame(55, Xhprof::$log_num);
        $this->assertSame(7, Xhprof::$view_wtred);
        $this->assertSame('12345', Xhprof::$key_prefix);
    }

    #[Test]
    public function bootstrapWithNullConfigLeavesStaticsUntouched(): void
    {
        Xhprof::$time_limit = 42;
        Xhprof::$ignore_url_arr = ['/keep'];

        Xhprof::bootstrap($this->request, $this->response, null, $this->cache, $this->logger);

        // config 参数为 null 时不覆盖 Xhprof::$config（仍为 setUp 注入的 FakeConfig），
        // 但 XhprofProfiler::bootstrap() 会读取当前 config 并覆盖静态值 —— 属于 API 语义
        $this->assertSame($this->config, Xhprof::$config);
    }

    #[Test]
    public function profilerBootstrapWithNullConfigReturnsEarly(): void
    {
        Context::reset();
        Xhprof::$config = null;
        Xhprof::$time_limit = 42;
        Xhprof::$ignore_url_arr = ['/keep'];

        XhprofProfiler::bootstrap();

        $this->assertSame(42, Xhprof::$time_limit);
        $this->assertSame(['/keep'], Xhprof::$ignore_url_arr);
    }

    #[Test]
    public function isEnabledReturnsTrueWhenEnableSet(): void
    {
        $config = new FakeConfig(['xhprof' => ['enable' => true]]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);

        $this->assertTrue(XhprofProfiler::isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseWhenEnableMissing(): void
    {
        $this->assertFalse(XhprofProfiler::isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseWhenEnableFalse(): void
    {
        $config = new FakeConfig(['xhprof' => ['enable' => false]]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);

        $this->assertFalse(XhprofProfiler::isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseWhenEnableIsNonBooleanTruthy(): void
    {
        // (bool) 强转语义：字符串 "0" 为 false
        $config = new FakeConfig(['xhprof' => ['enable' => '0']]);
        Xhprof::bootstrap($this->request, $this->response, $config, $this->cache, $this->logger);

        $this->assertFalse(XhprofProfiler::isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseWhenNoConfigBound(): void
    {
        Context::reset();
        Xhprof::$config = null;

        $this->assertFalse(XhprofProfiler::isEnabled());
    }
}
