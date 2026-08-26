<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Stubs;

/**
 * 框架 stub 共享的静态注册表，测试之间用 reset() 隔离。
 */
class Registry
{
    /** @var array<string, mixed> webman 点号配置树 */
    public static array $webmanConfig = [];

    /** @var array<string, mixed> laravel 点号配置树 */
    public static array $laravelConfig = [];

    /** @var array<int, string> 记录 copy_dir 调用 */
    public static array $copied = [];

    /** @var array<int, string> 记录 remove_dir 调用 */
    public static array $removed = [];

    public static string $basePath = '/tmp/xhprof-test';

    public static function reset(): void
    {
        self::$webmanConfig = [];
        self::$laravelConfig = [];
        self::$copied = [];
        self::$removed = [];
        self::$basePath = '/tmp/xhprof-test';
    }

    public static function resolve(array $tree, string $key): mixed
    {
        $parts = explode('.', $key);
        $value = $tree;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
