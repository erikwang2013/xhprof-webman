<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Core\Xhprof;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeConfig;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StaticControllerTest extends TestCase
{
    private FakeResponse $response;

    private ?ConfigInterface $savedConfig;

    private bool $savedHyperfFlag;

    protected function setUp(): void
    {
        $this->response = new FakeResponse();
        // Xhprof::$config 是进程级静态量：别的测试类（十二家适配器）会临时换上自己的
        // FakeConfig，虽然它们都在 tearDown 里还原，本类自己改了也必须还原——否则
        // 「哪些资源请求被认」这件事会随测试顺序变化。
        $this->savedConfig = Xhprof::$config;
        $this->useConfig(null);

        // `$_hyperf` 也是私有静态量，且**进程内一旦置位就不复位**：
        // XhprofProfiler::isHyperf() 置位后没有任何地方复位它，而 HyperfTest /
        // MissingExtensionParityTest 排在 Core 前面、tearDown 还会 Context::reset()
        // ——此后 `Xhprof::getConfig()` 短路去读 Context，
        // 拿到 null，本类读写 `Xhprof::$config` 的行就全部失去判别力。
        // 钉死为 false：本类只测「静态属性这条通道」，Context 通道由 HyperfTest 测。
        // 先例见 tests/Unit/Core/I18nTest.php 里对同一闩的存/复原。
        $this->savedHyperfFlag = (bool) self::hyperfFlag()->getValue();
        self::hyperfFlag()->setValue(null, false);
    }

    protected function tearDown(): void
    {
        self::hyperfFlag()->setValue(null, $this->savedHyperfFlag);
        $this->useConfig($this->savedConfig);
    }

    private static function hyperfFlag(): \ReflectionProperty
    {
        return new \ReflectionProperty(Xhprof::class, '_hyperf');
    }

    private function useConfig(?ConfigInterface $config): void
    {
        Xhprof::$config = $config;
    }

    private function useAssetsUrl(string $assetsUrl): void
    {
        $this->useConfig(new FakeConfig(['xhprof' => ['assets_url' => $assetsUrl]]));
    }

    #[Test]
    public function getPackageRootPointsToPackageRoot(): void
    {
        $root = StaticController::getPackageRoot();

        $this->assertIsString($root);
        $this->assertNotSame('', $root);
        $this->assertDirectoryExists($root . DIRECTORY_SEPARATOR . 'src');
        $this->assertDirectoryExists($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core');
        $this->assertFileExists($root . DIRECTORY_SEPARATOR . 'composer.json');
    }

    #[Test]
    public function getAssetsPathPointsToExistingHtmlDir(): void
    {
        $path = StaticController::getAssetsPath();

        $this->assertIsString($path);
        $this->assertDirectoryExists($path);
        $this->assertStringEndsWith('src' . DIRECTORY_SEPARATOR . 'html', $path);
        $this->assertDirectoryExists($path . DIRECTORY_SEPARATOR . 'js');
        $this->assertDirectoryExists($path . DIRECTORY_SEPARATOR . 'css');
    }

    #[Test]
    public function serveReturnsFileForValidAssetPath(): void
    {
        $request = new FakeRequest([], ['uri' => '/xhprof-assets/js/xhprof_report.js']);

        $result = StaticController::serve($request, $this->response);

        $this->assertSame($this->response, $result);
        $this->assertNotNull($result->filePath);
        $this->assertSame(
            realpath(StaticController::getAssetsPath() . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'xhprof_report.js'),
            realpath($result->filePath)
        );
        $this->assertSame('public, max-age=86400', $result->headers['Cache-Control']);
        $this->assertFileExists($result->filePath);
        // 原先这里是 file_get_contents(...) 与 (string) 后的自己比较，恒真、无信息量。
        // 真正值得断言的是：包内那份资源确实存在且非空（静态资源没被打进包就会暴露）。
        $this->assertNotSame('', (string) file_get_contents($result->filePath), '资源文件不应为空');
    }

    #[Test]
    public function serveAcceptsFullUrlWithQueryString(): void
    {
        $request = new FakeRequest([], ['uri' => 'http://localhost:8787/xhprof-assets/css/xhprof.css?v=123']);

        $result = StaticController::serve($request, $this->response);

        $this->assertNotNull($result->filePath);
        $this->assertStringEndsWith('xhprof.css', $result->filePath);
        $this->assertFileExists($result->filePath);
    }

    #[Test]
    public function serveServesJqueryFromSubdirectory(): void
    {
        $request = new FakeRequest([], ['uri' => '/xhprof-assets/jquery/jquery-3.0.0.min.js']);

        $result = StaticController::serve($request, $this->response);

        $this->assertNotNull($result->filePath);
        $this->assertStringEndsWith('jquery-3.0.0.min.js', $result->filePath);
    }

    #[Test]
    public function serveSetsCacheControlAndContentTypeHeaders(): void
    {
        $request = new FakeRequest([], ['uri' => '/xhprof-assets/js/xhprof_report.js']);

        StaticController::serve($request, $this->response);

        $this->assertSame(
            ['Cache-Control' => 'public, max-age=86400', 'Content-Type' => 'application/javascript'],
            $this->response->headers
        );
    }

    /**
     * file() 之后的 withHeaders() 必须显式钉住 Content-Type。
     *
     * 不钉的话，Laravel 的 response()->file() 会把它交给 Symfony BinaryFileResponse::prepare()，
     * 那里在**缺** Content-Type 时用 finfo 按内容嗅探——实测本包 11 个资源里 8 个被猜错
     * （js/css 猜成 text/plain、dataTables.bootstrap.js 猜成 text/html；png/gif 正常），
     * 浏览器会拒收 text/plain 的脚本、不套用 text/plain 的样式表。
     *
     * @return iterable<string, array{string, string}>
     */
    public static function assetContentTypeProvider(): iterable
    {
        yield 'js' => ['js/xhprof_report.js', 'application/javascript'];
        yield 'js in subdir' => ['js/dataTables.bootstrap.js', 'application/javascript'];
        yield 'jquery' => ['jquery/jquery-3.0.0.min.js', 'application/javascript'];
        yield 'css' => ['css/xhprof.css', 'text/css'];
        yield 'css (bootstrap)' => ['css/bootstrap.css', 'text/css'];
        yield 'png' => ['images/sort_both.png', 'image/png'];
        yield 'gif' => ['jquery/indicator.gif', 'image/gif'];
        // 宠物排序图标：与 png/gif 同口径，按**真实文件**过一遍 serve()。类型表里早有
        // svg 这一档（pet.svg 就是靠它服务的），这条钉的是「文件真的在、真的以 image/svg+xml
        // 出去」——上面的 url() 解析检查管引用，这里管服务，两件事。
        yield 'svg' => ['images/sort_asc.svg', 'image/svg+xml'];
    }

    #[Test]
    #[DataProvider('assetContentTypeProvider')]
    public function servePinsContentTypeByFileExtension(string $asset, string $expected): void
    {
        $request = new FakeRequest([], ['uri' => '/xhprof-assets/' . $asset]);

        $result = StaticController::serve($request, $this->response);

        $this->assertNotNull($result->filePath, "资源不存在：$asset");
        $this->assertSame($expected, $result->headers['Content-Type']);
        // 与 LocalFile 嗅探无关的证据：钉的就是扩展名推出来的那个类型（见 contentType()）
        $this->assertSame($expected, StaticController::contentType($result->filePath));
    }

    /**
     * 类型表本身：readFile() 与 file 响应共用同一张，扩展名大小写不敏感与否以表为准。
     *
     * @return iterable<string, array{string, string}>
     */
    public static function contentTypeProvider(): iterable
    {
        yield 'css' => ['a.css', 'text/css'];
        yield 'js' => ['a.js', 'application/javascript'];
        yield 'png' => ['a.png', 'image/png'];
        yield 'gif' => ['a.gif', 'image/gif'];
        yield 'jpg' => ['a.jpg', 'image/jpeg'];
        yield 'jpeg' => ['a.jpeg', 'image/jpeg'];
        yield 'svg' => ['a.svg', 'image/svg+xml'];
        yield '表外扩展名' => ['a.woff2', 'application/octet-stream'];
        yield '无扩展名' => ['LICENSE', 'application/octet-stream'];
    }

    #[Test]
    #[DataProvider('contentTypeProvider')]
    public function contentTypeComesFromTheSharedMimeTable(string $path, string $expected): void
    {
        $this->assertSame($expected, StaticController::contentType($path));
    }

    #[Test]
    public function readFileAndFileResponseShareOneMimeTable(): void
    {
        // 一处新造第二张表就会红：readFile()（十个适配器的 file() 用它；只有 Laravel 与 Webman
        // 走框架自己的 response()->file()，不调 readFile 也不自己钉类型）与 serve()
        // 钉的 Content-Type 必须永远是同一个来源。
        foreach (['css/xhprof.css', 'js/xhprof_report.js', 'images/sort_both.png'] as $asset) {
            $path = StaticController::getAssetsPath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
            $file = StaticController::readFile($path);

            $this->assertNotNull($file, "资源不存在：$asset");
            $this->assertSame(StaticController::contentType($path), $file[1]);
        }
    }

    /**
     * 报告页加载的每份自管 CSS 里，`url()` 指向的文件都必须真的在包里。
     *
     * 反面教材就是这个仓库自己：换宠物排序图标之前，那五条 .sorting* 规则里有三条
     * （sort_asc.png / sort_asc_disabled.png / sort_desc_disabled.png）指向从未随包
     * 发行的文件——表头看起来就是「有的列有箭头、有的没有」。三张宠物排序 SVG 是
     * 这次唯一新增的引用，这条断言负责把「接线接上了」钉死。
     *
     * `css/bootstrap.css` 不在检查范围：它逐字未改（上游副本），6 条 Glyphicons 字体
     * url() 指向本包不发行的 fonts/ 目录；报告页不用 glyphicon——图标只有 pet.svg 与
     * 这三张排序图，都走 .xp-* / .sorting* 规则，那 6 条永远不会被请求。
     */
    #[Test]
    public function everyUrlInReportPageCssResolvesToAPackagedFile(): void
    {
        $assetsPath = StaticController::getAssetsPath();
        $refs = [];

        foreach (['css/xhprof.css', 'css/dataTables.bootstrap.css'] as $css) {
            $file = $assetsPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $css);
            $text = file_get_contents($file);
            $this->assertNotFalse($text, "读不到 $css");

            preg_match_all('/url\(\s*[\'"]?([^\'")]+?)[\'"]?\s*\)/', $text, $matches);
            $this->assertNotEmpty($matches[1], "$css 里一条 url() 都没匹配到——先看正则还是文件");

            foreach ($matches[1] as $ref) {
                if (preg_match('#^(data:|https?:|//)#', $ref) === 1) {
                    continue;
                }
                // CSS 里相对 url() 相对**该 css 文件**解析
                $target = realpath(dirname($file) . DIRECTORY_SEPARATOR . $ref);
                $this->assertNotFalse($target, "$css 引用的 $ref 在包里不存在");
                $this->assertStringStartsWith(
                    realpath($assetsPath) . DIRECTORY_SEPARATOR,
                    $target,
                    "$css 的 $ref 指到了 src/html 之外"
                );
                $refs[] = basename($ref);
            }
        }

        // 图标契约：宠物本体 + 排序三态。少一张就说明规则被误删或没接上。
        $unique = array_values(array_unique($refs));
        sort($unique);
        $this->assertSame(['pet.svg', 'sort_asc.svg', 'sort_both.svg', 'sort_desc.svg'], $unique);
    }

    /**
     * 两份 pet.svg 必须始终是同一只火苗。
     *
     * 两个文件第 5 行都写着「改一处要同步另一处」——但在本条之前，没有任何测试比对过
     * 两份图画（唯一提到 pet.svg 的断言只钉文件集合与 MIME）。宠物是 README 首图、
     * 报告页品牌图标与三张排序图的共同来源，两份走形 = 文档里的火苗和页面上的不是同一只。
     *
     * 口径：去掉 XML 注释（头注释按用途**有意**分叉：一份说「这里（src/html/）与
     * docs/images/pet.svg」，另一份反过来）、把连续空白归一成一个空格后，其余字节必须
     * 逐字相同——归一空白只放过排版差异，图形、配色、坐标的差异一个都跑不掉。
     */
    #[Test]
    public function theTwoPetSvgCopiesStayInSync(): void
    {
        $normalize = static fn(string $svg): string => trim((string) preg_replace(
            '/\s+/',
            ' ',
            (string) preg_replace('/<!--.*?-->/s', '', $svg)
        ));

        $packaged = $normalize((string) file_get_contents(StaticController::getAssetsPath() . '/pet.svg'));
        $documentation = $normalize((string) file_get_contents(StaticController::getPackageRoot() . '/docs/images/pet.svg'));

        $this->assertGreaterThan(500, strlen($packaged), 'src/html/pet.svg 读出来是空的或残缺');
        $this->assertSame(
            $packaged,
            $documentation,
            'docs/images/pet.svg 与 src/html/pet.svg 走形了：改一处要同步另一处'
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversalUriProvider(): iterable
    {
        yield 'dotdot segment' => ['/xhprof-assets/../src/Core/Xhprof.php'];
        yield 'dotdot mid path' => ['/xhprof-assets/js/../css/xhprof.css'];
        yield 'dotdot deep' => ['/xhprof-assets/js/../../../../etc/passwd'];
        yield 'encoded slash-dotdot' => ['/xhprof-assets/js/%2e%2e/xhprof_report.js'];
        yield 'double encoded' => ['/xhprof-assets/%252e%252e/css/xhprof.css'];
        yield 'backslash trick' => ['/xhprof-assets/..\\..\\etc\\passwd'];
        yield 'dotdot with full url' => ['http://evil.com/xhprof-assets/../Xhprof.php'];
    }

    #[Test]
    #[DataProvider('traversalUriProvider')]
    public function serveRejectsPathTraversal(string $uri): void
    {
        $request = new FakeRequest([], ['uri' => $uri]);

        $result = StaticController::serve($request, $this->response);

        $this->assertSame($this->response, $result);
        $this->assertSame('', $result->body);
        $this->assertNull($result->filePath);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUriProvider(): iterable
    {
        yield 'non-prefix path' => ['/other/js/xhprof_report.js'];
        yield 'missing leading slash' => ['xhprof-assets/js/xhprof_report.js'];
        yield 'prefix without slash' => ['/xhprof-assets'];
        yield 'prefix with trailing slash' => ['/xhprof-assets/'];
        yield 'empty path' => ['/'];
        yield 'root' => ['/xhprof-assets/js/'];
        yield 'scheme only' => ['http://localhost'];
    }

    #[Test]
    #[DataProvider('invalidUriProvider')]
    public function serveReturnsEmptyBodyForInvalidOrNonMatchingUri(string $uri): void
    {
        $request = new FakeRequest([], ['uri' => $uri]);

        $result = StaticController::serve($request, $this->response);

        $this->assertSame($this->response, $result);
        $this->assertSame('', $result->body);
        $this->assertNull($result->filePath);
        $this->assertSame([], $result->headers);
    }

    #[Test]
    public function serveReturnsEmptyBodyForMissingFile(): void
    {
        $request = new FakeRequest([], ['uri' => '/xhprof-assets/js/does-not-exist.js']);

        $result = StaticController::serve($request, $this->response);

        $this->assertSame('', $result->body);
        $this->assertNull($result->filePath);
    }

    #[Test]
    public function serveReturnsEmptyBodyForEmptyUri(): void
    {
        // 空 uri：parse_url 解析失败返回 null，getPathFromRequest 返回 null
        $request = new FakeRequest([], ['uri' => '']);

        $result = StaticController::serve($request, $this->response);

        $this->assertSame('', $result->body);
        $this->assertNull($result->filePath);
    }

    #[Test]
    public function serveDoesNotLeakParentDirectoryContent(): void
    {
        // 即使文件真实存在，位于 assets 目录之外也不得返回
        $outside = StaticController::getPackageRoot() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Xhprof.php';
        $this->assertFileExists($outside);

        $request = new FakeRequest([], ['uri' => '/xhprof-assets/../Core/Xhprof.php']);

        StaticController::serve($request, $this->response);

        $this->assertSame('', $this->response->body);
        $this->assertNull($this->response->filePath);
    }

    #[Test]
    public function serveRejectsSiblingRealpathOutsideAssets(): void
    {
        // %2e%2e 不被 str_contains('..') 捕获，但最终 realpath 前缀校验兜底
        $request = new FakeRequest([], ['uri' => '/xhprof-assets/%2e%2e/Core/Xhprof.php']);

        $result = StaticController::serve($request, $this->response);

        $this->assertSame('', $result->body);
        $this->assertNull($result->filePath);
    }

    /**
     * 资源前缀必须跟着 `xhprof.assets_url` 走，且归一化口径与 **12 个入口类**一致
     * （各入口类短路时用的前缀都从这一个配置项归一化出来，参照实现见 `uriPrefix()`；
     * 四个路由型入口曾经把资源 path 写死在用户路由文件里，那条边界已随中间件短路消失）。
     *
     * 这条路径此前是坏的：前缀硬编码在 StaticController 里，入口类按**配置的**
     * 前缀决定要不要接管，于是「配了非默认值」= 入口类把请求交给 serve()，
     * serve() 只认老前缀 → **空 body 的 200**，静态资源静默消失（Css/Js 全丢，
     * 报告页没有样式和脚本）。Slim/Yii3 各有一条特征化用例曾把这个缺陷钉住。
     *
     * @return iterable<string, array{?string, string, bool}>
     */
    public static function assetsUrlProvider(): iterable
    {
        // 未 bootstrap（getConfig() 为 null）时用默认前缀——契约兜底，不能炸
        yield '没有配置' => [null, '/xhprof-assets/js/xhprof_report.js', true];
        yield '配置 = 默认值' => ['/xhprof-assets', '/xhprof-assets/js/xhprof_report.js', true];
        yield '配置带尾斜杠' => ['/xhprof-assets/', '/xhprof-assets/js/xhprof_report.js', true];
        yield '自定义前缀' => ['/static/xhprof', '/static/xhprof/js/xhprof_report.js', true];
        yield '自定义前缀 + 尾斜杠' => ['/static/xhprof/', '/static/xhprof/js/xhprof_report.js', true];
        // 绝对 URL（CDN）：入口类的短路前缀也变成绝对 URL、永远匹配不上 path-only
        // 的 uri —— 资源归 CDN，本地一个都不服务。Core 必须同样一个都不认。
        yield 'CDN 绝对 URL' => ['https://cdn.test/xhprof-assets', '/xhprof-assets/js/xhprof_report.js', false];
        // 空串 = 不启用资源短路（WordPress/Joomla 的注释口径）：入口类不接管，Core 也不认
        yield '配置成空串' => ['', '/xhprof-assets/js/xhprof_report.js', false];
        // '/' 不是空串，归一化后前缀就是 '/'（先 rtrim 再判空会把它归成空串、
        // 落到默认值上 → 与入口类的判定分叉）：于是任何路径都算资源路径。
        yield '配置成 /' => ['/', '/js/xhprof_report.js', true];
    }

    #[Test]
    #[DataProvider('assetsUrlProvider')]
    public function serveFollowsTheConfiguredAssetsUrl(?string $assetsUrl, string $uri, bool $serves): void
    {
        if ($assetsUrl !== null) {
            $this->useAssetsUrl($assetsUrl);
        }

        $result = StaticController::serve(new FakeRequest([], ['uri' => $uri]), $this->response);

        if (!$serves) {
            $this->assertNull($result->filePath, "不该把 $uri 当资源");
            $this->assertSame('', $result->body);
            return;
        }

        $this->assertNotNull($result->filePath, "配了 $assetsUrl 后 $uri 应当能读到文件");
        $this->assertFileExists($result->filePath);
        $this->assertNotSame('', (string) file_get_contents($result->filePath), '资源文件不应为空');
        $this->assertSame('public, max-age=86400', $result->headers['Cache-Control']);
    }

    /**
     * 换前缀后**老前缀不再被认**：否则「同一份文件有两个 URL」，
     * 缓存与 CDN 的键都会分裂。
     */
    #[Test]
    public function serveStopsMatchingTheDefaultPrefixWhenConfiguredOtherwise(): void
    {
        $this->useAssetsUrl('/static/xhprof');

        $result = StaticController::serve(
            new FakeRequest([], ['uri' => '/xhprof-assets/js/xhprof_report.js']),
            $this->response
        );

        $this->assertNull($result->filePath);
        $this->assertSame('', $result->body);
    }

    #[Test]
    public function serveIsIdempotentAcrossCalls(): void
    {
        $request = new FakeRequest([], ['uri' => '/xhprof-assets/js/xhprof_report.js']);

        StaticController::serve($request, $this->response);
        $first = $this->response->filePath;
        StaticController::serve($request, $this->response);

        $this->assertNotNull($first);
        $this->assertSame($first, $this->response->filePath);
    }
}
