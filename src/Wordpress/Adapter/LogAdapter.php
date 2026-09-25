<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Wordpress\Adapter;

use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

/**
 * WordPress 日志适配器：直接写 PHP 错误日志。
 *
 * 不复用 `wp_error_log()` 之类：WordPress 没有自己的通用 logger 抽象，
 * `error_log()` 正是 WP 插件记录内部错误的惯用出口，落点由站点的
 * `error_log` ini / WP_DEBUG_LOG 决定。
 */
class LogAdapter implements LoggerInterface
{
    public function error(string $message, array $context = []): void
    {
        error_log($context === [] ? $message : $message . ' ' . (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
