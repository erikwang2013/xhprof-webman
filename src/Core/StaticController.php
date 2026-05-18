<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class StaticController
{
    private const ASSETS_DIR = 'src/html';
    private const URI_PREFIX = '/xhprof-assets';

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

        return $response->file($realFile)->withHeaders([
            'Cache-Control' => 'public, max-age=86400',
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
