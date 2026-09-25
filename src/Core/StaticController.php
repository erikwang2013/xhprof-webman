<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class StaticController
{
    private const ASSETS_DIR = 'src/html';
    private const URI_PREFIX = '/xhprof-assets';

    private const MIME_TYPES = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
    ];

    /** 读取文件内容并推断 MIME 类型；文件不存在/不可读返回 null。 */
    public static function readFile(string $path): ?array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        return [$content, self::contentType($path)];
    }

    /**
     * 由文件扩展名推 Content-Type（MIME_TYPES 是唯一的一张表，readFile() 与 file 响应共用）。
     */
    public static function contentType(string $path): string
    {
        return self::MIME_TYPES[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    }

    public static function getPackageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function getAssetsPath(): string
    {
        return self::getPackageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::ASSETS_DIR);
    }

    public static function serve(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $path = self::getPathFromRequest($request);
        if ($path === null) {
            return $response->withBody('')->withHeaders([]);
        }

        $base = self::getAssetsPath();
        if (!is_dir($base)) {
            return $response->withBody('')->withHeaders([]);
        }

        $file = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $realBase = realpath($base);
        $realFile = $file !== '' && is_file($file) ? realpath($file) : false;

        if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            return $response->withBody('')->withHeaders([]);
        }

        // Content-Type 必须显式钉住，不能交给 file() 的实现去猜：Laravel 的
        // response()->file() 返回 Symfony BinaryFileResponse，它的 prepare() 在
        // **缺** Content-Type 时用 finfo 按**内容**嗅探——实测本包 src/html 的 18 个文件里
        // 14 个被猜错（xhprof_report.js / 三个 css 全被猜成 text/plain 或 text/html，
        // dataTables.bootstrap.js 猜成 text/html），浏览器会拒收 text/plain 的 script、
        // 不套用 text/plain 的样式表 → 报告页在 Laravel 上无 JS 无 CSS。
        // 其余六个适配器的 file() 自己钉了同一个类型（都取自本文件的 MIME_TYPES），
        // 这里补上后十家输出一致；类型表复用 readFile() 那张，不新造第二张。
        // withHeaders() 必须在 file() 之后：file() 返回的是**新的**响应对象（Laravel 上是一个
        // 新建的 BinaryFileResponse），先挂头会被它整个替换掉，头就白挂了。
        return $response->file($realFile)->withHeaders([
            'Cache-Control' => 'public, max-age=86400',
            'Content-Type' => self::contentType($realFile),
        ]);
    }

    private static function getPathFromRequest(RequestInterface $request): ?string
    {
        $uri = $request->uri();
        if (!is_string($uri)) {
            return null;
        }
        $pathOnly = parse_url($uri, PHP_URL_PATH);
        if ($pathOnly === null || $pathOnly === '') {
            return null;
        }
        $prefix = self::URI_PREFIX . '/';
        if (!str_starts_with($pathOnly, $prefix)) {
            return null;
        }
        $path = substr($pathOnly, strlen($prefix));
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        return $path;
    }
}
