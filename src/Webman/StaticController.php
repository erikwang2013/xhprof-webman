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
     * 根据请求 path 返回对应静态文件内容
     * 路由示例：Route::get('/xhprof-assets/{path:.+}', [StaticController::class, 'serve']);
     */
    public static function serve(Request $request): Response
    {
        $path = $request->param('path', $request->get('path', ''));
        if ($path === '') {
            $uri = $request->uri();
            $prefix = '/xhprof-assets';
            if (str_starts_with($uri, $prefix . '/')) {
                $path = substr($uri, strlen($prefix) + 1);
            }
        }
        if ($path === '' || str_contains($path, '..')) {
            return response('', 404);
        }

        $base = self::getAssetsPath();
        $file = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        $realBase = realpath($base);
        $realFile = realpath($file);
        if (!is_file($file) || $realBase === false || $realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            return response('', 404);
        }

        $mime = self::mimeType($file);
        $body = file_get_contents($file);
        if ($body === false) {
            return response('', 404);
        }

        return response($body, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private static function mimeType(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $map = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }
}
