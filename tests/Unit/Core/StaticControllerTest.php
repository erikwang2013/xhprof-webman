<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core;

require_once __DIR__ . '/../../Fixtures/Fakes.php';

use ErikWang2013\Xhprof\Core\StaticController;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeRequest;
use ErikWang2013\Xhprof\Tests\Fixtures\FakeResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StaticControllerTest extends TestCase
{
    private FakeResponse $response;

    protected function setUp(): void
    {
        $this->response = new FakeResponse();
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
     * 那里在**缺** Content-Type 时用 finfo 按内容嗅探——实测本包 18 个资源里 14 个被猜错
     * （js/css 猜成 text/plain、dataTables.bootstrap.js 猜成 text/html），
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
        // 一处新造第二张表就会红：readFile()（六个适配器的 file() 用它）与 serve()
        // 钉的 Content-Type 必须永远是同一个来源。
        foreach (['css/xhprof.css', 'js/xhprof_report.js', 'images/sort_both.png'] as $asset) {
            $path = StaticController::getAssetsPath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
            $file = StaticController::readFile($path);

            $this->assertNotNull($file, "资源不存在：$asset");
            $this->assertSame(StaticController::contentType($path), $file[1]);
        }
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
