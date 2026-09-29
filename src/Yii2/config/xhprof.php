<?php

declare(strict_types=1);

/**
 * Yii2 默认配置。覆盖方式是把数组写进 `config/web.php` 的 bootstrap 数组定义：
 *
 *     'bootstrap' => [
 *         [
 *             'class' => \ErikWang2013\Xhprof\Yii2\XhprofBootstrap::class,
 *             'config' => ['auth_token' => 'xxx'],
 *         ],
 *     ],
 *
 * 合并语义是 `array_replace`（整段替换同名字段），不是递归合并——`ignore_url_arr`
 * 这类列表键必须整段替换，否则用户写 `['/admin']` 会与默认值逐下标合并成静默失配的列表。
 *
 * 键集与其余框架的 config/xhprof.php 保持一致。Yii2 特有的 Redis 连接参数
 * （`redis` 子数组，可选）不在这里——它不是共用配置项，见 README「Yii2」一节。
 */

return [
    'enable' => true,
    'time_limit' => 0,
    'log_num' => 1000,
    'view_wtred' => 3,
    'ignore_url_arr' => ['/xhprof'],
    'assets_url' => '/xhprof-assets',
    'auth_token' => null,  //设置后报告页必须带 ?token=xxx 才能访问，null 表示不鉴权
    'key_prefix' => 'xhprof',  //Redis key 前缀，多项目共用 Redis 时建议改掉
    'log_ttl' => 86400 * 7,  //性能数据保留时间(秒)，默认7天
    'locale' => null,  //报告页语言：zh_CN/en/ko/ru/de/fr/es/pt/ar/hi/bn/id/ja；null = 跟随浏览器 Accept-Language，都没有则中文
];
