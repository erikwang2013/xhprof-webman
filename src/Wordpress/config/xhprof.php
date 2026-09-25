<?php

return [
    'enable' => true,
    'time_limit' => 0,
    'log_num' => 1000,
    'view_wtred' => 3,
    // 排除高频路径靠这里（isIgnore() 对 uri() 做子串匹配，不改代码就能生效），
    // 例如加上 '/wp-cron.php'、'/admin-ajax.php'、'/wp-json'。
    'ignore_url_arr' => ['/xhprof'],
    'assets_url' => '/xhprof-assets',
    'auth_token' => null,  //设置后报告页必须带 ?token=xxx 才能访问，null 表示不鉴权
    'key_prefix' => 'xhprof',  //Redis key 前缀，多站点/多项目共用 Redis 时建议改掉
    'log_ttl' => 86400 * 7,  //性能数据保留时间(秒)，默认7天
    'locale' => null,  //报告页语言：zh_CN/en/ko/ru/de/fr/es/pt/ar/hi/bn/id/ja；null = 跟随浏览器 Accept-Language，都没有则中文
];
