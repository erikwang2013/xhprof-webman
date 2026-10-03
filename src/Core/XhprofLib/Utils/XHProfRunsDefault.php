<?php
/*
 * Derived from phacility/xhprof — Copyright (c) 2009 Facebook.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * CHANGES FROM UPSTREAM: namespaced under ErikWang2013\Xhprof\Core\XhprofLib,
 * twelve-framework adapters in place of the original PHP superglobals, an i18n
 * layer, and the fixes recorded in this repository's history. The rest of this
 * package (everything outside src/Core/XhprofLib/) is the MIT-licensed work of
 * this project — see LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\XhprofLib\Utils;

use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Core\Xhprof;

/**
 * Redis 里的三类键（前缀 Xhprof::$key_prefix，默认 xhprof）：
 *
 *   <prefix>:run_id            列表，只存 run_id 指针（lPush 进新头、rPop 掉旧尾）
 *   <prefix>:request_log:<id>  列表页一行数据（JSON），写入时带 log_ttl
 *   <prefix>:xhprof_log:<id>   原始采样数据（serialize），同样带 log_ttl
 *
 * 索引（run_id 列表）**刻意不加 TTL**：它只是指针、不装数据，长度受 log_num
 * 约束而有界（_checkLogNum 在每次写入后收敛），不需要靠过期来兜底。数据键
 * 过期后索引里留下的 id 成为悬空指针，list_runs() 取不到值即跳过、get_run()
 * 返回 false 走「数据已不存在」空态——都是既有行为。给索引设 TTL 只会把
 * 「数据还在、发现入口先没了」变成常态：列表页丢的正是本该可见的那些行。
 *
 * 清理入口（没有专用 API，键名即接口）：
 *   DEL <prefix>:run_id   —— 列表页立即清空；数据键另有 TTL，不删也会自行过期
 *   （全清则按上面的两种数据键模式删，模式匹配建议 redis-cli --scan，别用 KEYS）
 */
class XHProfRunsDefault implements XHProfRuns
{
    public static $dir;
    public function __construct($dir = null)
    {
        if (empty($dir)) {
            $dir = ini_get("xhprof.output_dir");
            if (empty($dir)) {
                $dir = "/tmp";
                XhprofLib::xhprof_error("Warning: Must specify directory location for XHProf runs. " .
                    "Trying {$dir} as default. You can either pass the " .
                    "directory location as an argument to the constructor " .
                    "for XHProfRuns_Default() or set xhprof.output_dir " .
                    "ini param.");
            }
        }
        XHProfRunsDefault::$dir = $dir;
    }

    public static function xhprof_valid_run_id($run_id): bool
    {
        return is_string($run_id) && preg_match('/^[a-f0-9]{13,32}$/', $run_id) === 1;
    }

    public static function xhprof_valid_source($source): bool
    {
        return is_string($source) && preg_match('/^[a-z0-9_\-\.]{1,64}$/', $source) === 1;
    }

    public static function get_run($run_id, $type, &$run_desc)
    {
        // 入口统一白名单校验，防止 run_id/source 注入 Redis key
        if (!self::xhprof_valid_run_id($run_id) || !self::xhprof_valid_source($type)) {
            return false;
        }
        // 描述文案走词表（单 run 报告页顶部必现；聚合时 use_script_name 也拿它当
        // 可见的 `__script::` 行名）。取 t() 而不是 plain()：调用方按 HTML 上下文
        // 自行 htmlspecialchars（profiler_report 就是这么做的），这里再转义会双重转义。
        $run_desc = sprintf(I18n::t('run.desc'), $type);
        $res = Xhprof::getCache()->get(Xhprof::$key_prefix . ':xhprof_log:' . $run_id);
        if (!is_string($res) || $res === '') {
            return false;
        }
        // 缓存值损坏（写了一半 / 被串键 / 手工改过 Redis）时 unserialize() 立
        // "Error at offset …" warning 并返回 false。warning 顺着渲染路径漏给宿主：
        // 升异常的宿主上就是整页 500 —— 一条坏记录把「优雅降级」变成了「白屏」。
        // `@` 收窄到这一句（PHPUnit / Laravel 等按 error_reporting() 判定的 handler
        // 都吃它），返回值判 false：本路径的写入方永远是 save_run() 的数组序列化，
        // 序列化出来的 `b:0;`（合法的 false）不会出现在这个键里。
        $data = @unserialize($res, ['allowed_classes' => false]);
        if ($data === false) {
            XhprofLib::xhprof_error("XHProf: unserialize failed for Run ID: $run_id");
            return false;
        }
        return $data;
    }

    public static function save_run($xhprof_data, $type, $run_id = null)
    {
        //根据响应时间判断是否需要记录
        if (Xhprof::$time_limit > 0 && ($xhprof_data['main()']['wt'] ?? 0) < (Xhprof::$time_limit * 1000 * 1000)) return false;
        //根据忽略配置判断是否忽略当前请求
        if (!XhprofLib::isIgnore()) return false;
        // 先写列表，再用 lPush 返回的真实长度决定是否裁剪
        [$run_id, $len, $row] = XHProfRunsDefault::_saveToRedis($xhprof_data);
        XHProfRunsDefault::_checkLogNum($len);
        // 慢请求告警/通知放在**落库成功之后**：执行到这里就意味着这条 run 已经在列表里
        // （有告警必有 run 可看），且裁剪已经跑完。time_limit（本方法第一行）与
        // sample_rate（XhprofProfiler::start()，更早）的过滤都发生在它之前——被它们
        // 过滤掉的请求既没有 run，也不会发告警。落库抛异常时（redis 连不上等）由
        // XhprofProfiler::stop() 兜底，这里同样到不了。
        XHProfRunsDefault::_notifySlowRun($run_id, $row, $xhprof_data);
        return $run_id;
    }

    /**
     * 慢请求（wt >= Xhprof::$view_wtred）的告警与 webhook 通知。
     *
     * 只由 save_run() 在落库之后调用。wt 取列表行里那个已四舍五入到 4 位的值，
     * 与列表页标红用的是同一个数（列表页是严格 `>`，这里按需求用 `>=`，边界差一档）。
     */
    protected static function _notifySlowRun($run_id, array $row, $xhprof_data)
    {
        $wt = (float) $row['wt'];
        // wt > 0：没采到 wt 的 run（空数据）不该告警。view_wtred 可以被配成 0，
        // 那时「>= 0」对空 run 也成立，靠这道守卫把两者分开。
        if ($wt <= 0 || $wt < Xhprof::$view_wtred) {
            return;
        }
        Xhprof::getLogger()?->error(sprintf(
            'xhprof: slow request wt=%.4fs uri=%s run_id=%s',
            $wt,
            $row['request_uri'],
            $run_id
        ));
        // webhook 是可选旁路：没配（null/空串）就什么都不做。键从配置适配器现读、
        // 代码内默认 null——配置里没有 webhook_url 时行为与加它之前完全一致。
        $webhook = Xhprof::getConfig()?->get('xhprof.webhook_url', null);
        if (is_string($webhook) && $webhook !== '') {
            XHProfRunsDefault::_fireWebhook($webhook, array(
                'run_id' => $run_id,
                'uri'    => (string) $row['request_uri'],
                'wt'     => $wt,
                'ct'     => (int) ($xhprof_data['main()']['ct'] ?? 0),
                'ip'     => (string) ($row['ip'] ?? ''),
                'time'   => (int) $row['create_time'],
            ));
        }
    }

    /**
     * 慢请求 webhook：fire-and-forget 的 POST JSON。
     *
     * 取舍：在请求周期内同步发 HTTP 是自伤——webhook 端点每次抖动都会变成一次业务
     * 请求的尾延迟。所以只做「连上、把请求头/体写进 socket、立刻 fclose」，不等响应、
     * 不读状态码，连接超时压到 200ms。**这不是队列**：进程退出/网络中断就是没发出去，
     * 没有重试、没有落盘补偿，端点慢或挂掉只会让这条通知丢失。任何失败（连不上、写
     * 失败、抛异常）只记一条 error 日志，绝不向调用方抛——照 XhprofProfiler::stop()
     * 的 catch 形态。已知边界：DNS 解析不受这 200ms 约束（webhook 配本机/IP 直连时无关）。
     */
    protected static function _fireWebhook(string $url, array $payload): void
    {
        try {
            $parts = parse_url($url);
            if (!is_array($parts) || empty($parts['host'])) {
                Xhprof::getLogger()?->error('xhprof: webhook_url is not a usable URL: ' . $url);
                return;
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
            if ($scheme === 'https') {
                $remote = 'ssl://' . $parts['host'];
                $port = (int) ($parts['port'] ?? 443);
            } elseif ($scheme === 'http') {
                $remote = (string) $parts['host'];
                $port = (int) ($parts['port'] ?? 80);
            } else {
                Xhprof::getLogger()?->error('xhprof: webhook_url scheme must be http or https: ' . $url);
                return;
            }
            $path = (string) ($parts['path'] ?? '/');
            if (isset($parts['query'])) {
                $path .= '?' . $parts['query'];
            }
            $body = (string) json_encode($payload);
            $request = 'POST ' . $path . " HTTP/1.1\r\n"
                . 'Host: ' . $parts['host'] . "\r\n"
                . "Content-Type: application/json\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\n"
                . "Connection: close\r\n\r\n"
                . $body;
            $fp = @fsockopen($remote, $port, $errno, $errstr, 0.2);
            if ($fp === false) {
                Xhprof::getLogger()?->error('xhprof: webhook connect failed: ' . $errstr);
                return;
            }
            @fwrite($fp, $request);
            @fclose($fp);   // 不等响应：写完即断，响应随 socket 一起丢弃
        } catch (\Throwable $e) {
            Xhprof::getLogger()?->error('xhprof: webhook delivery failed: ' . $e->getMessage());
        }
    }


    /**
     * 控制日志长度：超限时一趟收敛到 log_num。
     * @return bool
     */
    protected static function _checkLogNum($len)
    {

        // 以列表实际长度为唯一依据（$len 取自 lPush 的返回值），
        // 不再维护 run_id_num 计数器。计数器与列表必然漂移——例如手动 DEL
        // run_id 列表来"清理性能数据"后，计数器仍停在超限值，而旧实现里
        // rPop 空列表返回 false（不是 null），`!== null` 判真，于是每轮 save
        // 都会把刚写入的 run 立刻删除：采样永久静默失效，且每请求白付 6 次 Redis 往返。
        //
        // 超限部分在这一趟里全部弹掉（旧实现每条 save 只弹一条）。只弹一条时
        // 列表长度是不动点：push 一条再 pop 一条净变化为零——log_num 调小后
        // 永远收敛不到新上限，多出来的行与数据键一直挂在 Redis 里、列表页也照展示。
        // 收敛的 rPop 次数恰为「超出条数」，弹空立即停手（真 Redis rPop 空列表
        // 返回 false，不是 null）：既不会把仍在上限内的 run 删掉，也不会越界。
        // 多弹的部分只付一次往返，收敛后回到「每次写入至多 1 次 rPop」。
        // 索引因此有界，这也是它不需要 TTL 的理由（见类头注释）。
        $over = $len - (int) Xhprof::$log_num;
        if ($over <= 0) {
            return true;
        }

        $cache = Xhprof::getCache();
        $dead = [];
        for (; $over > 0; $over--) {
            $old_run_id = $cache->rPop(Xhprof::$key_prefix . ':run_id');
            if (empty($old_run_id)) {
                break;   // 列表比 $len 短（并发写入 / 手工改动），别再弹
            }
            $dead[] = Xhprof::$key_prefix . ':request_log:' . $old_run_id;
            $dead[] = Xhprof::$key_prefix . ':xhprof_log:' . $old_run_id;
        }
        if ($dead) {
            // 批量删：一次 DEL 带走所有键，别每条 run 各付一次往返
            $cache->del(...$dead);
        }
        return true;
    }

    /**
     * 数据存储至redis
     * @return array{0:string,1:int,2:array} run_id、列表真实长度、写入的列表行
     */
    protected static function _saveToRedis($xhprof_data)
    {

        $run_id = bin2hex(random_bytes(8));
        // lPush 返回推入后的列表长度，这就是列表的真实长度，无需另设计数器
        $len = Xhprof::getCache()->lPush(Xhprof::$key_prefix . ":run_id", $run_id);
        $wt = 0;   //请求总耗时
        $mu = 0;   //总消耗内存
        if (!empty($xhprof_data['main()']['wt']) && $xhprof_data['main()']['wt'] > 0) {
            $wt = round($xhprof_data['main()']['wt'] / 1000000, 4);        //1秒=1000毫秒=1000*1000微秒
            $mu = round($xhprof_data['main()']['mu'] / 1024 / 1024, 4);      //消耗内存 单位mb   1mb=1024kb=1024*1024b(字节)
        }

        $method = Xhprof::getRequest()->method();
        $uri = Xhprof::getRequest()->uri();
        if ($uri === '' && XhprofLib::sampleCliEnabled()) {
            // CLI（队列 worker / 定时任务 / artisan 等）没有 URI。合成 `cli:<脚本名>`
            // 作为 request_uri：列表页一眼看出这条 run 来自命令行，findPreviousRunForUri()
            // 也能按「同一脚本的上次运行」找回来。合成放在**落库这一层**——列表页与
            // 「上次运行」读的都是这里写下的值，在别处再拼一份必然对不上。
            // $argv 只在 CLI SAPI 存在（register_argc_argv 关掉时连 $_SERVER['argv'] 也没有），
            // 拿不到就退化成 'unknown'。sample_cli 关闭时走 else 分支，行为与加它之前逐字一致。
            $script = (string) ($_SERVER['argv'][0] ?? '');
            $request_uri = 'cli:' . ($script === '' ? 'unknown' : basename($script));
        } else {
            $http = Xhprof::getRequest()->header('x-forwarded-proto');
            $http = !empty($http) ? $http . "://" : "";
            $request_uri = $http . Xhprof::getRequest()->host() . $uri;
        }
        $row = array(
            'request_uri' => $request_uri,
            'method'      => $method,
            'wt'          => $wt,
            'mu'          => $mu,
            'ip'          => XhprofLib::xhprof_get_ip(),
            'create_time' => time(),  //请求时间
        );
        $key = Xhprof::$key_prefix . ':request_log:' . $run_id;  //请求列表log
        Xhprof::getCache()->set($key, json_encode($row), Xhprof::$log_ttl);
        $key = Xhprof::$key_prefix . ':xhprof_log:' . $run_id;   //列表存储log
        $xhprof_data_str = serialize($xhprof_data);
        if (!empty($xhprof_data_str)) Xhprof::getCache()->set($key, $xhprof_data_str, Xhprof::$log_ttl);
        return array($run_id, $len, $row);
    }


    public static function list_runs()
    {
        // 列表链接与「对比选中」按钮共用一个 source：按钮把它经 data-source 交给 JS，
        // 拼出的 run1=/run2= 链接与行链接同形（少了它 diff 分支读不到 run 数据）。
        $source = 'xhprof_foo';
        //取所有请求数据（一趟 lRange + 一趟 mget；状态条复用同一份，见 runsIndexAndLogs）
        list($run_id_lists, $values) = self::runsIndexAndLogs();
        $table_html = "";
        foreach ($run_id_lists as $i => $run_id) {
            if (!self::xhprof_valid_run_id($run_id)) continue;
            $res = $values[$i] ?? null;
            if (!$res) continue;
            $request_arr = json_decode($res, true);
            if (!is_array($request_arr)) continue;
            $wt = (float) ($request_arr['wt'] ?? 0);
            $mu = (float) ($request_arr['mu'] ?? 0);
            $wtClass = $wt > Xhprof::$view_wtred ? 'xp-wt-warn' : '';
            $tr = '<tr>'
                // 复选框列（对比入口）。value 是 run_id；$run_id 已在上面过了
                // xhprof_valid_run_id() 白名单（纯小写十六进制），无需再转义。
                // data-create-time 给 JS 定 run1/run2 的先后用：run1 必须是时间早的
                // 那条（基线），见 get_print_class 的 delta 语义。
                . '<td><input type="checkbox" class="xp-run-cb" value="' . $run_id
                . '" data-create-time="' . (int) ($request_arr['create_time'] ?? 0)
                . '" aria-label="' . I18n::plain('runs.selectRow') . '"></td>'
                . '<td>' . htmlspecialchars((string) $request_arr['method']) . '</td>'
                // 不带 `all=1`：列表页点进任一 run 默认渲染「前 100 + 显示全部」，
                // 而不是把整个函数表一次性铺出来（1 万函数实测 6.5MB HTML），
                // `all=1` 时标题里的「显示全部」链接也一并消失（full_report 的 limit 判定）。
                . '<td><a href="' . XhprofLib::report_url(array(
                    'run' => $run_id,
                    'source' => $source,
                    'requrl' => (string) $request_arr['request_uri'],
                )) . '">' . htmlspecialchars((string) $request_arr['request_uri']) . '</a></td>'
                . '<td>' . date('Y-m-d H:i:s', (int) ($request_arr['create_time'] ?? 0)) . '</td>'
                . '<td class="' . trim($wtClass) . '">' . $wt . '</td>'
                . '<td>' . $mu . '</td>'
                . '<td>' . htmlspecialchars((string) $request_arr['ip']) . '</td>'
                . '</tr>';
            $table_html .= $tr;
        }

        // 状态条的汇总与表格同源：数据已由上面那一趟 lRange + mget 拿到，这里只做归并。
        $overview = self::overviewFromRows($run_id_lists, $values);

        // 「对比选中」按钮**默认 disabled**：没选够两条时点击无意义，而在 JS 没跑起来
        // （词表/脚本加载失败、禁了 JS）时按钮也必须是不可用的——页面行为与加这个入口
        // 之前完全一致，单 run 链接照旧。提示文字由 JS 按选中数显隐，静态渲染的是初态。
        $str_html = '<div class="xp-main">'
            . '<div class="xp-card"><div class="xp-card-title">' . I18n::plain('runs.title') . '</div>'
            . '<div class="xp-runs-toolbar">'
            . '<button type="button" id="xp-compare-btn" class="xp-btn" disabled'
            . ' data-source="' . htmlspecialchars($source, ENT_QUOTES, 'UTF-8') . '">'
            . I18n::plain('runs.compare') . '</button>'
            // role=status（aria-live）：选中数变化时读屏会播报「请勾选两条…」
            . '<span class="xp-compare-hint" role="status">' . I18n::plain('runs.compareHint') . '</span>'
            . '</div>'
            // 状态条（A3）：已存条数 / 上限 / 保留天数 / 最早–最新时间。数字**不走**本地化
            // 格式化：列表页通篇是英式裸值（表体的 wt/mu 是 PHP 浮点裸串、DataTables 分页行
            // 不传 sInfoThousands），这里单独本地化反而造出 D9 要消灭的同页混写。
            // 放在工具区下方独立成行（不挤进 flex 行）：.xp-runs-toolbar 不换行、.xp-card 又
            // overflow:hidden，窄视口下状态条会被静默裁掉；块级行能正常折行。
            . '<div class="xp-runs-status" style="padding:0 20px 10px;color:var(--xp-text-muted);font-size:13px">'
            . sprintf(
                I18n::plain('runs.status'),
                $overview['count'],
                $overview['limit'],
                (int) round($overview['ttl'] / 86400),
                $overview['oldest'] !== null ? date('Y-m-d H:i:s', (int) $overview['oldest']) : '-',
                $overview['newest'] !== null ? date('Y-m-d H:i:s', (int) $overview['newest']) : '-'
            )
            . '</div>'
            // tabindex=0：宽度不够时这个容器横滚（overflow-x:auto），键盘用户得能聚焦进来
            // 用方向键滚；没有它滚动区对键盘不可达。
            . '<div class="xp-table-wrap" tabindex="0"><table id="table_id_example" class="xp-table xp-runs-table">'
            . '<thead><tr>'
            // 表头复选框 = 全选（作用于当前全部行）。scope="col" 给读屏标出「表头对应整列」；
            // 这个 `<th>` 里是复选框、没有文本，仍需 scope 才能把列关系连上。
            . '<th scope="col"><input type="checkbox" class="xp-run-all" aria-label="'
            . I18n::plain('runs.selectAll') . '"></th>'
            . '<th scope="col">' . I18n::plain('runs.col.method') . '</th>'
            . '<th scope="col">' . I18n::plain('runs.col.url') . '</th>'
            . '<th scope="col">' . I18n::plain('runs.col.time') . '</th>'
            . '<th scope="col">' . I18n::plain('runs.col.wt') . '</th>'
            . '<th scope="col">' . I18n::plain('runs.col.mu') . '</th>'
            . '<th scope="col">' . I18n::plain('runs.col.ip') . '</th>'
            . '</tr></thead><tbody>' . $table_html . '</tbody></table></div></div></div>';
        return $str_html;
    }

    /**
     * 列表页表格与状态条**共用**的取数：一趟 lRange + 一趟 mget。
     *
     * 抽出来是为了让状态条与表格同源：list_runs() 若先渲染表格、再调一次
     * runsOverview()，列表页的 Redis 往返就翻倍（两趟 lRange + 两趟 mget）。
     * 两个调用方各自再拿这份数组算自己要的东西。
     *
     * @return array{0: array<int, string>, 1: array<int, mixed>} [run_id 列表, 对应的 request_log 原始值（顺序对齐）]
     */
    private static function runsIndexAndLogs(): array
    {
        $run_id_lists = Xhprof::getCache()->lRange(Xhprof::$key_prefix . ':run_id', 0, Xhprof::$log_num);
        $keys = array_map(function ($run_id) {
            return Xhprof::$key_prefix . ":request_log:" . $run_id;
        }, $run_id_lists);
        // mget 批量取，消除 N+1；兼容部分驱动返回 [key=>value] 的形态
        return array($run_id_lists, array_values(Xhprof::getCache()->mget($keys)));
    }

    /**
     * 由 runsIndexAndLogs() 的原始数据归并出概览。语义（count 含悬空项、时间只取
     * mget 到的行）见 runsOverview() 的说明——那是调用契约，抽助手不改语义。
     */
    private static function overviewFromRows(array $run_id_lists, array $values): array
    {
        $oldest = null;
        $newest = null;
        foreach ($run_id_lists as $i => $run_id) {
            if (!self::xhprof_valid_run_id($run_id)) continue;
            $res = $values[$i] ?? null;
            if (!$res) continue;
            $request_arr = json_decode($res, true);
            if (!is_array($request_arr)) continue;
            $t = (int) ($request_arr['create_time'] ?? 0);
            if ($oldest === null || $t < $oldest) $oldest = $t;
            if ($newest === null || $t > $newest) $newest = $t;
        }
        return array(
            'count'  => count($run_id_lists),
            'limit'  => (int) Xhprof::$log_num,
            'oldest' => $oldest,
            'newest' => $newest,
            'ttl'    => (int) Xhprof::$log_ttl,
        );
    }

    /**
     * 列表概览（供列表页状态条用）：一趟 lRange + 一趟 mget，与 list_runs() 同一条
     * 数据路径（同一个 runsIndexAndLogs() 助手，列表页只花一趟），不额外扫 Redis。
     *
     * count 取**索引列表长度**——它就是状态条要表达的「占用 / 上限」，包含数据已过期、
     * 只剩指针的悬空项（索引刻意不带 TTL，见类头注释）；oldest/newest 只统计能 mget 到
     * create_time 的行，一条都没有时是 null。返回结构是 Display 批的调用契约，别改形状：
     *   ['count' => int, 'limit' => int, 'oldest' => int|null, 'newest' => int|null, 'ttl' => int]
     */
    public static function runsOverview(): array
    {
        list($run_id_lists, $values) = self::runsIndexAndLogs();
        return self::overviewFromRows($run_id_lists, $values);
    }

    /**
     * 「同 URL 的上次运行」：返回 create_time < $beforeTime 且 request_uri 与 $uri
     * **逐字相同**的最近一条 run_id；找不到返回 null。供报告页做「与上次运行对比」用。
     *
     * 索引列表头新尾旧（lPush 头插），所以从头部扫、第一条命中的就是最近的一条。
     * $uri 必须与落库时写进 request_uri 的字符串同形——它是
     * `[x-forwarded-proto://]host + uri`（见 _saveToRedis()），不是裸的请求路径；
     * CLI 采样（sample_cli）下则是合成的 `cli:<脚本名>`。
     * 扫描范围与 list_runs() 一致（一趟 lRange + 一趟 mget）。
     */
    public static function findPreviousRunForUri(string $uri, int $beforeTime): ?string
    {
        $run_id_lists = Xhprof::getCache()->lRange(Xhprof::$key_prefix . ':run_id', 0, Xhprof::$log_num);
        $keys = array_map(function ($run_id) {
            return Xhprof::$key_prefix . ":request_log:" . $run_id;
        }, $run_id_lists);
        $values = array_values(Xhprof::getCache()->mget($keys));
        foreach ($run_id_lists as $i => $run_id) {
            if (!self::xhprof_valid_run_id($run_id)) continue;
            $res = $values[$i] ?? null;
            if (!$res) continue;
            $request_arr = json_decode($res, true);
            if (!is_array($request_arr)) continue;
            if (!array_key_exists('request_uri', $request_arr)) continue;
            if ((string) $request_arr['request_uri'] !== $uri) continue;
            if ((int) ($request_arr['create_time'] ?? 0) >= $beforeTime) continue;
            return $run_id;
        }
        return null;
    }
}
