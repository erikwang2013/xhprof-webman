<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ErikWang2013\Xhprof\Drupal\Adapter\RequestAdapter as DrupalRequestAdapter;
use ErikWang2013\Xhprof\Drupal\Adapter\ResponseAdapter as DrupalResponseAdapter;
use ErikWang2013\Xhprof\Slim\Adapter\ConfigAdapter as SlimConfigAdapter;
use ErikWang2013\Xhprof\Slim\Adapter\RequestAdapter as SlimRequestAdapter;
use ErikWang2013\Xhprof\Slim\Adapter\ResponseAdapter as SlimResponseAdapter;
use ErikWang2013\Xhprof\Symfony\Adapter\RequestAdapter as SymfonyRequestAdapter;
use ErikWang2013\Xhprof\Symfony\Adapter\ResponseAdapter as SymfonyResponseAdapter;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakePsrResponse;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeResponseFactory;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeServerRequest;
use ErikWang2013\Xhprof\Yii3\Adapter\ConfigAdapter as Yii3ConfigAdapter;
use ErikWang2013\Xhprof\Yii3\Adapter\RequestAdapter as Yii3RequestAdapter;
use ErikWang2013\Xhprof\Yii3\Adapter\ResponseAdapter as Yii3ResponseAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 适配器防漂移。
 *
 * 十框架的适配器**刻意不做共享层**（每个框架一份，各自独立演化），代价是同一语义
 * 可能在两份实现里走岔。本文件把「哪些必须一致」和「哪些已经不一致」都钉成断言：
 * 前者是契约（改任何一边都要变红），后者是实测出来的既成事实（改任何一边也要变红）。
 *
 * 两组关系：
 *  1. Symfony ↔ Drupal：RequestAdapter / ResponseAdapter 是**同一份实现的两个副本**
 *     （实测只差 namespace 行），故既做源码形状对照、也做行为语料对照。
 *  2. Yii3 ↔ Slim：两份独立实现。共享面（R-1..R-7 的可见行为）必须一致；
 *     其中「query/body 同名键的合并顺序」原先不一致（Slim 是 body 胜出、且与它自己的
 *     get() 相反，会让 Core 的白名单校验被绕过），2026-09-25 已由 Slim 侧收敛、
 *     现在并入共享面语料。仍不一致的两处（都实测过）钉在文件末尾的 diverge 用例里。
 *
 * 注意每条 op 都用**新建的适配器实例**（见下面「每条 op 一个实例」的说明）。
 */
class AdapterParityTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    // ---------- 辅助 ----------

    private function tempFile(string $ext, string $content): string
    {
        $path = sys_get_temp_dir() . '/xhprof-parity-' . bin2hex(random_bytes(4)) . '.' . $ext;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * 同一操作喂给两个适配器，**逐项**断言输出完全一致。
     *
     * 逐项而不是整体 assertSame：整体比较失败时只知道「有差异」，逐项能直接指出
     * 是哪个方法走岔了。
     *
     * **每条 op 一个实例**（故这里收的是工厂而不是实例）：Slim 的 ResponseAdapter
     * 就地写被包装响应的流（不换对象），复用实例会让上一条 op 写的 body 留在同一个流里、
     * 下一条 op 看着像「追加」。生产里每个请求只构造一个适配器、只走一条链
     * （报告页 / deny() / file() 三者互斥，见该类的注释），所以复用实例那种用法
     * 本身就不存在——测它等于测一个不可能出现的场景。
     *
     * @param callable(callable(): object): array<string, mixed> $op
     * @param callable(): object                                 $makeA
     * @param callable(): object                                 $makeB
     */
    private function assertSameOutput(callable $op, callable $makeA, callable $makeB, string $label): void
    {
        $outA = $op($makeA);
        $outB = $op($makeB);
        $this->assertSame(array_keys($outA), array_keys($outB), "{$label}：两侧测的方法集合必须相同");
        foreach ($outA as $key => $value) {
            $this->assertSame($value, $outB[$key], "{$label}：{$key} 两侧输出不一致");
        }
    }

    /**
     * 同一份实现的两个副本：除 namespace 行外必须逐字节相同。
     *
     * 行为语料再多也只覆盖「想到的输入」，这条覆盖的是整份源码——副本一旦被单边修改，
     * 哪怕改的是注释也会红（注释不同本身无害，但它是「两边开始各改各的」的第一个信号）。
     */
    private function assertSameSourceExceptNamespace(string $relativePath): void
    {
        $root = dirname(__DIR__, 3) . '/src/';
        $norm = static fn (string $file): string => (string) preg_replace(
            '/^namespace .*$/m',
            'namespace <X>;',
            (string) file_get_contents($root . $file)
        );

        $this->assertSame(
            $norm('Symfony/Adapter/' . $relativePath),
            $norm('Drupal/Adapter/' . $relativePath),
            "Symfony 与 Drupal 的 {$relativePath} 是同一份实现的两个副本（只允许 namespace 行不同）。"
            . '改一边就必须同步改另一边；若确实要让两者分头演化，删掉这条断言并在计划里记一笔。'
        );
    }

    // ---------- Symfony ↔ Drupal：源码形状 ----------

    #[Test]
    public function symfonyAndDrupalRequestAdaptersAreStillTheSameImplementation(): void
    {
        $this->assertSameSourceExceptNamespace('RequestAdapter.php');
    }

    #[Test]
    public function symfonyAndDrupalResponseAdaptersAreStillTheSameImplementation(): void
    {
        $this->assertSameSourceExceptNamespace('ResponseAdapter.php');
    }

    // ---------- Symfony ↔ Drupal：行为语料 ----------

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, string>}>
     *         每项：uri、method、parameters、server
     */
    private function symfonyRequestCorpus(): array
    {
        return [
            'GET + query' => ['/list?page=2&q=%E4%B8%AD%E6%96%87', 'GET', [], []],
            'POST + query 与 body 同名键' => ['/list?page=2', 'POST', ['page' => '9', 'only_body' => 'b'], []],
            '无 query' => ['/plain', 'GET', [], []],
            '带端口与 userinfo' => ['http://user:pass@example.com:8443/a?b=1', 'GET', [], []],
            'xff 首段' => ['/x', 'GET', [], ['HTTP_X_FORWARDED_FOR' => '203.0.113.5, 10.1.1.1']],
            'x-real-ip' => ['/x', 'GET', [], ['HTTP_X_REAL_IP' => '203.0.113.7']],
            '只有 REMOTE_ADDR' => ['/x', 'GET', [], ['REMOTE_ADDR' => '10.0.0.1']],
            '什么 ip 都没有' => ['/x', 'GET', [], []],
        ];
    }

    #[Test]
    public function symfonyAndDrupalRequestAdaptersAgreeOnEveryInput(): void
    {
        foreach ($this->symfonyRequestCorpus() as $name => $case) {
            [$uri, $method, $parameters, $server] = $case;
            $request = Request::create($uri, $method, $parameters, [], [], $server);

            $this->assertSameOutput(
                function (callable $make): array {
                    $a = $make();

                    return [
                        'get(page)' => $a->get('page'),
                        'get(missing, d)' => $a->get('missing', 'd'),
                        'all()' => $a->all(),
                        'method()' => $a->method(),
                        'header(x-none)' => $a->header('x-none'),
                        'host()' => $a->host(),
                        'uri()' => $a->uri(),
                        'url()' => $a->url(),
                        'getRealIp()' => $a->getRealIp(),
                    ];
                },
                static fn (): object => new SymfonyRequestAdapter($request),
                static fn (): object => new DrupalRequestAdapter($request),
                $name
            );
        }
    }

    #[Test]
    public function symfonyAndDrupalRequestAdaptersAgreeOnHeaders(): void
    {
        // Symfony 的 Request 里 header 与 server 是两份独立数据（HeaderBag / ServerBag），
        // 而 header() 走 HeaderBag、getRealIp() 走 ServerBag —— 两条取法都要对上。
        $request = Request::create('/x', 'GET');
        $request->headers->set('X-Token', 'abc');
        $request->headers->set('X-Forwarded-For', '203.0.113.5');

        $this->assertSameOutput(
            function (callable $make): array {
                $a = $make();

                return [
                    'header(X-Token)' => $a->header('X-Token'),
                    'header(x-token 大小写)' => $a->header('x-token'),
                    'header(x-none)' => $a->header('x-none'),
                    'getRealIp()' => $a->getRealIp(),
                ];
            },
            static fn (): object => new SymfonyRequestAdapter($request),
            static fn (): object => new DrupalRequestAdapter($request),
            'header 语料'
        );
    }

    /** @return array<string, callable(callable(): object): array<string, mixed>> */
    private function symfonyResponseCorpus(): array
    {
        return [
            'status+body+headers' => static function (callable $make): array {
                $r = $make()->withStatus(403)
                    ->withBody('403 Forbidden')
                    ->withHeaders(['X-A' => '1', 'Content-Type' => 'text/plain'])
                    ->send();

                return [
                    'status' => $r->getStatusCode(),
                    'body' => $r->getContent(),
                    'X-A' => $r->headers->get('X-A'),
                    'Content-Type' => $r->headers->get('Content-Type'),
                ];
            },
            'withHeaders 非字符串值' => static function (callable $make): array {
                $r = $make()->withHeaders(['Content-Length' => 12, 'X-Multi' => ['a', 'b']])->send();

                return ['Content-Length' => $r->headers->get('Content-Length'), 'X-Multi' => $r->headers->get('X-Multi')];
            },
            'file 后加头（R-5）' => function (callable $make): array {
                $r = $make()->file($this->tempFile('css', 'body{}'))
                    ->withHeaders(['Cache-Control' => 'public, max-age=60'])
                    ->send();

                return [
                    'status' => $r->getStatusCode(),
                    // 比文件大小而不是文件名：两侧各自 tempnam 一个随机名，名字天然不同。
                    'file size' => $r->getFile()->getSize(),
                    'Content-Type' => $r->headers->get('Content-Type'),
                    'Cache-Control' => $r->headers->get('Cache-Control'),
                ];
            },
            'file 缺失 → 404' => static function (callable $make): array {
                $r = $make()->file(sys_get_temp_dir() . '/xhprof-parity-missing-' . bin2hex(random_bytes(3)) . '.css')->send();

                return ['status' => $r->getStatusCode(), 'body' => $r->getContent()];
            },
        ];
    }

    #[Test]
    public function symfonyAndDrupalResponseAdaptersAgreeOnEveryInput(): void
    {
        foreach ($this->symfonyResponseCorpus() as $label => $op) {
            $this->assertSameOutput(
                $op,
                static fn (): object => new SymfonyResponseAdapter(new Response()),
                static fn (): object => new DrupalResponseAdapter(new Response()),
                $label
            );
        }
    }

    // ---------- Yii3 ↔ Slim：共享面 ----------

    /** @return array<string, array{0: string, 1: array<string, mixed>|string, 2: array<string, mixed>, 3: array<string, mixed>}> */
    private function psrRequestCorpus(): array
    {
        return [
            '仅 query' => ['/list?page=2', [], [], []],
            '仅 body' => ['/list', ['a' => '1'], [], []],
            'body 非数组' => ['/list', 'not-an-array', [], []],
            'query 与 body 不同键' => ['/list?page=2', ['a' => '1'], [], []],
            // query 与 body **同名键**：合并顺序必须一致。这曾是两条实现的分歧（Slim 是 body
            // 胜出，且与它自己的 get() 相反），2026-09-25 由 Slim 侧收敛到 query 优先（`+=`），
            // 与 Yii3 同语义 —— 故从「分歧」并入本语料。注意这里只保证**跨实现**一致；
            // 同一实现里 get() 与 all() 必须给同一答案属于各家的自洽性，钉在 SlimTest/Yii3Test。
            'query 与 body 同名键' => ['/list?page=2', ['page' => '9'], [], []],
            'server 有 HTTP_X_FORWARDED_FOR' => ['/list', [], ['HTTP_X_FORWARDED_FOR' => '198.51.100.3'], []],
            'server 有 HTTP_X_REAL_IP' => ['/list', [], ['HTTP_X_REAL_IP' => '198.51.100.9'], []],
            '只有 REMOTE_ADDR' => ['/list', [], ['REMOTE_ADDR' => '10.0.0.1'], []],
            'REMOTE_ADDR 为空的兜底' => ['/list', [], ['REMOTE_ADDR' => ''], []],
            '带端口/编码/空 query' => ['https://example.com:8443/a%20b?q=%E4%B8%AD&empty=', [], [], []],
            '有 header x-token' => ['/list', [], [], ['x-token' => 'abc']],
        ];
    }

    #[Test]
    public function yii3AndSlimRequestAdaptersAgreeOnTheSharedSurface(): void
    {
        foreach ($this->psrRequestCorpus() as $name => $case) {
            [$uri, $body, $server, $headers] = $case;
            $request = new FakeServerRequest('POST', $uri, $server, $headers);
            if ($body !== []) {
                $request = $request->withParsedBody($body);
            }

            $this->assertSameOutput(
                function (callable $make): array {
                    $a = $make();

                    return [
                        'get(page)' => $a->get('page'),
                        'get(missing, d)' => $a->get('missing', 'd'),
                        'all()' => $a->all(),
                        'method()' => $a->method(),
                        'header(x-none)' => $a->header('x-none'),
                        'header(x-token)' => $a->header('x-token'),
                        'host()' => $a->host(),
                        'uri()' => $a->uri(),
                        'url()' => $a->url(),
                        'getRealIp()' => $a->getRealIp(),
                    ];
                },
                static fn (): object => new SlimRequestAdapter($request),
                static fn (): object => new Yii3RequestAdapter($request),
                $name
            );
        }
    }

    /** @return array<string, callable(callable(): object): array<string, mixed>> */
    private function psrResponseCorpus(): array
    {
        return [
            // Slim 包装已有响应、Yii3 到 send() 才用 factory 造一个新的：形态不同，
            // 但对外产出的 PSR-7 响应必须一样（R-4/R-5 的可见结果）。
            'status+body+headers' => static function (callable $make): array {
                $r = $make()->withStatus(403)->withBody('403 Forbidden')->withHeaders(['X-A' => '1'])->send();

                return [
                    'status' => $r->getStatusCode(),
                    'body' => (string) $r->getBody(),
                    'X-A' => $r->getHeaderLine('X-A'),
                    'Content-Type' => $r->getHeaderLine('Content-Type'),
                ];
            },
            'file css' => function (callable $make): array {
                $r = $make()->file($this->tempFile('css', 'body{}'))
                    ->withHeaders(['Cache-Control' => 'public, max-age=60'])
                    ->send();

                return [
                    'status' => $r->getStatusCode(),
                    'body' => (string) $r->getBody(),
                    'Content-Type' => $r->getHeaderLine('Content-Type'),
                    'Cache-Control' => $r->getHeaderLine('Cache-Control'),
                ];
            },
            'file 缺失 → 404' => static function (callable $make): array {
                $r = $make()->file(sys_get_temp_dir() . '/xhprof-parity-missing-' . bin2hex(random_bytes(3)) . '.css')->send();

                return ['status' => $r->getStatusCode(), 'body' => (string) $r->getBody()];
            },
            '204 空 body' => static function (callable $make): array {
                $r = $make()->withStatus(204)->send();

                return ['status' => $r->getStatusCode(), 'body' => (string) $r->getBody()];
            },
        ];
    }

    #[Test]
    public function yii3AndSlimResponseAdaptersAgreeOnTheSharedSurface(): void
    {
        foreach ($this->psrResponseCorpus() as $label => $op) {
            $this->assertSameOutput(
                $op,
                static fn (): object => new SlimResponseAdapter(new FakePsrResponse()),
                static fn (): object => new Yii3ResponseAdapter(new FakeResponseFactory()),
                $label
            );
        }
    }

    #[Test]
    public function yii3AndSlimConfigAdaptersAgreeOnTheContractKeyShapes(): void
    {
        // R-6：Core 只用这两种取法（bootstrap 用整块、index 用叶子）。
        // 语料不含裸键 'assets_url'（那处不一致，见文件末尾）。
        foreach (['xhprof', 'xhprof.assets_url', 'xhprof.enable', 'xhprof.ignore_url_arr', 'xhprof.nope', 'nope.nope'] as $key) {
            $config = ['assets_url' => '/custom', 'enable' => false];

            $this->assertSameOutput(
                static fn (callable $make): array => ['get' => $make()->get($key, 'DEFAULT')],
                static fn (): object => new SlimConfigAdapter($config),
                static fn (): object => new Yii3ConfigAdapter($config),
                "key={$key}"
            );
        }
    }

    // ---------- Yii3 ↔ Slim：实测仍不一致的两处（钉住，改任何一边都要变红） ----------

    #[Test]
    public function yii3AndSlimDivergeOnRealIpSourceWhenItIsOnlyInHeaders(): void
    {
        // 消费者：src/Core/XhprofLib/Utils/XhprofLib.php:461 → 写进 request_log 的 ip 字段。
        // PSR-7 里 header 与 serverParams 是两处独立数据（真实的 nyholm/slim-psr7 亦如此）。
        $request = new FakeServerRequest('GET', '/list', [], ['x-forwarded-for' => '203.0.113.9']);
        $slim = new SlimRequestAdapter($request);
        $yii3 = new Yii3RequestAdapter($request);

        // Slim 只看 serverParams：header 里带的 XFF 读不到 → 退化成兜底值。
        $this->assertSame('127.0.0.1', $slim->getRealIp(), 'Slim 只读 serverParams');
        // Yii3 先读 header 再退回 serverParams：读到 header 里的值。
        $this->assertSame('203.0.113.9', $yii3->getRealIp(), 'Yii3 先读 header');
    }

    #[Test]
    public function yii3AndSlimDivergeOnBareKeyLookup(): void
    {
        // 裸键（不带 xhprof. 前缀）目前**无消费者**：Core 里所有取值都是
        // get('xhprof') 或 get('xhprof.<leaf>')（已 grep 全 src/Core 确认）。
        // 所以这处差异当前不影响行为，但一旦 Core 改用裸键，两个框架就会取到不同的值。
        $config = ['assets_url' => '/custom'];
        $slim = new SlimConfigAdapter($config);
        $yii3 = new Yii3ConfigAdapter($config);

        // Slim 把配置挂在 ['xhprof' => …] 之下，顶层没有 assets_url → 落到 default。
        $this->assertSame('DEFAULT', $slim->get('assets_url', 'DEFAULT'), 'Slim 的裸键取不到值');
        // Yii3 裸键直接在配置数组里找 → 取到值。
        $this->assertSame('/custom', $yii3->get('assets_url', 'DEFAULT'), 'Yii3 的裸键能取到值');
    }
}
