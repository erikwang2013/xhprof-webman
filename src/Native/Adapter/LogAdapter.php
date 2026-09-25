<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Native\Adapter;

use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

/**
 * 原生 PHP 日志适配器：直接写 PHP 错误日志（与 WordPress / Yii3 两家同口径）。
 *
 * 原生应用没有 logger 抽象可依赖（PSR-3 是**建议**依赖，不是本包的 require），
 * `error_log()` 是唯一保证存在的出口，落点由站点的 `error_log` ini 决定。
 * 需要别的出口时从入口类的第三个参数注入自定义 LoggerInterface。
 */
class LogAdapter implements LoggerInterface
{
    public function error(string $message, array $context = []): void
    {
        error_log($context === [] ? $message : $message . ' ' . (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
