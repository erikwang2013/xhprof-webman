<?php

declare(strict_types=1);

namespace Aaron\Xhprof\Webman;

use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 从本包内提供静态文件，无需复制到项目 public 目录
 */
class StaticController
{
    /** 包内静态资源目录（相对包根目录） */
    private const ASSETS_DIR = 'src/html';

    /** 路由前缀，须与 config assets_url 一致 */
    private const URI_PREFIX = '/xhprof-assets';

    /**
     * 获取包根目录（composer 包根，即含 composer.json 的目录）
     */
    public static function getPackageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * 获取静态资源绝对路径
     */
    public static function getAssetsPath(): string
    {
        return self::getPackageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::ASSETS_DIR);
    }

    /**
     * 根据请求 URI 返回对应静态文件
     * 路由示例：Route::get('/xhprof-assets/{path:.+}', [StaticController::class, 'serve']);
     */
    public static function serve(Request $request): Response
    {
        $path = self::getPathFromRequest($request);
        if ($path === null) {
            return response('', 404);
        }

        $base = self::getAssetsPath();
        if (!is_dir($base)) {
            return response('', 404);
        }

        $file = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $realBase = realpath($base);
        $realFile = $file !== '' && is_file($file) ? realpath($file) : false;

        if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            return response('', 404);
        }

        return response()->file($realFile)->withHeaders([
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * 从请求中解析静态文件子路径（不含前缀与 query）
     */
    private static function getPathFromRequest(Request $request): ?string
    {
        $uri = method_exists($request, 'uri') ? $request->uri() : ($_SERVER['REQUEST_URI'] ?? '');
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
