<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Lib;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\I18n\I18n;
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

        $this->cache = new FakeCache();
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

    /** 替换配置适配器时同步刷新 Hyperf Context（load-bearing：webhook_url / sample_cli 从这里读） */
    private function useXhprofConfig(array $xhprofConfig): void
    {
        $cfg = new FakeConfig(['xhprof' => $xhprofConfig]);
        Xhprof::$config = $cfg;
        if (class_exists(\Hyperf\Context\Context::class)) {
            \Hyperf\Context\Context::set('xhprof.config', $cfg);
        }
    }

    private function sampleData(): array
    {
        return [
            'main()' => ['wt' => 1234567, 'mu' => 2048],
            'main()==>foo()' => ['ct' => 1, 'wt' => 100, 'mu' => 512],
        ];
    }

    /** 把 main() 的总耗时设成 $seconds 秒（其余字段不变），用于跨过 view_wtred 阈值 */
    private function sampleDataWithWt(float $seconds): array
    {
        $data = $this->sampleData();
        $data['main()']['wt'] = (int) round($seconds * 1000000);
        return $data;
    }

    /** 起一个回环监听，返回 [server, port]（只收一次连接；调用方负责 fclose） */
    private function listenOnce(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, "起监听失败：$errstr");
        $name = (string) stream_socket_get_name($server, false);
        return [$server, (int) substr($name, (int) strrpos($name, ':') + 1)];
    }

    /** 从监听上收一条连接并读全「请求头 + 按 Content-Length 算的请求体」，超时即停 */
    private function readRequest($server, float $timeout = 3.0): string
    {
        $conn = stream_socket_accept($server, $timeout);
        self::assertNotFalse($conn, 'webhook 没有连上监听端口（fire-and-forget 也要先把请求发出去）');
        stream_set_timeout($conn, (int) $timeout);
        $data = '';
        while (true) {
            $chunk = fread($conn, 8192);
            if ($chunk === false || $chunk === '') {
                break;   // EOF 或超时
            }
            $data .= $chunk;
            if (str_contains($data, "\r\n\r\n")) {
                [$head, $body] = explode("\r\n\r\n", $data, 2);
                if (preg_match('/Content-Length:\s*(\d+)/i', $head, $m) === 1 && strlen($body) >= (int) $m[1]) {
                    break;   // 体已收全，不必等 FIN
                }
            }
        }
        fclose($conn);
        return $data;
    }

    /** 一个刚被释放、确定无人监听的回环端口（连它必得 ECONNREFUSED，且是立刻的） */
    private function closedLoopbackPort(): int
    {
        [$server, $port] = $this->listenOnce();
        fclose($server);
        return $port;
    }

    /** 保存并恢复 $_SERVER['argv']（CLI 合成 uri 要读它，测试进程的 argv[0] 是 phpunit 路径） */
    private function withArgv(?array $argv, callable $fn): void
    {
        $had = array_key_exists('argv', $_SERVER);
        $prev = $_SERVER['argv'] ?? null;
        if ($argv === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $argv;
        }
        try {
            $fn();
        } finally {
            if ($had) {
                $_SERVER['argv'] = $prev;
            } else {
                unset($_SERVER['argv']);
            }
        }
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
        // run_desc 现在取自词表（run.desc）：语言是进程级静态量、会随用例顺序变，
        // 这里显式钉死中文源再断言**字面量**——断言写成「sprintf(I18n::t(...))」会
        // 与生产代码互为镜像，词表缺这个键时两边一起错、照样绿。
        $prevLocale = I18n::locale();
        I18n::setLocale(I18n::FALLBACK);
        try {
            $data = $this->sampleData();
            $this->cache->set('xhprof:xhprof_log:a1b2c3d4e5f60718', serialize($data));
            $desc = null;
            $res = XHProfRunsDefault::get_run('a1b2c3d4e5f60718', 'xhprof_foo', $desc);
            self::assertSame($data, $res);
            self::assertSame('XHProf 运行（命名空间=xhprof_foo）', $desc);
        } finally {
            I18n::setLocale($prevLocale);
        }
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

    /**
     * 缓存值损坏（写了一半 / 被串键）→ 返回 false 并留一条日志，**不许有 warning 逃逸**。
     *
     * warning 漏到宿主上的两种下场都不可接受：升异常的宿主 = 一条坏记录把整页打成 500，
     * 不升的宿主 = 每次渲染一条没人看得懂的日志。这里把 warning 当场升成异常复现前者。
     */
    #[Test]
    public function getRunReturnsFalseForCorruptCacheValueWithoutWarningEscape(): void
    {
        // `a:1:{i:0;s:3:"trunc` —— 一个被截断的序列化数组
        $this->cache->set('xhprof:xhprof_log:a1b2c3d4e5f60718', 'a:1:{i:0;s:3:"trunc');

        // PHPUnit 跑测试时把 error_reporting() 压到 245（不含 E_WARNING），
        // 于是「按 error_reporting 判定的 handler」在测试进程里天然看不到 warning，
        // 会把这条测试变成永远绿的摆设。显式抬到 E_ALL，模拟严格宿主（Laravel 的
        // HandleExceptions::handleError、Symfony ErrorHandler 都是这个形状）。
        $previousReporting = error_reporting(E_ALL);
        set_error_handler(static function (int $errno, string $errstr): bool {
            // `@` 抑制期间 PHP 8 把 error_reporting() 收窄成 4437（不含 E_WARNING），
            // 按契约放过；`@` 之外的 warning 一律升异常。
            if (!(error_reporting() & $errno)) {
                return false;
            }
            throw new \ErrorException($errstr, 0, $errno);
        });
        try {
            $desc = null;
            $res = XHProfRunsDefault::get_run('a1b2c3d4e5f60718', 'xhprof_foo', $desc);
        } catch (\ErrorException $e) {
            $this->fail('unserialize 的 warning 从读路径漏出去了：' . $e->getMessage());
        } finally {
            restore_error_handler();
            error_reporting($previousReporting);
        }

        self::assertFalse($res, '损坏值必须优雅降级成 false，由上层渲染「数据已不存在」空态');
        self::assertStringContainsString(
            'unserialize failed for Run ID: a1b2c3d4e5f60718',
            $this->logger->errors[0] ?? '',
            '降级必须留一条可归因的日志'
        );
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
     * 超限部分必须**同一趟**全部收敛掉。
     *
     * 旧实现每条 save 只弹一条：push 一条再 pop 一条净变化为零，列表长度成了
     * 不动点——超限后永远回不到 log_num（审计记为「990 次写入才收敛」，实测比
     * 这还糟：一次都不收敛），多出来的行照常渲染、数据键照常驻留。
     */
    #[Test]
    public function checkLogNumConvergesToLimitInOneWrite(): void
    {
        Xhprof::$log_num = 3;
        // 灌到超限：10 条旧 run（头新尾旧），模拟 log_num 调小前攒下的列表
        for ($i = 1; $i <= 10; $i++) {
            $id = sprintf('%016x', $i);
            $this->cache->lPush('xhprof:run_id', $id);
            $this->cache->set('xhprof:request_log:' . $id, '{}');
            $this->cache->set('xhprof:xhprof_log:' . $id, serialize(['main()' => ['wt' => $i]]));
        }

        $runId = XHProfRunsDefault::save_run($this->sampleData(), 'xhprof_foo');

        // 仍在上限内的两条数据键原样在——多弹一条就是越界删了它们（先断言，
        // 越界时的报错才是「不许删」而不是被后面的列表长度差异盖过去）
        for ($i = 9; $i <= 10; $i++) {
            $id = sprintf('%016x', $i);
            self::assertNotNull($this->cache->get('xhprof:request_log:' . $id), "$id 仍在上限内，不许删");
            self::assertNotNull($this->cache->get('xhprof:xhprof_log:' . $id), "$id 仍在上限内，不许删");
        }
        // 一次写入后立即降到 log_num：列表只剩 新 run + 最新两条旧 run
        self::assertSame(
            [$runId, sprintf('%016x', 10), sprintf('%016x', 9)],
            $this->cache->lRange('xhprof:run_id', 0, -1),
            '列表长度必须一次收敛到 log_num，且只留最新的'
        );
        // 被裁的 8 条两种数据键全删
        for ($i = 1; $i <= 8; $i++) {
            $id = sprintf('%016x', $i);
            self::assertNull($this->cache->get('xhprof:request_log:' . $id), "$id 的 request_log 应被删除");
            self::assertNull($this->cache->get('xhprof:xhprof_log:' . $id), "$id 的 xhprof_log 应被删除");
        }
        self::assertNotNull($this->cache->get('xhprof:xhprof_log:' . $runId));
    }

    /**
     * log_num 调小后，下一次写入就把列表收敛到新上限——不靠后续请求慢慢磨。
     *
     * 现场：按老上限 1000 攒满列表，运维把 log_num 改成 10。旧实现要「再写 990
     * 次」才收敛（实测更糟：推一条弹一条互相抵消，永远停在 1000）。
     */
    #[Test]
    public function checkLogNumConvergesFullyWhenLimitIsLowered(): void
    {
        Xhprof::$log_num = 1000;
        for ($i = 0; $i < 1000; $i++) {
            XHProfRunsDefault::save_run($this->sampleData(), 'xhprof_foo');
        }
        $before = $this->cache->lRange('xhprof:run_id', 0, -1);
        self::assertCount(1000, $before, '夹具失效：应攒满 1000 条（每条写入都检查过，不该被裁）');

        Xhprof::$log_num = 10;
        $runId = XHProfRunsDefault::save_run($this->sampleData(), 'xhprof_foo');

        // 被裁的 991 条（$before 的第 9..999 位）两种数据键全清，留下的 9 条原样在
        // （先逐条断言数据键，越界/漏删的报错都直接点名第几条）
        foreach ($before as $i => $id) {
            $kept = $i < 9;
            self::assertSame(
                $kept,
                $this->cache->get('xhprof:request_log:' . $id) !== null,
                "第 $i 条" . ($kept ? '仍在上限内，数据键不许删' : '已被裁，数据键应删除')
            );
            self::assertSame(
                $kept,
                $this->cache->get('xhprof:xhprof_log:' . $id) !== null,
                "第 $i 条的 xhprof_log" . ($kept ? '不许删' : '应删除')
            );
        }
        self::assertSame(
            array_merge([$runId], array_slice($before, 0, 9)),
            $this->cache->lRange('xhprof:run_id', 0, -1),
            '一次写入后必须恰好收敛到新上限 10（新 run + 最新的 9 条）'
        );
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
        // 属性上下文转义（裸 `&` 会被 HTML 解析器当实体起头，参数名可被改掉）。
        // **不带 `all=1`**：点进任一 run 默认渲染「前 100 + 显示全部」，铺全量函数表
        // （1 万函数实测 6.5MB）是「显示全部」链接的职责，不该由列表页替用户决定。
        self::assertStringContainsString('?run=a1a1a1a1a1a1a1a1&amp;source=xhprof_foo&amp;requrl=', $html);
        self::assertStringNotContainsString('all=1', $html, '列表链接不得再带 all=1');
        self::assertStringContainsString(
            'requrl=' . urlencode('http://example.com/<script>alert(1)</script>'),
            $html
        );
        self::assertStringNotContainsString('c3c3c3c3c3c3c3c3', $html);
        self::assertStringContainsString(date('Y-m-d H:i:s', 1700000000), $html);

        // ---- 对比入口（复选框列 + 工具区按钮）----
        // 两行有效数据 ⇒ 两个行复选框，值是 run_id；被跳过的 c3 不许留下复选框
        self::assertSame(2, substr_count($html, 'class="xp-run-cb"'));
        self::assertStringContainsString('value="a1a1a1a1a1a1a1a1"', $html);
        self::assertStringContainsString('value="b2b2b2b2b2b2b2b2"', $html);
        // 入库时间跟着复选框走：JS 靠它在两条之间定 run1/run2 的先后（早的当基线）
        self::assertStringContainsString('data-create-time="1700000000"', $html);
        self::assertStringContainsString('data-create-time="1700000001"', $html);
        self::assertSame(1, substr_count($html, 'class="xp-run-all"'), '表头全选复选框只有一个');
        // 可及名称（读屏不把「一个复选框」当名字）
        self::assertStringContainsString('aria-label="' . I18n::plain('runs.selectRow') . '"', $html);
        self::assertStringContainsString('aria-label="' . I18n::plain('runs.selectAll') . '"', $html);
        // 工具区：按钮默认 disabled（没选够两条 / JS 没加载时都点不动），source 由 PHP 注入
        self::assertStringContainsString('id="xp-compare-btn"', $html);
        self::assertStringContainsString('data-source="xhprof_foo"', $html);
        self::assertMatchesRegularExpression(
            '/<button[^>]*id="xp-compare-btn"[^>]*disabled/',
            $html,
            '按钮必须默认禁用——静态渲染时（0 选中 / JS 未运行）不该可点'
        );
        self::assertStringContainsString(I18n::plain('runs.compare'), $html);
        self::assertStringContainsString(I18n::plain('runs.compareHint'), $html);
    }

    /**
     * 列表加复选框列后，DataTables 的 `columns` 与默认排序列索引必须跟着走。
     *
     * 两边是同一份契约的两个副本：表头由 list_runs() 渲染，列配置在
     * src/html/js/xhprof_report.js。不同步时页面**不报错**——只是默认排序落到别的
     * 列上、列配置错位，属于「看着怪但不红」的一类缺陷，所以在这里钉住。
     */
    #[Test]
    public function dataTablesColumnConfigStaysInSyncWithTheRenderedHeaders(): void
    {
        $html = XHProfRunsDefault::list_runs();
        preg_match('#<thead><tr>(.*?)</tr></thead>#s', $html, $h);
        self::assertNotEmpty($h, '表头没渲染出来，锚点失效');
        preg_match_all('#<th[ >]#', $h[1], $cells);
        self::assertGreaterThan(0, count($cells[0]), '表头一个 <th> 都没有，锚点失效');

        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/src/html/js/xhprof_report.js');
        preg_match('#"columns":\s*\[(.*?)\]#s', $js, $c);
        self::assertNotEmpty($c, 'JS 里找不到 columns 配置，锚点失效');
        // 逐条匹配列配置项（`null` 或单键对象），而不是数逗号：配置项将来内嵌逗号
        // （如再加 width）时逗号计数会**多**算、静默放行；这里会直接红。
        preg_match_all('#^\s*(?:\{\s*"orderable":\s*false\s*\}|null)\s*,?\s*$#m', $c[1], $cols);

        self::assertSame(
            count($cells[0]),
            count($cols[0]),
            '表头列数与 DataTables 列配置条目数不一致——加/删列时 HTML 与 JS 两边都要改'
        );

        preg_match('#"order":\s*\[\s*\[\s*(\d+)\s*,\s*"desc"\s*\]\s*\]#', $js, $o);
        self::assertNotEmpty($o, 'JS 里找不到默认排序配置，锚点失效');
        $orderIndex = (int) $o[1];
        // 默认按「请求时间」倒序，它是第 4 个 <th>（0 起算索引 3：复选框/方法/地址之后）
        self::assertSame(3, $orderIndex, '默认排序列索引没跟着复选框列右移（排序会落到别的列）');
        self::assertStringContainsString(
            'null',
            $cols[0][$orderIndex] ?? '',
            '默认排序列在列配置里必须可排序（null），指向复选框/方法这类列就是错的'
        );
    }

    /**
     * 「对比选中」的接线：JS 侧锚点 + HTML/JS 两侧的选择器必须成对。
     *
     * 按钮的三分支契约（与 src/html/js/xhprof_report.js 同批）：0/1 条禁用、
     * 恰好 2 条走 run1/run2 diff、>2 条按 create_time 升序把 id 拼进 run= 走聚合。
     *
     * PHPUnit 跑不了浏览器的行为，这里钉的是**锚点**：删掉接线、改掉选择器、
     * 或把「按 create_time 定先后」换回按行位置/点击顺序，都会在这里红
     * （与 XhprofDisplayTest 里钉搜索框 keydown 接线是同一手法）。
     * 真正的点击行为仍要人工在浏览器里过。
     *
     * 选择器漂移是这类功能最可能的静默死法：改名的只有一边，页面不报错、
     * 复选框就是没反应。所以两侧各钉一次。
     */
    #[Test]
    public function compareEntryWiringIsPresentAcrossMarkupAndScript(): void
    {
        // 有行才渲染行复选框，先种一条
        $this->cache->lPush('xhprof:run_id', 'a1a1a1a1a1a1a1a1');
        $this->cache->set('xhprof:request_log:a1a1a1a1a1a1a1a1', json_encode([
            'request_uri' => 'http://example.com/ok', 'method' => 'GET',
            'wt' => 0.5, 'mu' => 1.0, 'ip' => '8.8.8.8', 'create_time' => 1700000000,
        ]));
        $html = XHProfRunsDefault::list_runs();
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/src/html/js/xhprof_report.js');

        foreach ([
            'xp-run-cb' => 'input.xp-run-cb',
            'xp-run-all' => 'input.xp-run-all',
            'xp-compare-btn' => '#xp-compare-btn',
            'xp-compare-hint' => '.xp-compare-hint',
            'data-source' => "attr('data-source')",
            // 先后判定要用的第二个数据属性：PHP 不渲染 / JS 不读，各算一处断裂
            'data-create-time' => "getAttribute('data-create-time')",
        ] as $inHtml => $inJs) {
            self::assertStringContainsString($inHtml, $html, "列表 HTML 里没有 {$inHtml}（JS 的选择器会落空）");
            self::assertStringContainsString($inJs, $js, "JS 里没有 {$inJs}（HTML 上挂了也没人用）");
        }

        self::assertStringContainsString("input.xp-run-cb", $js, 'JS 里没有行复选框的接线点');
        self::assertStringContainsString("input.xp-run-all", $js, 'JS 里没有表头全选的处理');
        self::assertMatchesRegularExpression(
            '/on\(\s*[\'"]change[\'"]\s*,\s*[\'"]input\.xp-run-cb[\'"]/',
            $js,
            '行复选框的 change 事件没有接上'
        );
        self::assertMatchesRegularExpression(
            '/\$\([\'"]\#xp-compare-btn[\'"]\)/',
            $js,
            'JS 里没有对比按钮的接线点'
        );
        // 上游把「恰好两条才放行」改成了三分支：0/1 条禁用、2 条 diff、>2 条聚合。
        // 禁用条件必须与 2 比较（1 条也禁用），而不是与 0 比。
        self::assertMatchesRegularExpression(
            '/prop\(\s*[\'"]disabled[\'"]\s*,\s*n\s*<\s*2\s*\)/',
            $js,
            '按钮的启用条件不是「少于两条才禁用」'
        );
        // >2 条走聚合。分支边界必须是与 2 比较：改成 >3 时 3 条会掉进 diff 分支，
        // 第三条被静默丢掉（diff 只用 picks[0]/picks[1]）。
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*picks\.length\s*>\s*2\s*\)/',
            $js,
            '聚合分支的条数边界不是 >2'
        );
        // 聚合把 id 按 create_time **升序**拼进 run=（a.t - b.t；方向反了链接不稳定，
        // 同一秒入库的靠 Array.sort 的稳定性保持列表顺序）。
        self::assertMatchesRegularExpression(
            '/\.sort\(\s*function\s*\(\s*a\s*,\s*b\s*\)\s*\{\s*return\s+a\.t\s*-\s*b\.t;\s*\}\s*\)/',
            $js,
            '聚合的 id 没有按 create_time 升序排序（a.t - b.t）'
        );
        self::assertMatchesRegularExpression(
            '/\[[\'"]run[\'"]\]\s*=\s*ids\.join\(\s*[\'"],[\'"]\s*\)/',
            $js,
            '聚合没有把 id 用逗号拼进 run='
        );
        // 聚合分支必须清掉 run1/run2：从对比页带着旧参数点过来时，
        // 留着它们会盖过 run=，落到错误的报告页。
        self::assertMatchesRegularExpression(
            '/delete\s+cur_params\[[\'"]run1[\'"]\]/',
            $js,
            '聚合分支没有清掉 run1（会盖过 run=）'
        );
        self::assertMatchesRegularExpression(
            '/delete\s+cur_params\[[\'"]run2[\'"]\]/',
            $js,
            '聚合分支没有清掉 run2（会盖过 run=）'
        );
        // 收集取**列表顺序**（rows({order:'index'}) = 原始数据顺序），不是当前排序/点击顺序；
        // 它同时是同一秒入库两条的分先后依据（见下面相等也反转的断言）。
        self::assertMatchesRegularExpression(
            '/rows\(\{\s*order:\s*[\'"]index[\'"]\s*\}\)/',
            $js,
            '选中项没有按列表顺序取（默认排序下会与看到的行序不一致）'
        );
        // run1 必须是**时间早的那条**（基线）、run2 是之后：diff 的 delta = run2 − run1，
        // 负值（变快）才显示成绿色（XhprofDisplay::get_print_class 的注释）。
        // 方向反了，每一次改善都会显示成红色回归。
        self::assertMatchesRegularExpression(
            '/picks\[0\]\.t\s*>=\s*picks\[1\]\.t\s*\)\s*picks\.reverse\(\)/',
            $js,
            'run1/run2 没有按 create_time 定先后（相等时也要反转：同一秒里列表靠后那条更早）'
        );
        self::assertMatchesRegularExpression(
            '/\[[\'"]run1[\'"]\]\s*=\s*picks\[0\]\.id/',
            $js,
            'run1 不是取时间更早的那条'
        );
        self::assertMatchesRegularExpression(
            '/\[[\'"]run2[\'"]\]\s*=\s*picks\[1\]\.id/',
            $js,
            'run2 不是取时间更晚的那条'
        );
        // 「恰好两条」分支必须清掉可能残留的 run=（与聚合分支清 run1/run2 对称）：
        // 从聚合页带着 run=a,b,c 过来再选两条时，run 分支在派发时排在 run1/run2 前面，
        // 不清就会把「恰好两条」当成聚合页。
        self::assertMatchesRegularExpression(
            '/delete\s+cur_params\[[\'"]run[\'"]\]/',
            $js,
            'diff 分支没有清掉残留的 run=（会把两条误派发成聚合）'
        );
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

    // ------------------------------------------------------------------
    // 慢请求告警 + webhook
    // ------------------------------------------------------------------

    #[Test]
    public function saveRunAlertsOnSlowRequestAfterPersisting(): void
    {
        Xhprof::$view_wtred = 3;
        $runId = XHProfRunsDefault::save_run($this->sampleDataWithWt(5.0), 'xhprof_foo');

        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $runId);
        // 告警带 run_id 与 uri：凭它就能翻到具体那条 run
        self::assertStringContainsString('xhprof: slow request', $this->logger->errors[0] ?? '');
        self::assertStringContainsString('wt=5.0000s', $this->logger->errors[0] ?? '');
        self::assertStringContainsString('uri=localhost/order', $this->logger->errors[0] ?? '');
        self::assertStringContainsString('run_id=' . $runId, $this->logger->errors[0] ?? '');
        // 「有告警必有 run 可看」：告警对应的那条数据真的在
        self::assertNotNull($this->cache->get('xhprof:xhprof_log:' . $runId));
        self::assertNotNull($this->cache->get('xhprof:request_log:' . $runId));
    }

    #[Test]
    public function saveRunDoesNotAlertBelowThreshold(): void
    {
        Xhprof::$view_wtred = 3;
        $runId = XHProfRunsDefault::save_run($this->sampleDataWithWt(2.9999), 'xhprof_foo');

        self::assertNotNull($this->cache->get('xhprof:xhprof_log:' . $runId), 'run 照常落库');
        self::assertSame([], $this->logger->errors, '低于阈值一条告警都不该有');
    }

    #[Test]
    public function saveRunAlertsExactlyAtThreshold(): void
    {
        // 阈值判定是 >=（需求原文），恰好等于 view_wtred 也要告警
        Xhprof::$view_wtred = 3;
        XHProfRunsDefault::save_run($this->sampleDataWithWt(3.0), 'xhprof_foo');
        self::assertStringContainsString('xhprof: slow request', $this->logger->errors[0] ?? '');
    }

    #[Test]
    public function saveRunDoesNotAlertWhenNoWallTimeCollected(): void
    {
        // view_wtred=0 时「>= 0」对空 run 也成立，必须靠 wt > 0 守卫区分
        Xhprof::$view_wtred = 0;
        $data = ['main()' => ['wt' => 0, 'mu' => 0]];
        $runId = XHProfRunsDefault::save_run($data, 'xhprof_foo');

        self::assertNotNull($this->cache->get('xhprof:xhprof_log:' . $runId), '空 run 照常落库');
        self::assertSame([], $this->logger->errors, '没采到 wt 不该告警');
    }

    #[Test]
    public function saveRunDoesNotAlertWhenRequestIsNotPersisted(): void
    {
        // time_limit / ignore_url_arr 的过滤都发生在告警之前：没有 run，就没有告警
        Xhprof::$view_wtred = 3;
        Xhprof::$ignore_url_arr = ['/xhprof'];
        $this->useRequest(new FakeRequest([], ['uri' => '/xhprof']));

        self::assertFalse(XHProfRunsDefault::save_run($this->sampleDataWithWt(9.0), 'xhprof_foo'));
        self::assertSame([], $this->cache->calls);
        self::assertSame([], $this->logger->errors, '被过滤的请求不该发告警');
    }

    #[Test]
    public function webhookPostsJsonPayloadForSlowRun(): void
    {
        [$server, $port] = $this->listenOnce();
        try {
            $this->useXhprofConfig(['webhook_url' => "http://127.0.0.1:$port/hook?x=1"]);
            $this->useRequest(new FakeRequest([], [
                'method' => 'POST',
                'uri' => '/order',
                'host' => 'example.com',
                'ip' => '1.2.3.4',
                'headers' => ['x-forwarded-proto' => 'https'],
            ]));
            $data = $this->sampleDataWithWt(5.0);
            $data['main()']['ct'] = 3;   // payload 里的 ct 取自 main()['ct']

            $runId = XHProfRunsDefault::save_run($data, 'xhprof_foo');
            $wire = $this->readRequest($server);
        } finally {
            fclose($server);
        }

        self::assertStringStartsWith('POST /hook?x=1 HTTP/1.1', $wire);
        self::assertStringContainsString('Content-Type: application/json', $wire);
        self::assertStringContainsString('Host: 127.0.0.1', $wire);

        [$head, $body] = explode("\r\n\r\n", $wire, 2);
        self::assertMatchesRegularExpression('/Content-Length: ' . strlen($body) . '\r\n/', $head, 'Content-Length 必须等于实际体长');
        $payload = json_decode($body, true);
        self::assertSame($runId, $payload['run_id']);
        self::assertSame('https://example.com/order', $payload['uri']);
        // json_encode(5.0) 是 "5"（整数形态），解码回来是 int；断言数值本身
        self::assertEqualsWithDelta(5.0, $payload['wt'], 1e-9);
        self::assertSame(3, $payload['ct']);
        self::assertSame('1.2.3.4', $payload['ip']);
        $row = json_decode($this->cache->get('xhprof:request_log:' . $runId), true);
        self::assertSame($row['create_time'], $payload['time']);
    }

    #[Test]
    public function webhookIsOffByDefault(): void
    {
        // 不配 / 配 null / 配空串三种「关」的形态：只发告警，不发 webhook
        foreach ([[], ['webhook_url' => null], ['webhook_url' => '']] as $xhprofConfig) {
            $this->useXhprofConfig($xhprofConfig);
            $this->logger->errors = [];
            XHProfRunsDefault::save_run($this->sampleDataWithWt(5.0), 'xhprof_foo');
            self::assertStringContainsString('xhprof: slow request', $this->logger->errors[0] ?? '');
            foreach ($this->logger->errors as $line) {
                self::assertStringNotContainsString('webhook', $line, '没配 webhook_url 时不该有任何 webhook 记录');
            }
        }
    }

    #[Test]
    public function webhookFiresOnlyForSlowRuns(): void
    {
        // 指向一个确定无人监听的端口：一旦发射就会连接失败并留日志（connect 失败在
        // 同文件的 webhookDeliveryFailureIsSwallowed 里被证明会记日志），所以
        // 「日志里没有 webhook」= 真的没有发射。
        $port = $this->closedLoopbackPort();
        $this->useXhprofConfig(['webhook_url' => "http://127.0.0.1:$port/hook"]);
        Xhprof::$view_wtred = 3;

        $runId = XHProfRunsDefault::save_run($this->sampleDataWithWt(1.2), 'xhprof_foo');

        self::assertNotNull($this->cache->get('xhprof:xhprof_log:' . $runId));
        self::assertSame([], $this->logger->errors, '快请求不该发 webhook（也不该有告警）');
    }

    #[Test]
    public function webhookDeliveryFailureIsSwallowed(): void
    {
        // fire-and-forget 的边界：连不上只留一条日志，落库与返回值不受影响、不抛异常
        $port = $this->closedLoopbackPort();
        $this->useXhprofConfig(['webhook_url' => "http://127.0.0.1:$port/hook"]);
        Xhprof::$view_wtred = 3;

        $runId = XHProfRunsDefault::save_run($this->sampleDataWithWt(5.0), 'xhprof_foo');

        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $runId, 'webhook 发不出去不能影响落库');
        self::assertNotNull($this->cache->get('xhprof:xhprof_log:' . $runId));
        self::assertStringContainsString('xhprof: slow request', $this->logger->errors[0] ?? '');
        self::assertStringContainsString('webhook', $this->logger->errors[1] ?? '', '投递失败要留一条可归因的日志');
    }

    // ------------------------------------------------------------------
    // 列表概览 / 同 URL 的上次运行
    // ------------------------------------------------------------------

    /** 往索引列表塞一条 run（lPush 是头插：按**反向**调用才能得到想要的列表顺序） */
    private function seedRunRow(string $runId, string $uri, int $createTime): void
    {
        $this->cache->lPush('xhprof:run_id', $runId);
        $this->cache->set('xhprof:request_log:' . $runId, json_encode([
            'request_uri' => $uri,
            'method' => 'GET',
            'wt' => 1.0,
            'mu' => 1.0,
            'ip' => '1.1.1.1',
            'create_time' => $createTime,
        ]));
    }

    #[Test]
    public function runsOverviewReturnsFixedShapeOnEmptyIndex(): void
    {
        Xhprof::$log_num = 7;
        Xhprof::$log_ttl = 1234;

        self::assertSame(
            ['count' => 0, 'limit' => 7, 'oldest' => null, 'newest' => null, 'ttl' => 1234],
            XHProfRunsDefault::runsOverview()
        );
    }

    #[Test]
    public function runsOverviewCountsIndexLengthAndTakesTimesFromRows(): void
    {
        Xhprof::$log_num = 7;
        Xhprof::$log_ttl = 1234;
        // 想要的列表顺序（头新尾旧）：A(300)、B(100)、C(200)、D(悬空)。反向种入。
        $this->seedRunRow('d4d4d4d4d4d4d4d4', 'http://x/dangling', 250);  // 只推指针，不写 request_log
        $this->cache->del('xhprof:request_log:d4d4d4d4d4d4d4d4');
        $this->seedRunRow('c3c3c3c3c3c3c3c3', 'http://x/c', 200);
        $this->seedRunRow('b2b2b2b2b2b2b2b2', 'http://x/b', 100);
        $this->seedRunRow('a1a1a1a1a1a1a1a1', 'http://x/a', 300);

        self::assertSame(
            // count 是索引列表长度（含悬空指针）；oldest/newest 只来自 mget 到的 create_time，
            // 且与列表头/尾无关（头 A=300 最大、尾 D 悬空）
            ['count' => 4, 'limit' => 7, 'oldest' => 100, 'newest' => 300, 'ttl' => 1234],
            XHProfRunsDefault::runsOverview()
        );
    }

    #[Test]
    public function findPreviousRunForUriReturnsMostRecentEarlierRunWithSameUri(): void
    {
        // 列表顺序（头新尾旧）：P(X,500)、Q(X,300)、R(Y,400)、S(X,100)。反向种入。
        $this->seedRunRow('ddd0000000000004', 'http://x/target', 100);   // S
        $this->seedRunRow('ccc0000000000003', 'http://x/other', 400);    // R
        $this->seedRunRow('bbb0000000000002', 'http://x/target', 300);   // Q
        $this->seedRunRow('aaa0000000000001', 'http://x/target', 500);   // P

        // 最近的一条：P 不早于 500 被跳过，Q 命中
        self::assertSame('bbb0000000000002', XHProfRunsDefault::findPreviousRunForUri('http://x/target', 500));
        // 严格小于：Q 恰好等于边界不算「之前」
        self::assertSame('ddd0000000000004', XHProfRunsDefault::findPreviousRunForUri('http://x/target', 300));
        // 换 uri 只认逐字相同的那条
        self::assertSame('ccc0000000000003', XHProfRunsDefault::findPreviousRunForUri('http://x/other', 500));
        // 没有更早的 / 没有同 uri 的
        self::assertNull(XHProfRunsDefault::findPreviousRunForUri('http://x/target', 50));
        self::assertNull(XHProfRunsDefault::findPreviousRunForUri('http://x/target ', 500), 'uri 比较是逐字，尾巴多一个空格都不算');
        self::assertNull(XHProfRunsDefault::findPreviousRunForUri('http://x/none', 500));
    }

    #[Test]
    public function findPreviousRunForUriSkipsDanglingAndInvalidEntries(): void
    {
        self::assertNull(XHProfRunsDefault::findPreviousRunForUri('http://x/target', 1000), '空列表返回 null');

        // 列表：非法 id、悬空指针、命中项。坏条目一律跳过，不挡后面那条
        $this->seedRunRow('aaa0000000000001', 'http://x/target', 300);   // 命中项（尾）
        $this->cache->lPush('xhprof:run_id', 'd4d4d4d4d4d4d4d4');        // 悬空（无 request_log）
        $this->cache->lPush('xhprof:run_id', 'NOTHEXRUNID');             // 非法 id

        self::assertSame('aaa0000000000001', XHProfRunsDefault::findPreviousRunForUri('http://x/target', 1000));
    }

    // ------------------------------------------------------------------
    // CLI 采样（sample_cli）
    // ------------------------------------------------------------------

    #[Test]
    public function saveRunStoresSyntheticCliUriWhenSampleCliEnabled(): void
    {
        $this->useXhprofConfig(['sample_cli' => true]);
        $this->useRequest(new FakeRequest([], ['uri' => '']));

        $this->withArgv(['/srv/app/queue-worker.php'], function (): void {
            $runId = XHProfRunsDefault::save_run($this->sampleData(), 'xhprof_foo');
            $row = json_decode($this->cache->get('xhprof:request_log:' . $runId), true);
            self::assertSame('cli:queue-worker.php', $row['request_uri'], '落库的 request_uri 要让列表页看得出是 CLI');
            // 同一脚本的下次运行能按这条合成 uri 找回上次（集成口径，与 Display 的调用同形）
            self::assertSame(
                $runId,
                XHProfRunsDefault::findPreviousRunForUri('cli:queue-worker.php', (int) $row['create_time'] + 1)
            );
        });

        // 拿不到 argv（register_argc_argv 关闭等）时退化成 'cli:unknown'，不能是 'cli:'
        $this->withArgv(null, function (): void {
            $runId = XHProfRunsDefault::save_run($this->sampleData(), 'xhprof_foo');
            $row = json_decode($this->cache->get('xhprof:request_log:' . $runId), true);
            self::assertSame('cli:unknown', $row['request_uri']);
        });
    }

    #[Test]
    public function saveRunWithoutSampleCliKeepsIgnoringEmptyUriRequests(): void
    {
        // 默认（键不存在）与显式 false 都必须逐字保持旧行为：空 URI → 不落库
        foreach ([[], ['sample_cli' => false]] as $xhprofConfig) {
            $this->useXhprofConfig($xhprofConfig);
            $this->useRequest(new FakeRequest([], ['uri' => '']));
            $this->cache->reset();
            self::assertFalse(
                XHProfRunsDefault::save_run($this->sampleData(), 'xhprof_foo'),
                'sample_cli 关闭时空 URI 请求一律不采样'
            );
            self::assertSame([], $this->cache->calls);
        }
    }

    #[Test]
    public function saveRunWithSampleCliStillUsesHttpUriAndIgnoreList(): void
    {
        // 非空 URI 走原路：ignore_url_arr 依旧生效，request_uri 仍是 host+uri
        $this->useXhprofConfig(['sample_cli' => true]);
        Xhprof::$ignore_url_arr = ['/xhprof'];
        $this->useRequest(new FakeRequest([], ['uri' => '/xhprof/ignored']));
        self::assertFalse(XHProfRunsDefault::save_run($this->sampleData(), 'xhprof_foo'));

        $this->useRequest(new FakeRequest([], ['uri' => '/order', 'host' => 'example.com']));
        $runId = XHProfRunsDefault::save_run($this->sampleData(), 'xhprof_foo');
        $row = json_decode($this->cache->get('xhprof:request_log:' . $runId), true);
        self::assertSame('example.com/order', $row['request_uri'], '有 URI 时不做任何合成');
    }
}
