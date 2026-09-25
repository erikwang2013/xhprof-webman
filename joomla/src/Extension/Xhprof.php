<?php

/**
 * 插件类入口：把 Joomla 惯例的插件类名（`xhprof.xml` 的 <namespace> 指向的名字）
 * 别名到包体里的实现。
 *
 * 实现在包里（vendor/aaron-dev/xhprof-webman/src/Joomla/Extension/Xhprof.php），
 * 本文件是**拷贝**到 plugins/system/xhprof/ 的那一份。两份文件位置不同、autoload
 * 不共享，所以这里不重复写实现，只做别名——避免同一份逻辑有两个会漂移的副本。
 *
 * 直接从浏览器访问本文件时终止（Joomla 装载本文件时 _JEXEC 一定已定义）。
 */
defined('_JEXEC') or die;

if (!class_exists(\ErikWang2013\Xhprof\Joomla\Extension\Xhprof::class)) {
    // 包由 composer 装进站点根的 vendor/，而插件目录是拷过来的，此刻站点根的
    // autoloader 未必被 require 过（与 WordPress mu-plugin 同一处境），自己拉一次。
    $xhprofWebmanAutoload = JPATH_ROOT . '/vendor/autoload.php';

    if (is_file($xhprofWebmanAutoload)) {
        require_once $xhprofWebmanAutoload;
    }
}

if (!class_exists(\ErikWang2013\Xhprof\Joomla\Extension\Xhprof::class)) {
    // 到这里说明 composer 依赖没装好，插件不可能工作。宁可响亮地失败，也不要静默
    // 什么都不做——"采样怎么没数据"比一个明确的错误难查得多。
    throw new \RuntimeException(
        'xhprof-webman: 找不到 ErikWang2013\Xhprof\Joomla\Extension\Xhprof。'
        . '请确认已 `composer require aaron-dev/xhprof-webman`，且站点的 vendor/autoload.php 位于 JPATH_ROOT 之下。'
    );
}

class_alias(\ErikWang2013\Xhprof\Joomla\Extension\Xhprof::class, 'Joomla\Plugin\System\Xhprof\Extension\Xhprof');
