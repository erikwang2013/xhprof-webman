<?php

declare(strict_types=1);

/**
 * WordPress 桩：只声明本包实际调用的 6 个 WP 全局函数（wp_unslash / status_header /
 * is_ssl / add_action / apply_filters / add_filter），外加一个测试驱动用的钩子注册表
 * `WordpressHooks`。
 *
 * **签名必须逐字忠实**：`tools/contracts/cases/Wordpress.php`（L1）会把本文件声明的
 * 每个函数与真实 `php-stubs/wordpress-stubs v7.1.0` 做反射对比（参数名 / 可选性 /
 * 默认值 / 返回类型），差一个字符就红。所以这里宁可「照抄」也不要顺手写好看点。
 *
 * 函数体只做记账，不做 SAPI 输出：真实 `status_header()` 会发状态行、`is_ssl()` 要读
 * $_SERVER，桩没有 HTTP 上下文可依——这正是 WordPress 卡的 L2 标 SKIP 的原因，
 * 拿一个自造桩去「证明」适配器语义只是循环论证。
 *
 * 不加 `function_exists` 守卫是刻意的：重复声明要炸就炸响（加载期 fatal 会立刻暴露
 * 文件被重复加载或与别处撞名），静默改用别人的实现会让「桩 = 真实 WP」失去哨兵。
 */

namespace {

    /**
     * 忠实于 `stripslashes_deep()`：标量原样返回，字符串 stripslashes，数组逐元素递归。
     */
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }

    /** 记录状态码，不真的发状态行（见文件头说明）。 */
    function status_header($code, $description = '')
    {
        \ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks::recordStatus((int) $code, (string) $description);
    }

    function is_ssl()
    {
        return \ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks::$ssl;
    }

    /**
     * 只支持 callable（真实 WP 也接受 `Class::method` 字符串与闭包）；本包只用
     * `[$object, 'method']` 与闭包两种形态。
     */
    function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1)
    {
        \ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks::addAction((string) $hook_name, $callback, (int) $priority);
    }

    /** 与 add_action 同形（真实 WP 里就是同一个 WP_Hook 机制，只是记账到过滤器表）。 */
    function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1)
    {
        \ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks::addFilter(
            (string) $hook_name,
            $callback,
            (int) $priority,
            (int) $accepted_args
        );
    }

    /**
     * `$args` 是变参（真实 WP 的声明就是这个形状）；本包只用两个参数的形式。
     */
    function apply_filters($hook_name, $value, ...$args)
    {
        return \ErikWang2013\Xhprof\Tests\Stubs\Framework\WordpressHooks::applyFilters((string) $hook_name, $value, $args);
    }
}

namespace ErikWang2013\Xhprof\Tests\Stubs\Framework {

    /**
     * WP 钩子注册表 + 状态记账。测试之间用 reset() 隔离。
     *
     * `do()` 按优先级升序触发、同优先级按注册顺序——与 `WP_Hook::do_action()` 的
     * ksort + 顺序遍历一致。
     */
    final class WordpressHooks
    {
        /** @var array<string, array<int, array<int, callable>>> hook → priority → callables */
        private static array $actions = [];

        /** @var array<string, array<int, array<int, array{callback: callable, accepted: int}>>> 过滤器表（与 $actions 分开存，apply_filters 的语义不同） */
        private static array $filters = [];

        /** @var array<int, array{code:int, description:string}> */
        public static array $statuses = [];

        /** is_ssl() 的返回值，测试里按需设置。 */
        public static bool $ssl = false;

        public static function reset(): void
        {
            self::$actions = [];
            self::$filters = [];
            self::$statuses = [];
            self::$ssl = false;
        }

        public static function addAction(string $hook, callable $callback, int $priority): void
        {
            self::$actions[$hook][$priority][] = $callback;
        }

        public static function addFilter(string $hook, callable $callback, int $priority, int $acceptedArgs): void
        {
            self::$filters[$hook][$priority][] = ['callback' => $callback, 'accepted' => $acceptedArgs];
        }

        /**
         * 忠实于 `WP_Hook::apply_filters()`：按优先级升序，前一个回调的返回值是下一个的输入；
         * 每个回调收到的是 `[值, ...$args]` 按 accepted_args 切片（默认 1 = 只收值本身，
         * 与真实 WP 的 `array_slice($this->args, 0, $accepted_args)` 同形）。
         *
         * @param array<int, mixed> $args
         */
        public static function applyFilters(string $hook, mixed $value, array $args): mixed
        {
            $byPriority = self::$filters[$hook] ?? [];
            ksort($byPriority);
            foreach ($byPriority as $callbacks) {
                foreach ($callbacks as $entry) {
                    $value = ($entry['callback'])(...array_slice(array_merge([$value], $args), 0, $entry['accepted']));
                }
            }

            return $value;
        }

        public static function recordStatus(int $code, string $description): void
        {
            self::$statuses[] = ['code' => $code, 'description' => $description];
        }

        /** 该钩子上挂了几回调（用于断言「enable=false 时没有注册 shutdown」）。 */
        public static function count(string $hook): int
        {
            $n = 0;
            foreach (self::$actions[$hook] ?? [] as $callbacks) {
                $n += count($callbacks);
            }

            return $n;
        }

        /** 触发钩子，返回回调数。 */
        public static function do(string $hook): int
        {
            $byPriority = self::$actions[$hook] ?? [];
            ksort($byPriority);
            $n = 0;
            foreach ($byPriority as $callbacks) {
                foreach ($callbacks as $callback) {
                    $callback();
                    $n++;
                }
            }

            return $n;
        }
    }
}
