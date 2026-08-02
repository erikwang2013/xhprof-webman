<?php

return [
    'enable' => true,
    'time_limit' => 0,  //仅记录响应超过多少秒的请求  默认0记录所有
    'log_num' => 1000, //仅记录最近的多少次请求(最大值有待观察，看日志、查看响应时间) 默认1000
    'view_wtred' => 3, //列表耗时超过多少秒标红 默认3s
    'ignore_url_arr' => ['/xhprof'],  //忽略URL配置
    // 静态资源 URL（从包内直接提供，无需复制到 public）。须与路由中 xhprof-assets 的 path 一致
    'assets_url' => '/xhprof-assets',
];
