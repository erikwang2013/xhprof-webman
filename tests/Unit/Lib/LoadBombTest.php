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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 落库炸弹探测器：**不是性能门禁**，是 RenderBombTest 的姊妹——那条盖渲染路径，
 * 这条盖采样数据的**落库**路径（`XHProfRunsDefault::save_run()`：serialize 整张
 * 边表 → 两次 set → 列表收敛）。这条路径跑在**每个被采样的请求**上，比渲染更热。
 *
 * 用与 RenderBombTest 同规模的合成边表（1 万函数 / 约 1.5 万条边）走一遍真实的
 * save_run，断言落库完成且耗时 < 炸弹门限。它防的是**算法级**爆炸：某次改动把落库
 * 管线里的某个 O(n) 变成 O(n²)，耗时会以数量级跳，而不是 10% 的抖动。
 *
 * 门限为什么给到 2s（实测于 PHP 8.5.6，2026-10-04，本机——同一台机器上 7 次写取
 * min/median/max，另有单次实测的 coverage / develop 两档）：
 *   - `xdebug.mode=off`：0.011 / 0.014 / 0.041s；coverage 0.013s；develop 0.021s；
 *   - `xdebug.mode=profile`（本机默认）：0.005 / 0.006 / 0.011s——**不比 off 慢**，
 *     与 RenderBombTest 那边 profile 慢 19× 相反：这里计时的主体（serialize /
 *     json_encode）是 C 实现，xdebug 只插桩 PHP 层，插不到它；渲染路径反之。
 *   - O(n²) 参照：同样 1.5 万项、把 `serialize($data)` 换成 array_merge 累积器
 *     （教科书式二次累积，序列化结果字节相同）——独立跑 4 次 2.79 / 3.35 / 4.08 /
 *     5.39s（内存分配器抖动，波动不小），装进本用例（/tmp 变异副本 + 前置自动加载器）
 *     两次实测 3.01 / 6.14s。
 * 2s 因此是：最慢一次实测基线（0.041s）的 ~49× 余量，二次参照**最快**一次（2.79s）
 * 之下——两种环境的计时都会抖动，取两边都留得下余量的中段值。
 * 结论只在 CI 或 `php -d xdebug.mode=off` 下解读；越线先排除机器负载，再当落库
 * 管线的算法回归查。夹具与静态量布置照抄 XHProfRunsDefaultTest（读它，不改它）。
 */
class LoadBombTest extends TestCase
{
    use XhprofStaticsSnapshot;

    /** 夹具规模：1 万函数 + 5 千条函数间边（main() 的 1 万条边由函数数决定） */
    private const FUNCTIONS = 10000;
    private const EXTRA_EDGES = 5000;

    /** 炸弹门限（秒）。依据见类注释：实测 0.041s 最慢，二次参照 5.39s */
    private const BOMB_SECONDS = 2.0;

    /** setUp 开工前的静态量快照（tearDown 原样放回） */
    private array $saved = [];

    protected FakeCache $cache;
    protected FakeRequest $request;
    protected FakeResponse $response;
    protected FakeConfig $config;
    protected FakeLogger $logger;

    protected function setUp(): void
    {
        $this->saved = $this->snapshotXhprofStatics();

        $this->cache = new FakeCache();
        $this->request = new FakeRequest([], ['uri' => '/order', 'url' => 'http://xhprof.local/order']);
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
        Xhprof::$symbol_lookup_url = '';
    }

    protected function tearDown(): void
    {
        $this->restoreXhprofStatics($this->saved);
    }

    #[Test]
    public function tenThousandFunctionRunLoadsUnderBombThreshold(): void
    {
        $data = $this->bombRunData();
        // 夹具规模是这条探测器的全部价值：规模退化成小表后它还能通过，但不再是炸弹
        $this->assertSame(1 + self::FUNCTIONS + self::EXTRA_EDGES, count($data), '炸弹夹具规模退化');

        $start = microtime(true);
        $runId = XHProfRunsDefault::save_run($data, 'xhprof_foo');
        $elapsed = microtime(true) - $start;

        // 完成度：先钉「真的全量落库了」——半截序列化/中途抛异常会在这里红，
        // 而不是被计时掩盖成「很快」。
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) $runId);
        $this->assertSame([$runId], $this->cache->lRange('xhprof:run_id', 0, -1), 'run_id 没进索引列表');
        $desc = null;
        $this->assertSame(
            $data,
            XHProfRunsDefault::get_run($runId, 'xhprof_foo', $desc),
            '落库的数据必须原样读回（序列化被截断/被改坏会在这里红）'
        );

        $this->assertLessThan(self::BOMB_SECONDS, $elapsed, sprintf(
            '落库耗时 %.2fs 超过 %.1fs 炸弹门限：先排除机器负载，再当 save_run 的算法回归查'
            . '（同类参照：把 serialize 换成二次累积，1.5 万项实测 2.8~5.4s）',
            $elapsed,
            self::BOMB_SECONDS
        ));
    }

    /**
     * 合成边表：main() -> fn0..fn9999 各一条，另加 5 千条确定性的函数间边
     * （fn{i} -> fn{(7i+1) mod 1万}）。不用随机数：复现与变异都要可复算。
     * 与 RenderBombTest::bombRunData() 同形（两侧刻意不共享：各测试类自有夹具，
     * 一边改规模不该悄悄改另一边）。
     *
     * @return array<string, array<string, int>>
     */
    private function bombRunData(): array
    {
        $data = ['main()' => [
            'ct' => 1,
            'wt' => (self::FUNCTIONS + self::EXTRA_EDGES) * 2000,
            'mu' => 1 << 24,
        ]];
        for ($i = 0; $i < self::FUNCTIONS; $i++) {
            $data["main()==>fn{$i}()"] = ['ct' => 1, 'wt' => 2000, 'mu' => 4096];
        }
        for ($i = 0; $i < self::EXTRA_EDGES; $i++) {
            $from = $i % self::FUNCTIONS;
            $to = ($i * 7 + 1) % self::FUNCTIONS;
            if ($to === $from) {
                $to = ($to + 1) % self::FUNCTIONS;
            }
            $data["fn{$from}()==>fn{$to}()"] = ['ct' => 1, 'wt' => 1000, 'mu' => 128];
        }

        return $data;
    }
}
