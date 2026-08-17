<?php

return [
    'enable' => true,
    'time_limit' => 0,  //仅记录响应超过多少秒的请求  默认0记录所有
    'log_num' => 1000, //仅记录最近的多少次请求(最大值有待观察，看日志、查看响应时间) 默认1000
    'view_wtred' => 3, //列表耗时超过多少秒标红 默认3s
    'ignore_url_arr' => ['/xhprof'],  //忽略URL配置
    // 静态资源 URL（从包内直接提供，无需复制到 public）。须与路由中 xhprof-assets 的 path 一致
    'assets_url' => '/xhprof-assets',
    'auth_token' => null,  //设置后报告页必须带 ?token=xxx 才能访问，null 表示不鉴权
    'key_prefix' => 'xhprof',  //Redis key 前缀，多项目共用 Redis 时建议改掉
    'log_ttl' => 86400 * 7,  //性能数据保留时间(秒)，默认7天
];
