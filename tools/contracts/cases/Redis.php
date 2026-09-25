<?php

declare(strict_types=1);

/**
 * 真实 Redis 端到端 —— 真 Slim 应用 + 真 phpredis（127.0.0.1:6379）跑完整落库/读回链路。
 *
 * 为什么值得单独一张卡：前面所有 case 用的都是**内存假缓存**（Slim 卡里的匿名类），
 * 于是「适配器写出去的键长什么样、TTL 到底有没有设、列表页能不能把刚采到的数据读回来」
 * 全靠读源码推断。这里把 {@see src/Slim/Adapter/RedisAdapter.php} 接到真服务器上，断言
 * 链条是：**业务请求 → `<prefix>:run_id` 出现 run_id → 从列表页 HTML 里抓出 href →
 * 请求那个 href → 报告页渲染出真采样数据**。任何一环断了都是 FAIL。
 *
 * ---------------------------------------------------------------------------------------
 * 本卡实测出的两件「真实包/真服务器与直觉不一致」的事，都直接钉在下面的断言里：
 *
 *  1) **裸 phpredis 的 `mget([])` 返回 `false`，而不是 `[]`**（实测 `INFO commandstats`
 *     里 `mget` 的 calls 计数**零增长** —— 一个命令都没发）。声明 `: array` 的方法
 *     原样透传会抛 TypeError。适配器必须自己兜。`set($k,$v,0)` 同理：phpredis 会报
 *     "EXPIRE can't be < 1" **且不发送命令**，所以「TTL 0」这条路必须走普通 SET。
 *  2) **`new RedisAdapter(new \Redis())` 并不会惰性建连**：惰性建连的判据是
 *     `$this->redis === null`，注入一个**未连接**的实例等于把这条兜底路径关掉 —— 而它在
 *     生产路径上被 `XhprofProfiler::stop()` 的 Throwable 防线吞掉（只留一行日志），表现为
 *     **采样静默地永不落库**。注意「第一条命令因此失败」的具体形态**随 phpredis 版本变**
 *     （本机 5.3.7 抛 `RedisException: Redis server went away`；CI 的构建直接按默认连上了
 *     127.0.0.1:6379 并正常返回），所以本卡只钉我们的代码（注入的实例被原样使用、我们一次
 *     `connect()` 都不调），不冻 phpredis 的行为：注入的必须自己 connect，不注入的能自连。
 *
 * 前置：Redis 必须真的在 127.0.0.1:6379 上（contracts.yml 里有 `services: redis`；
 * 本机常驻）。**连不上就 FAIL，绝不 SKIP** —— EXPECTED_SKIPS 是签字常量，SKIP 数必须
 * 与环境无关；「没装 Redis 就跳过」会让这张卡在 CI 上永远绿着什么都不证明。
 * 本 case 内部没有不可验证的子项，skips 恒为 0。
 * ---------------------------------------------------------------------------------------
 */

return static function (): array {
    $autoload = contracts_dir() . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return [
            'status' => 'FAIL',
            'detail' => 'tools/contracts/vendor 未安装，先跑 composer install -d tools/contracts',
            'skips' => 0,
        ];
    }
    require_once $autoload;

    // 本项目 src/ 的 PSR-4 自注册 —— 与其余 case 同一条理由：不依赖主仓库 vendor。
    $repoRoot = contracts_repo_root();
    spl_autoload_register(static function (string $class) use ($repoRoot): void {
        $prefix = 'ErikWang2013\\Xhprof\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = $repoRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });

    $checks = 0;
    $failures = [];
    $expect = static function (string $label, mixed $actual, mixed $expected) use (&$checks, &$failures): void {
        $checks++;
        if ($actual !== $expected) {
            $failures[] = $label . '：得到 ' . var_export($actual, true) . '，期望 ' . var_export($expected, true);
        }
    };
    $expectContains = static function (string $label, string $haystack, string $needle) use (&$checks, &$failures): void {
        $checks++;
        if (strpos($haystack, $needle) === false) {
            $failures[] = $label . '：' . var_export($needle, true) . ' 不在 ' . var_export($haystack, true) . ' 里';
        }
    };
    // ================= 0. 前置：真服务器必须可用（缺 → FAIL，不 SKIP）=================
    if (!extension_loaded('redis')) {
        return [
            'status' => 'FAIL',
            'detail' => '缺 ext-redis：本卡是真实 Redis 端到端，不给假缓存留后门。'
                . 'contracts.yml 的 setup-php 里已声明 extensions: xhprof, redis。',
            'skips' => 0,
        ];
    }

    $redis = new \Redis();
    $connectError = '';
    $connected = false;
    try {
        $connected = $redis->connect('127.0.0.1', 6379, 1.0) === true && $redis->ping() === true;
    } catch (\Throwable $e) {
        $connectError = get_class($e) . ': ' . $e->getMessage();
    }
    if (!$connected) {
        return [
            'status' => 'FAIL',
            'detail' => '连不上 127.0.0.1:6379 的 Redis（本卡的前置）' . ($connectError !== '' ? '：' . $connectError : ''),
            'skips' => 0,
        ];
    }

    // 唯一 key_prefix：多项目共用一台 Redis 时靠它隔离，本卡靠它不碰别人的数据、
    // 也不被别人的数据干扰（同一台机器上并行跑环也不会互相看见）。
    $prefix = 'xhprof-contract-' . getmypid() . '-' . bin2hex(random_bytes(3));
    $cache = new \ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter($redis);

    /** 服务器自己数出来的命令调用次数（INFO commandstats）—— 用来证明「确实走了 SETEX」、
     *  「mget([]) 一个命令都没发」，而不是靠读源码推断谁调了谁。 */
    $cmdCalls = static function (string $cmd) use ($redis): int {
        $info = $redis->info('commandstats');
        foreach (explode(',', (string) ($info['cmdstat_' . $cmd] ?? '')) as $part) {
            if (strncmp($part, 'calls=', 6) === 0) {
                return (int) substr($part, 6);
            }
        }
        return 0;
    };

    // ================= 1. 真 Slim 应用 + 真 Redis：业务请求 → 落库 =================
    $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
    $app = new \Slim\App($psr17);
    $app->addRoutingMiddleware();
    $app->addErrorMiddleware(false, false, false);
    $app->get('/api/orders', static function ($request, $response) {
        usleep(2000);   // 一点真实耗时，保证 wt > 0
        $response->getBody()->write('business-ok');
        return $response;
    });
    $app->add(new \ErikWang2013\Xhprof\Slim\XhprofMiddleware(
        $app->getResponseFactory(),
        ['enable' => true, 'key_prefix' => $prefix, 'locale' => 'zh_CN'],
        $cache
    ));

    $dispatch = static fn (string $path): \Psr\Http\Message\ResponseInterface
        => $app->handle(new \Nyholm\Psr7\ServerRequest('GET', 'http://shop.example.com:8080' . $path));

    $business = $dispatch('/api/orders?marker=alpha');
    $expect('E2E 业务请求 200', $business->getStatusCode(), 200);
    $expect('E2E 业务响应体原样透传（中间件没吞响应）', (string) $business->getBody(), 'business-ok');

    $runs = $redis->lrange($prefix . ':run_id', 0, -1);
    $expect('E2E 一次业务请求恰好写进一条 run_id', count($runs), 1);
    $runId = (string) ($runs[0] ?? '');
    $expect('E2E run_id 是 16 位小写十六进制（get_run() 的 /^[a-f0-9]{13,32}$/ 白名单同形）', preg_match('/^[a-f0-9]{16}$/', $runId), 1);

    $rowRaw = $redis->get($prefix . ':request_log:' . $runId);
    $expect('E2E request_log:<id> 存在且是字符串（说明真写进了服务器）', is_string($rowRaw), true);
    $row = json_decode((string) $rowRaw, true);
    $expect('E2E request_log 是合法 JSON 对象（list_runs 要 json_decode 它）', is_array($row), true);

    // R-2 的端到端证据：请求 URI 里明明有 :8080，落库的展示文本里**不能**有端口。
    $expect(
        'E2E R-2：落库的 request_uri = host()(无端口) + uri()(path+query)',
        $row['request_uri'] ?? null,
        'shop.example.com/api/orders?marker=alpha'
    );
    $expect(
        'E2E R-2：落库的 request_uri 里不含 ":8080"',
        strpos((string) ($row['request_uri'] ?? ''), ':8080'),
        false
    );
    $expect('E2E method 落库', $row['method'] ?? null, 'GET');
    $expect('E2E create_time 是本次请求（±60s）', abs(time() - (int) ($row['create_time'] ?? 0)) < 60, true);
    $expect('E2E wt > 0（真采样到了耗时，不是空主调）', ((float) ($row['wt'] ?? 0)) > 0, true);
    $expect('E2E ip 落库（无 XFF 头时是适配器的兜底值）', $row['ip'] ?? null, '127.0.0.1');

    // TTL：log_ttl 默认 86400*7。大于 0 就说明 set(ttl>0) 那次调用真的在服务端设了过期。
    $rowTtl = $redis->ttl($prefix . ':request_log:' . $runId);
    $expect('E2E request_log 带 TTL（默认 log_ttl=604800 秒，抽样比上限）', $rowTtl > 0 && $rowTtl <= 604800, true);
    $logTtl = $redis->ttl($prefix . ':xhprof_log:' . $runId);
    $expect('E2E xhprof_log 也带 TTL（两份数据同一个 log_ttl）', $logTtl > 0 && $logTtl <= 604800, true);

    $profile = unserialize((string) $redis->get($prefix . ':xhprof_log:' . $runId), ['allowed_classes' => false]);
    $expect('E2E xhprof_log 反序列化出数组', is_array($profile), true);
    $expect('E2E 采样数据里有 main()（真 xhprof 结果落库，不是空数组）', isset($profile['main()']), true);

    // ================= 2. 报告页：列表页 → 抓 href → 报告页 =================
    $list = $dispatch('/xhprof');
    $listHtml = (string) $list->getBody();
    $expect('报告页短路：200', $list->getStatusCode(), 200);
    $expect('报告页短路：Content-Type 是 html（PSR-7 响应不带默认值，必须显式给）', $list->getHeaderLine('content-type'), 'text/html; charset=UTF-8');
    $expectContains('列表页 HTML 含刚采到的 run_id', $listHtml, $runId);
    $expectContains('列表页 HTML 含请求 URI 原文（list_runs 的链接文本 + href）', $listHtml, 'shop.example.com/api/orders?marker=alpha');
    $expectContains('列表页链接带 requrl（报告页的 URI 就是这么传过去的）', $listHtml, 'requrl=');

    // 从列表页**真的把 href 抓出来**再请求 —— 不是自己拼一个 report_url() 再断言它。
    $found = preg_match('#<a href="([^"]*run=' . preg_quote($runId, '#') . '[^"]*)"#', $listHtml, $m);
    $expect('能从列表页抓到该 run 的 href', $found, 1);
    // report_url() 的返回值落在 href="…" 属性上下文里，裸 & 被转义成了 &amp;。
    $href = html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8');
    $expectContains('href 指向报告页本身（report_path() 取自当前请求路径）', $href, '/xhprof?');

    $report = $app->handle(new \Nyholm\Psr7\ServerRequest('GET', 'http://shop.example.com:8080' . $href));
    $reportHtml = (string) $report->getBody();
    $expect('报告页（跟列表页的链接）200', $report->getStatusCode(), 200);
    $expectContains('报告页含 get_run() 拼的 run_desc', $reportHtml, 'XHProf Run (Namespace=xhprof_foo)');
    $expectContains('报告页含 main() 行（真采样数据渲染成了函数表）', $reportHtml, 'main()');
    $expectContains('报告页含 run_id', $reportHtml, $runId);
    $expect(
        '报告页含请求 URI（导航链接经 http_build_query 转义，故先 urldecode 再比）',
        strpos(urldecode($reportHtml), 'shop.example.com/api/orders?marker=alpha') !== false,
        true
    );
    $expect('报告页自身不产生采样（短路在 xhprofStart() 之前）', count($redis->lrange($prefix . ':run_id', 0, -1)), 1);

    // ignore_url_arr 的端到端：默认 ['/xhprof']，所以 URI 里带 '/xhprof' 的请求不落库。
    $impostor = $dispatch('/api/xhprof-data');
    $expect('ignored URI 的请求照常走业务（这里没路由，故 404）', $impostor->getStatusCode(), 404);
    $expect('ignore_url_arr 命中：该请求不落库（runs 仍是 1 条）', count($redis->lrange($prefix . ':run_id', 0, -1)), 1);

    // 这里刻意**不**再单加一条「本 case 的 run_id 不在默认前缀 xhprof:run_id 里」的隔离断言：
    // 它在真正该发威的变异下（key_prefix 被写死）是**哑的** —— 那时本前缀下一条记录都没有，
    // $runId 为 ''，严格 in_array 恒为 false，断言照样绿（实测）。写死前缀这件事由上面那条
    // 「恰好写进一条 run_id」抓住（变异矩阵 R6：那条断言就在失败清单首位）。
    // 同理不比较默认列表的**长度**：同机别的进程一写就漂（本机实测 235→236 就是我自己变异跑的）。

    // ================= 3. 原语语义（真服务器）=================
    $k1 = $prefix . ':prim:ttl';
    $setBefore = $cmdCalls('set');
    $setexBefore = $cmdCalls('setex');
    $cache->set($k1, 'v1', 5);
    $expect('原语 set(ttl>0)：值读得回来', $redis->get($k1), 'v1');
    $ttl = $redis->ttl($k1);
    $expect('原语 set(ttl>0)：服务端 TTL 落在 (0, 5]', $ttl > 0 && $ttl <= 5, true);
    $expect('原语 set(ttl>0)：服务端记到的 SETEX 调用 +1', $cmdCalls('setex') - $setexBefore, 1);
    $expect('原语 set(ttl>0)：普通 SET 调用 +0（走的是 SETEX，不是 SET 再 EXPIRE 两趟）', $cmdCalls('set') - $setBefore, 0);

    $k2 = $prefix . ':prim:nottl';
    $setBefore2 = $cmdCalls('set');
    $setexBefore2 = $cmdCalls('setex');
    $cache->set($k2, 'v2');   // ttl 缺省 → (int) null = 0 → 不带过期
    $expect('原语 set(ttl 缺省)：值读得回来', $redis->get($k2), 'v2');
    $expect('原语 set(ttl 缺省)：TTL = -1（永不过期，不是"0 秒后立刻消失"）', $redis->ttl($k2), -1);
    $expect(
        '原语 set(ttl 缺省)：普通 SET +1 且 SETEX +0（phpredis 对 ttl=0 会报 EXPIRE can\'t be < 1 且不发命令）',
        [$cmdCalls('set') - $setBefore2, $cmdCalls('setex') - $setexBefore2],
        [1, 0]
    );

    $mgetBefore = $cmdCalls('mget');
    $expect('原语对照：裸 phpredis 的 mget([]) 是 false（不是 []）', $redis->mget([]), false);
    $expect('原语对照：裸 phpredis 的 mget([]) 一个命令都没发', $cmdCalls('mget') - $mgetBefore, 0);
    $expect('原语 mget([])：适配器返回 []（声明 : array，透传 false 会抛 TypeError）', $cache->mget([]), []);
    $expect('原语 mget([])：适配器同样一个命令都没发', $cmdCalls('mget') - $mgetBefore, 0);
    $expect('原语 mget([k1,k2])：按入参顺序回值', $cache->mget([$k1, $k2]), ['v1', 'v2']);
    $expect('原语 mget：不存在的键占位 false（不塌缩、不丢位）', $cache->mget([$k1, $prefix . ':nope']), ['v1', false]);

    // ================= 4. 惰性建连的两种形态 =================
    //
    // 注入的实例**不会**被惰性建连（判据是 `=== null`，不是 `isConnected()`）。
    //
    // 但**不要再把「第一条命令是否抛异常」冻成期望**——那是 phpredis 的行为，且随版本/构建
    // 而变：本机 5.3.7 抛 `RedisException: Redis server went away`，CI 的构建却直接按默认
    // 连上 127.0.0.1:6379 正常返回（同一个断言在两地一红一绿，正是把库的行为冻成期望的
    // 典型症状；2026-09-25 真的在 CI 上红过一次）。所以这里只钉**我们的代码**：注入的客户端
    // 被原样使用，且我们一次 connect() 都不调。探针把 get() 也接管掉，于是既不碰网络、
    // 也不受 phpredis 内部行为影响 —— 在任何环境下都是同一个结论。
    //
    // 生产含义没变：调用方注入**未连接**的实例时，我们的惰性路径关着，落库失败会被
    // XhprofProfiler::stop() 的 Throwable 防线吞掉 → 采样静默地永不落库，所以调用方必须
    // 自己 connect()（四家直连适配器的注释里都写了这一条）。
    $probe = new class extends \Redis {
        public const SENTINEL = 'xhprof-probe-sentinel';

        public int $connectCalls = 0;

        public int $getCalls = 0;

        public function connect($host, $port = 6379, $timeout = 0.0, $persistent_id = null, $retry_interval = 0, $read_timeout = 0.0, $context = null): bool
        {
            $this->connectCalls++;
            return parent::connect($host, $port, $timeout, $persistent_id, $retry_interval, $read_timeout, $context);
        }

        public function get($key): mixed
        {
            $this->getCalls++;
            return self::SENTINEL;
        }
    };
    $injected = new \ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter($probe);
    $expect('注入的客户端被原样使用（命令落在它身上，不是被悄悄换掉）', $injected->get('anything'), \get_class($probe)::SENTINEL);
    $expect('注入的实例上适配器不自己 connect（惰性建连的判据是 === null）', [$probe->connectCalls, $probe->getCalls], [0, 1]);
    $expect('不注入（new RedisAdapter()）时惰性建连可用：读得到刚写的键', (new \ErikWang2013\Xhprof\Slim\Adapter\RedisAdapter())->get($k2), 'v2');

    // ================= 5. 收尾：键的形态与清理 =================
    $expect('原语 del(...)：一次删多个键并返回删除个数', $cache->del($k1, $k2, $prefix . ':nope'), 2);

    $left = $redis->keys($prefix . '*');
    sort($left);
    $expect('一次采样在 Redis 里恰好留下 3 个键（run_id + request_log + xhprof_log）', count($left), 3);
    $expectContains('留下的键里有 run_id 列表', implode(' ', $left), $prefix . ':run_id');
    // 有键才调 del()：phpredis 的 del() **至少要一个参数**，传空会抛 ArgumentCountError，
    // 那会在 E2E 部分已经失败的情况下把上面的断言清单整个吞掉（只剩一条异常信息）。
    if ($left !== []) {
        $expect('收尾：del 把本 case 的键全删干净（不给 CI 留垃圾）', $cache->del(...array_values($left)), count($left));
    }
    $expect('收尾：Redis 上已无本 case 的键', $redis->keys($prefix . '*'), []);

    if ($failures !== []) {
        return [
            'status' => 'FAIL',
            'detail' => count($failures) . ' 项不符：' . implode('；', array_slice($failures, 0, 10)),
            'skips' => 0,
        ];
    }

    return [
        'status' => 'PASS',
        'detail' => 'L2 真 Slim ' . (\Composer\InstalledVersions::getPrettyVersion('slim/slim') ?? '?')
            . ' + 真 phpredis ' . (phpversion('redis') ?: '?') . ' ' . '（127.0.0.1:6379）端到端，共 '
            . $checks . " 项断言通过：业务请求 → run_id/request_log/xhprof_log 三键落库（含 R-2 无端口、TTL 生效）"
            . ' → 列表页含 run_id 与请求 URI → 从列表页抓 href 请求报告页 → run_desc/main()/URI 均渲染出来'
            . '；ignore_url_arr 命中不落库；原语 set(ttl>0)=SETEX、set(ttl 缺省)=SET 且永不过期、'
            . '裸 phpredis mget([])=false 且零命令 vs 适配器 []；惰性建连只在未注入时生效',
        'skips' => 0,
    ];
};
