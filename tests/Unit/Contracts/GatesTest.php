<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 契约环三道硬闸门的**负路径**单测（tools/contracts/lib/gates.php）。
 *
 * 为什么需要：run.php 的三道闸门（腿身份 exit 2、SKIP 冻结数、FAIL 汇总）此前只有正路径
 * 跑过 —— 环红过是因为有 case 真红，不是因为闸门被喂过坏输入。本仓库的规矩是「检查必须
 * 被证明会红」，所以判定逻辑搬进 lib/gates.php 后，这里对每种坏输入断言它的判决。
 *
 * 不依赖 Redis / xhprof / 任何 vendor：gates.php 是纯判定（唯一 IO 是读 composer.lock，
 * 这里用真临时文件喂它 —— 顺带把「没有 lock」「lock 不是 JSON」两条也测了）。
 *
 * 覆盖边界（诚实标注）：这里钉的是**判定**。run.php 把判定接进去的**调用点**只能靠一条
 * 源码级 tripwire（见 runPhpIsWiredToTheGateFunctions）—— 跑 run.php 需要整环环境
 * （Redis + 已装 vendor 两腿），不在单测范围内。
 */
class GatesTest extends TestCase
{
    /** @var list<string> 本用例建过的临时腿目录，收尾时删掉 */
    private static array $tmpDirs = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/contracts/lib/gates.php';
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$tmpDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        self::$tmpDirs = [];
    }

    private static function tempLeg(?string $lockContents): string
    {
        $dir = sys_get_temp_dir() . '/xhprof-gates-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        self::$tmpDirs[] = $dir;
        if ($lockContents !== null) {
            file_put_contents($dir . '/composer.lock', $lockContents);
        }

        return $dir;
    }

    /** @param array<string, string> $packages name => version */
    private static function lockWith(array $packages): string
    {
        $out = [];
        foreach ($packages as $name => $version) {
            $out[] = ['name' => $name, 'version' => $version];
        }

        return (string) json_encode(['packages' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, string> 6.4 腿的三条期望，与 run.php 的 LEGS 同值 */
    private static function symfony64Expect(): array
    {
        return [
            'symfony/event-dispatcher' => '6.4.',
            'symfony/http-foundation' => '6.4.',
            'symfony/http-kernel' => '6.4.',
        ];
    }

    // ================= 闸门 3：腿身份核对（run.php 走它 exit 2） =================

    #[Test]
    public function identityPassesWhenEveryPinnedPackageMatchesItsPrefix(): void
    {
        $dir = self::tempLeg(self::lockWith([
            'symfony/event-dispatcher' => '6.4.12',
            // lock 里两种写法都存在（composer 对 dev 分支去 v、对 tag 留 v），
            // ltrim($version, 'v') 的归一化就是为它存在 —— 删掉它这里必须红。
            'symfony/http-foundation' => 'v6.4.3',
            'symfony/http-kernel' => '6.4.2',
            'unrelated/package' => '9.9.9',
        ]));

        $this->assertNull(contracts_leg_identity_error('symfony64', $dir, self::symfony64Expect()));
    }

    #[Test]
    public function identityRejectsWrongVersions(): void
    {
        // 两个判别性输入，各钉一个决定：
        //  - 7.4.0：第二腿的 vendor 被换成 7.x（「悄悄变成主腿副本」的失效模式）；
        //  - 6.40.1：期望前缀刻意是 "6.4."（带点）而不是 "6.4" —— 后者会放过 6.40。
        $dir = self::tempLeg(self::lockWith([
            'symfony/event-dispatcher' => '6.4.12',
            'symfony/http-foundation' => '7.4.0',
            'symfony/http-kernel' => '6.40.1',
        ]));

        $error = contracts_leg_identity_error('symfony64', $dir, self::symfony64Expect());

        $this->assertIsString($error);
        $this->assertStringContainsString('腿 symfony64 的版本身份不对', $error);
        $this->assertStringContainsString('symfony/http-foundation 是 7.4.0，期望 6.4.x', $error);
        $this->assertStringContainsString('symfony/http-kernel 是 6.40.1，期望 6.4.x', $error);
        // 没问题的包不许出现在问题清单里（否则「哪条腿有问题」就不可读了）
        $this->assertStringNotContainsString('event-dispatcher', $error);
    }

    #[Test]
    public function identityRejectsMissingPackage(): void
    {
        $dir = self::tempLeg(self::lockWith(['symfony/event-dispatcher' => '6.4.12']));

        $error = contracts_leg_identity_error('symfony64', $dir, self::symfony64Expect());

        $this->assertIsString($error);
        $this->assertStringContainsString('symfony/http-foundation 不在 lock 里', $error);
        $this->assertStringContainsString('symfony/http-kernel 不在 lock 里', $error);
    }

    #[Test]
    public function identityRejectsMissingLock(): void
    {
        $dir = self::tempLeg(null);

        $error = contracts_leg_identity_error('symfony64', $dir, self::symfony64Expect());

        $this->assertIsString($error);
        $this->assertStringContainsString('没有 composer.lock', $error);
    }

    #[Test]
    public function identityRejectsUnparseableLock(): void
    {
        $this->assertStringContainsString(
            '不是合法 JSON 或没有 packages',
            (string) contracts_leg_identity_error('symfony64', self::tempLeg('{not json'), self::symfony64Expect())
        );
        $this->assertStringContainsString(
            '不是合法 JSON 或没有 packages',
            (string) contracts_leg_identity_error('symfony64', self::tempLeg('{"packages": "oops"}'), self::symfony64Expect())
        );
    }

    // ================= 闸门 2 的计数规则：SKIP 汇总 =================

    /** @return array<string, array{string, int, int}> status, 声明值, 期望计入 */
    public static function skipAccounting(): array
    {
        return [
            // 「整个 case 标成 SKIP 且 skips=0」曾能绕过冻结常量断言，max(1,…) 就是堵口
            'SKIP 且声明 0 ⇒ 至少记 1（堵口）' => ['SKIP', 0, 1],
            'SKIP 声明 3 ⇒ 记 3' => ['SKIP', 3, 3],
            'SKIP 声明负数 ⇒ 记 1' => ['SKIP', -5, 1],
            'PASS 声明 0 ⇒ 记 0' => ['PASS', 0, 0],
            'PASS 可带子项 skip ⇒ 记 2' => ['PASS', 2, 2],
            'FAIL 声明 0 ⇒ 记 0（FAIL 自己走 exit 1，不靠 SKIP 数）' => ['FAIL', 0, 0],
            'FAIL 声明负数 ⇒ 记 0' => ['FAIL', -3, 0],
        ];
    }

    #[Test]
    #[DataProvider('skipAccounting')]
    public function caseSkipsAccounting(string $status, int $declared, int $expected): void
    {
        $this->assertSame($expected, contracts_case_skips($status, $declared));
    }

    // ================= 闸门 1+2：终审判决与退出码 =================

    /** @return array<string, array{int, int, int, string}> failed, skipped, 冻结值, 期望判决 */
    public static function verdicts(): array
    {
        return [
            '全绿且 SKIP 恰好等于冻结值 ⇒ OK' => [0, 2, 2, 'OK'],
            '冻结值为 0 的腿全绿 ⇒ OK' => [0, 0, 0, 'OK'],
            '有 case FAIL ⇒ FAIL' => [1, 2, 2, 'FAIL'],
            'FAIL 优先于 SKIP-MISMATCH（两处都不对时报 FAIL）' => [3, 0, 2, 'FAIL'],
            'SKIP 多了（12 vs 冻结 2）⇒ SKIP-MISMATCH' => [0, 12, 2, 'SKIP-MISMATCH'],
            'SKIP 少一个（1 vs 冻结 2）⇒ 同样红（少签一个字）' => [0, 1, 2, 'SKIP-MISMATCH'],
            '冻结 0 的腿冒出一个 SKIP ⇒ SKIP-MISMATCH' => [0, 1, 0, 'SKIP-MISMATCH'],
        ];
    }

    #[Test]
    #[DataProvider('verdicts')]
    public function verdictDecision(int $failed, int $skipped, int $expectedSkips, string $expected): void
    {
        $this->assertSame($expected, contracts_verdict($failed, $skipped, $expectedSkips));
    }

    #[Test]
    public function exitCodeIsZeroOnlyForExactlyOk(): void
    {
        $this->assertSame(0, contracts_verdict_exit_code('OK'));
        $this->assertSame(1, contracts_verdict_exit_code('FAIL'));
        $this->assertSame(1, contracts_verdict_exit_code('SKIP-MISMATCH'));
        // fail-closed：不认识的字面量不许静默变绿（判决函数改了字面量而退出码没跟上时，
        // 这里必须拦下 —— 这是「非零退出」这条闸门的最后一颗钉子）
        $this->assertSame(1, contracts_verdict_exit_code('BOGUS'));
    }

    // ================= 调用点 tripwire =================

    /**
     * run.php 必须真的调用 gates.php 的四个函数。
     *
     * 这条只证明**调用点存在**，不证明传参正确（那是上面各条的事）。它拦的是一种具体的
     * 腐化方式：有人把判定又内联回 run.php，四个函数变成只被单测调用、环本体不再经过
     * 它们 —— 那时上面的绿全是假绿。删掉 run.php 里任何一处调用，这条就红。
     */
    #[Test]
    public function runPhpIsWiredToTheGateFunctions(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/tools/contracts/run.php');

        foreach ([
            'contracts_leg_identity_error(',
            'contracts_case_skips(',
            'contracts_verdict(',
            'contracts_verdict_exit_code(',
        ] as $call) {
            $this->assertStringContainsString($call, $source, "run.php 没有调用 {$call}");
        }
        // 内联复活的哨兵：这两种写法是「判定又搬回 run.php」的样子，出现即红。
        $this->assertStringNotContainsString('? \'SKIP-MISMATCH\' : \'OK\'', $source);
        $this->assertStringNotContainsString('$caseSkips = max(', $source);
    }
}
