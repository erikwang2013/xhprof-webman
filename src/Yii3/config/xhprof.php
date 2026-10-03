<?php

declare(strict_types=1);

/**
 * Yii3 默认配置。改法有两条，效果等价：
 *  - 直接把数组传给 `XhprofMiddleware` 构造函数的第二个参数（推荐，见 README）；
 *  - 或在 DI 里用 array definition 的 `__construct()['config']`。
 *
 * 合并语义是 `array_replace`（整段替换同名字段），不是递归合并——`ignore_url_arr`
 * 这类列表键必须整段替换，否则用户写 `['/admin']` 会与默认值逐下标合并成静默失配的列表。
 *
 * 键集与其余框架的 config/xhprof.php 保持一致。Yii3 特有的 Redis 连接参数
 * （`redis` 子数组，可选）不在这里——它不是共用配置项，见 README「Yii3」一节。
 */

return [
    'enable' => true,
    'sample_rate' => 1.0,  //按比例采样：每个请求以该概率记录，0.05 = 5% 请求被采样；调低它以降低采样开销；1.0 = 全采(默认)，<=0 = 不采；只在采样入口处生效
    'trigger_token' => null,  //按需触发采样：配置后请求带 X-Xhprof-Token: <值> 头即强制采样(无视 sample_rate，含 0)；null = 关闭
    'auth_basic' => null,  //HTTP Basic 鉴权，形如 "user:password"（第一个冒号分隔，密码可含冒号）；与 auth_token 是「任一配置即生效、任一通过即放行」，两者都不配则报告页不鉴权；null/空 = 关闭。注意 Apache+CGI/FastCGI 默认剥离 Authorization 头（需 CGIPassAuth On 或等效转发），nginx+php-fpm 不受此限
    'ip_allowlist' => [],  //报告页 IP 白名单：逐字比对（不支持 CIDR 网段、不做 IPv6 规范化，2001:0db8::1 与 2001:db8::1 是两个不同的字符串）；空数组 = 不启用；写得不是数组时 fail closed（拒绝访问）。判定取适配器的 getRealIp()，多数适配器会无条件取转发头，配合 trusted_proxies 声明
    'trusted_proxies' => [],  //部署声明，不是技术强制：声明了 ip_allowlist 才接受来自 X-Forwarded-For/X-Real-IP 的客户端 IP。多数适配器无条件取转发头，声明了也挡不住伪造 XFF——仅当部署在可信代理之后才安全
    'webhook_url' => null,  //慢请求（wt >= view_wtred）通知：落库后 POST JSON 到该地址；留空/null = 不发送。这不是队列：不等响应、没有重试、没有落盘补偿，端点慢或挂掉只会让这条通知丢失
    'sample_cli' => false,  //CLI/无 HTTP 请求也采样：true 时落库的 request_uri 记为 cli:<脚本名>；false = 一律忽略（默认，含队列 worker 与定时任务）
    'symbol_lookup_url' => null,  //源码链接模板：报告页给函数名加链接，指向 <模板>?symbol=<urlencoded 函数名>；null/空 = 不显示链接
    'max_runs_per_minute' => null,  //自适应预算：每分钟最多记录多少条，超出不采；null/非正数 = 关闭（默认）。缓存不可用时照常按 sample_rate 采样（fail-open）；触发采样不受它限制
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
