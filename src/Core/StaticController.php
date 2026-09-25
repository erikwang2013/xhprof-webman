<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class StaticController
{
    private const ASSETS_DIR = 'src/html';

    /** `xhprof.assets_url` 的默认值，与各框架配置文件里的默认值一致（见 uriPrefix()）。 */
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
        // **缺** Content-Type 时用 finfo 按**内容**嗅探——实测本包 src/html 的 11 个文件里
        // 8 个被猜错（三个 css 全被猜成 text/plain；四个 js 里 xhprof_report.js /
        // jquery.dataTables.min.js / bootstrap.min.js 猜成 text/plain，dataTables.bootstrap.js
        // 猜成 text/html；png/gif 两个猜对了），浏览器会拒收 text/plain 的 script、
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

    /**
     * 资源 URL 前缀（带尾斜杠），取自 `xhprof.assets_url`——**与各家入口类同一口径**：
     * Slim/Symfony/WordPress/Joomla/Yii3 的短路前缀与 Drupal 的守卫都从这一个配置项
     * 归一化出来（同一套归一化：先取原串再 rtrim，空串 = 不启用）。
     * 四个路由型框架（Laravel/Hyperf/Webman/ThinkPHP）的资源**路由 path 不跟配置走**：
     * 那条路由由用户在各自的路由文件里写死，改了配置只会让 Core 不再服务这条路径。
     * 这是既有的已知边界，README 不声称自定义前缀在它们身上生效。
     * 这里曾经硬编码 `/xhprof-assets`，于是配成别的值时入口类按配置把请求交给
     * serve()，而 serve() 只认老前缀 → 返回**空 body 的 200**，静态资源静默消失。
     *
     * 归一化先取原始串、再 rtrim：先 rtrim 再判空会把 `/` 归成空串而落到默认值，
     * 与入口类对 `/` 的判定分叉。空串/非字符串 = 不启用资源短路（入口类就不接管这类
     * 请求，Core 也就一个都不认）。未 bootstrap 时 getConfig() 为 null，用默认值。
     */
    private static function uriPrefix(): string
    {
        $cfg = Xhprof::getConfig();
        $assetsUrl = $cfg !== null ? $cfg->get('xhprof.assets_url', self::URI_PREFIX) : self::URI_PREFIX;
        if (!is_string($assetsUrl) || $assetsUrl === '') {
            return '';
        }
        return rtrim($assetsUrl, '/') . '/';
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
        $prefix = self::uriPrefix();
        if ($prefix === '' || !str_starts_with($pathOnly, $prefix)) {
            return null;
        }
        $path = substr($pathOnly, strlen($prefix));
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        return $path;
    }
}
