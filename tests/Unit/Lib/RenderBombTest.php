<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Lib;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeCache;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeLogger;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use ErikWang2013\Xhprof\Tests\Support\XhprofStaticsSnapshot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 渲染炸弹探测器：**不是性能门禁**。
 *
 * 用 1 万函数 / 约 1.5 万条边的合成边表走一遍真实渲染（XhprofDisplay::displayXHProfReport
 * 的单 run 路径——不是直接调内部小函数），断言渲染完成且耗时 < 60s。它防的是**算法级**
 * 爆炸：某次改动把渲染管线里的某个 O(n) 变成 O(n²)，耗时会以数量级跳，而不是 10% 的
 * 抖动。夹具与静态量布置照抄 XhprofDisplayTest（读它，不改它）。
 *
 * 门限为什么给到 60s、以及怎么解读（实测于 PHP 8.5.6，2026-10-03，本机）：
 *   - `xdebug.mode=off`：0.62s；
 *   - `xdebug.mode=profile`（本机默认）：同一渲染 11.9s（≈19×）——慢的是环境不是代码。
 * 60s 对关闭 xdebug 的 CI 是 ~100× 余量，对本机 profile 模式只剩 ~5×：机器更慢或
 * xdebug 开销更深时会越线，**越线时先看 xdebug.mode，那不是回归**。
 *
 * 2026-10-04 把上面这条结论**编码进阈值**：本机实测 profile 下同一渲染已到 61.3s
 * （并发负载下 139.7s；off 仍 ~1.95s 级），贴着 60s 反复触发「环境性红」——会无故
 * 变红的闸门会被忽略而不被调查，所以 xdebug 处于非 off 模式时门限 ×40（profile/
 * debug/coverage 都是「慢的是环境」；off 模式与 CI 各格不受影响，仍是 60s 全强度）。
 * 判据不因缩放失效：真 O(n²) 在 off 模式就 ~100× 越线，在缩放后的门限下要越线得慢到
 * 2500s——那是「小时级」的爆炸，不是 10% 的抖动，探测器仍然只看数量级。
 */
class RenderBombTest extends TestCase
{
    use XhprofStaticsSnapshot;

    /** 夹具规模：1 万函数 + 5 千条函数间边（main() 的 1 万条边由函数数决定） */
    private const FUNCTIONS = 10000;
    private const EXTRA_EDGES = 5000;

    /** 炸弹门限（秒）。见类注释：profile 模式下计时不可比，只在 CI / xdebug 关闭下解读 */
    private const BOMB_SECONDS = 60.0;

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
        $this->request = new FakeRequest([], ['uri' => '/xhprof', 'url' => 'http://xhprof.local/xhprof']);
        $this->response = new FakeResponse();
        $this->config = new FakeConfig([]);
        $this->logger = new FakeLogger();
        Xhprof::bootstrap($this->request, $this->response, $this->config, $this->cache, $this->logger);
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

        // 与 XhprofDisplayTest 同一组布置（渲染态经存取器写，两种进程模式下行为一致）
        XhprofDisplay::set_render_state([
            'sort_col' => 'wt',
            'diff_mode' => false,
            'display_calls' => true,
            'metrics' => null,
            'stats' => [],
            'pc_stats' => [],
            'totals' => 0,
            'totals_1' => 0,
            'totals_2' => 0,
        ]);
        XhprofDisplay::$vwbar = 'class="vwbar"';
        XhprofDisplay::$vbar = 'class="vbar"';
        XhprofDisplay::$vbbar = 'class="vbbar"';
        XhprofDisplay::$vrbar = 'class="vrbar"';
        XhprofDisplay::$vgbar = 'class="vgbar"';
    }

    protected function tearDown(): void
    {
        $this->restoreXhprofStatics($this->saved);
    }

    #[Test]
    public function tenThousandFunctionRunRendersUnderBombThreshold(): void
    {
        $runId = 'a1a1a1a1a1a1a1a1';
        $data = $this->bombRunData();
        // 夹具规模是这条探测器的全部价值：规模退化成小表后它还能通过，但不再是炸弹
        $this->assertSame(1 + self::FUNCTIONS + self::EXTRA_EDGES, count($data), '炸弹夹具规模退化');

        $this->cache->set('xhprof:xhprof_log:' . $runId, serialize($data));
        $this->cache->set('xhprof:request_log:' . $runId, json_encode([
            'request_uri' => 'http://example.com/ok',
            'method' => 'GET',
            'wt' => 0.5,
            'mu' => 1.0,
            'ip' => '8.8.8.8',
            'create_time' => 1700000000,
        ]));

        $start = microtime(true);
        $html = XhprofDisplay::displayXHProfReport(
            ['run' => $runId, 'all' => 1],
            'xhprof_foo',
            $runId,
            null,
            null,
            'wt',
            null,
            null
        );
        $elapsed = microtime(true) - $start;

        // 完成度：根、末尾函数都在、页面是全量体量（截断的半页会在这一步红，而不是被计时掩盖）
        $this->assertStringContainsString('main()', $html);
        $this->assertStringContainsString('fn' . (self::FUNCTIONS - 1) . '()', $html, '最后一个函数没渲染出来');
        $this->assertGreaterThan(1_000_000, strlen($html), '页面体量不像全量渲染（参考值 ≈3.9MB）');

        $this->assertLessThan($this->bombLimit(), $elapsed, sprintf(
            '渲染耗时 %.1fs 超过 %.0fs 炸弹门限（xdebug.mode=%s%s）。先看 xdebug.mode：非 off 模式下'
            . '同一渲染可慢一两个数量级（实测 profile 已到 61.3s，负载下 139.7s），越线先当环境问题'
            . '复测，再当渲染管线的算法回归查',
            $elapsed,
            $this->bombLimit(),
            (string) ini_get('xdebug.mode'),
            $this->bombLimit() > self::BOMB_SECONDS ? '，已按 ×40 环境缩放' : ''
        ));
    }

    /**
     * 本轮实际门限：xdebug 非 off（profile/debug/coverage/trace）时 ×40，见类注释。
     */
    private function bombLimit(): float
    {
        $mode = trim((string) ini_get('xdebug.mode'));

        return ($mode === '' || $mode === 'off') ? self::BOMB_SECONDS : self::BOMB_SECONDS * 40;
    }

    /**
     * 合成边表：main() -> fn0..fn9999 各一条，另加 5 千条确定性的函数间边
     * （fn{i} -> fn{(7i+1) mod 1万}）。不用随机数：复现与变异都要可复算。
     *
     * 数值保证每个符号自身耗时非负：父边 wt=2000、子边 wt=1000，每个 fn 至多一条出边。
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
