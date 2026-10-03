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
 * ten-framework adapters in place of the original PHP superglobals, an i18n
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
        [$run_id, $len] = XHProfRunsDefault::_saveToRedis($xhprof_data);
        XHProfRunsDefault::_checkLogNum($len);
        return $run_id;
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
     * @return string
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
        $http = Xhprof::getRequest()->header('x-forwarded-proto');
        $http = !empty($http) ? $http . "://" : "";
        $row = array(
            'request_uri' => $http . Xhprof::getRequest()->host() . Xhprof::getRequest()->uri(),
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
        return array($run_id, $len);
    }


    public static function list_runs()
    {
        // 列表链接与「对比选中」按钮共用一个 source：按钮把它经 data-source 交给 JS，
        // 拼出的 run1=/run2= 链接与行链接同形（少了它 diff 分支读不到 run 数据）。
        $source = 'xhprof_foo';
        //取所有请求数据
        $run_id_lists = Xhprof::getCache()->lRange(Xhprof::$key_prefix . ':run_id', 0, Xhprof::$log_num);
        $table_html = "";
        $keys = array_map(function ($run_id) {
            return Xhprof::$key_prefix . ":request_log:" . $run_id;
        }, $run_id_lists);
        // mget 批量取，消除 N+1；兼容部分驱动返回 [key=>value] 的形态
        $values = array_values(Xhprof::getCache()->mget($keys));
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
            . '<div class="xp-table-wrap"><table id="table_id_example" class="xp-table xp-runs-table">'
            . '<thead><tr>'
            // 表头复选框 = 全选（作用于当前全部行）
            . '<th><input type="checkbox" class="xp-run-all" aria-label="'
            . I18n::plain('runs.selectAll') . '"></th>'
            . '<th>' . I18n::plain('runs.col.method') . '</th>'
            . '<th>' . I18n::plain('runs.col.url') . '</th>'
            . '<th>' . I18n::plain('runs.col.time') . '</th>'
            . '<th>' . I18n::plain('runs.col.wt') . '</th>'
            . '<th>' . I18n::plain('runs.col.mu') . '</th>'
            . '<th>' . I18n::plain('runs.col.ip') . '</th>'
            . '</tr></thead><tbody>' . $table_html . '</tbody></table></div></div></div>';
        return $str_html;
    }
}
