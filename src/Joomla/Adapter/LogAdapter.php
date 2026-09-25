<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Joomla\Adapter;

use Joomla\CMS\Log\Log;
use ErikWang2013\Xhprof\Core\Contract\LoggerInterface;

/**
 * 契约 LoggerInterface → Joomla 的日志入口。
 *
 * Joomla 的日志是静态的 Joomla\CMS\Log\Log（CMS 内核组件，不在可安装的 composer 包里），
 * 而 CMSApplicationInterface 并未声明 getLogger()，拿不到可靠的 PSR-3 logger，
 * 故直接走 Log::add()。
 */
class LogAdapter implements LoggerInterface
{
    private const CATEGORY = 'xhprof';

    public function error(string $message, array $context = []): void
    {
        // 第 4 个参数 $date 传 null（用当前时间）。第 5 个 $context 是 J4+ 才有的，
        // 目标版本是 J4.4/J5，故直接传。
        Log::add($message, Log::ERROR, self::CATEGORY, null, $context);
    }
}
