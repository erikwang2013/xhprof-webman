<?php


return [
    'enable' => true,
    'time_limit' => 0,  //仅记录响应超过多少秒的请求  默认0记录所有
    'log_num' => 1000, //仅记录最近的多少次请求(最大值有待观察，看日志、查看响应时间) 默认1000
    'view_wtred' => 3, //列表耗时超过多少秒标红 默认3s
    'ignore_url_arr' => ["/test"],  //忽略URL配置
];
