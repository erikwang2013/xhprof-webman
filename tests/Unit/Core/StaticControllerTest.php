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
        $this->assertSame(file_get_contents($result->filePath), (string) file_get_contents($result->filePath));
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
    public function serveSetsCacheControlHeaderOnly(): void
    {
        $request = new FakeRequest([], ['uri' => '/xhprof-assets/js/xhprof_report.js']);

        StaticController::serve($request, $this->response);

        $this->assertSame(['Cache-Control' => 'public, max-age=86400'], $this->response->headers);
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
