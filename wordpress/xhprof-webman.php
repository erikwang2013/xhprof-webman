<?php
/**
 * Plugin Name: xhprof-webman
 * Plugin URI: https://github.com/erikwang2013/xhprof-webman
 * Description: 基于 xhprof 扩展 + Redis 的 PHP 性能采样，提供浏览器报告页。作为 mu-plugin 使用：不需要激活、不进数据库、升级不丢。
 * Version: 3.0.6
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: erik
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 *
 * 安装：把本文件复制到 `wp-content/mu-plugins/`。包本体需已在站点根装了 composer 依赖。
 */

// 直接从浏览器访问本文件时终止（mu-plugin 由 WordPress 加载，此时 ABSPATH 一定已定义）。
defined('ABSPATH') || exit;

if (!class_exists(\ErikWang2013\Xhprof\Wordpress\XhprofPlugin::class)) {
    // mu-plugin 在 wp-settings.php:396 就被 include_once 了，而普通插件要到 :469、
    // plugins_loaded 要到 :506——此刻没有任何东西 require 过站点根的 composer 自动加载器。
    // WP 核心自己也不加载它（6.4.3 的 wp-settings.php 里根本没有 vendor/autoload.php 字样），
    // 所以必须在这里自己拉一次，否则下面实例化到的只是「类不存在」。
    // 已知上限：路径写死为 ABSPATH 之下（README 的安装流程就是站点级 composer）；Bedrock
    // 这类把 vendor 放在 ABSPATH 之外的布局会走到下面的告警分支，按告警提示改路径即可。
    $xhprofWebmanAutoload = ABSPATH . 'vendor/autoload.php';
    if (is_file($xhprofWebmanAutoload)) {
        require_once $xhprofWebmanAutoload;
    }
}

if (!class_exists(\ErikWang2013\Xhprof\Wordpress\XhprofPlugin::class)) {
    // 静默失败会让「采样怎么没数据」无从查起，直接致命错误又会让整站白屏——
    // 一个性能分析插件不值得拖垮站点，所以报警告（进 WP_DEBUG_LOG / 服务器错误日志）后跳过。
    trigger_error(
        'xhprof-webman: 找不到 ErikWang2013\Xhprof\Wordpress\XhprofPlugin。'
        . '请确认已 `composer require aaron-dev/xhprof-webman`，且站点的 vendor/autoload.php 位于 ABSPATH 之下。',
        E_USER_WARNING
    );

    return;
}

// 变量名带前缀：所有 mu-plugin 共享同一份全局作用域，$plugin 这种名字会被别的 mu-plugin 覆盖。
$xhprofWebmanPlugin = new \ErikWang2013\Xhprof\Wordpress\XhprofPlugin();
$xhprofWebmanPlugin->register();
