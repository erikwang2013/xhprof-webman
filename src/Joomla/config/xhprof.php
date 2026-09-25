<?php

declare(strict_types=1);

// Joomla 的默认配置。用户改配置有两条路（都不读数据库）：
//   1) 在站点根放一个 xhprof.php（内容形如 `<?php return ['key_prefix' => 'xxx'];`），
//      只有写进去的 key 会覆盖这里的默认值；
//   2) 自己构造 ErikWang2013\Xhprof\Joomla\Adapter\ConfigAdapter 时把覆盖数组传进去。
// 刻意不用插件参数（#__extensions.params 要查库，而这份配置每个请求都要读）。
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
