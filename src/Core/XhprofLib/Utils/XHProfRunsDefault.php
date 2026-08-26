<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\XhprofLib\Utils;

use ErikWang2013\Xhprof\Core\Xhprof;

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
        $run_desc = "XHProf Run (Namespace=$type)";
        $res = Xhprof::getCache()->get(Xhprof::$key_prefix . ':xhprof_log:' . $run_id);
        if (!is_string($res) || $res === '') {
            return false;
        }
        return unserialize($res, ['allowed_classes' => false]);
    }

    //实现接口方法
    public static function save_run($xhprof_data, $type, $run_id = null)
    {
        //根据响应时间判断是否需要记录
        if (Xhprof::$time_limit > 0 && $xhprof_data['main()']['wt'] < (Xhprof::$time_limit * 1000 * 1000)) return false;
        //根据忽略配置判断是否忽略当前请求
        if (!XhprofLib::isIgnore()) return false;
        //控制日志长度
        XHProfRunsDefault::_checkLogNum();
        //数据存储至redis
        $run_id = XHProfRunsDefault::_saveToRedis($xhprof_data);
        return $run_id;
    }


    /**
     * 控制日志长度
     * @return bool
     */
    protected static function _checkLogNum()
    {

        $num = Xhprof::getCache()->incr(Xhprof::$key_prefix . ":run_id_num");
        if ($num > Xhprof::$log_num) {
            $old_run_id = Xhprof::getCache()->rPop(Xhprof::$key_prefix . ':run_id');
            if ($old_run_id !== null) {
                Xhprof::getCache()->del(
                    Xhprof::$key_prefix . ':request_log:' . $old_run_id,
                    Xhprof::$key_prefix . ':xhprof_log:' . $old_run_id
                );
                Xhprof::getCache()->decr(Xhprof::$key_prefix . ':run_id_num');  //计数-1
            }
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
        Xhprof::getCache()->lPush(Xhprof::$key_prefix . ":run_id", $run_id);
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
        $ttl = 86400 * 7;  //数据保留时间，默认7天，可用配置 xhprof.log_ttl 覆盖
        $cfg = Xhprof::getConfig();
        if ($cfg !== null) {
            $ttl = (int) $cfg->get('xhprof.log_ttl', 86400 * 7);
        }
        Xhprof::getCache()->set($key, json_encode($row), $ttl);
        $key = Xhprof::$key_prefix . ':xhprof_log:' . $run_id;   //列表存储log
        $xhprof_data_str = serialize($xhprof_data);
        if (!empty($xhprof_data_str)) Xhprof::getCache()->set($key, $xhprof_data_str, $ttl);
        return $run_id;
    }


    public static function list_runs()
    {
        //取所有请求数据
        $run_id_lists = Xhprof::getCache()->lRange(Xhprof::$key_prefix . ':run_id', 0, Xhprof::$log_num);
        $table_html = "";
        $keys = array_map(function ($run_id) {
            return Xhprof::$key_prefix . ":request_log:" . $run_id;
        }, $run_id_lists);
        // mget 批量取，消除 N+1；兼容部分驱动返回 [key=>value] 的形态
        $values = array_values(Xhprof::getCache()->mget($keys));
        foreach ($run_id_lists as $i => $run_id) {
            $res = $values[$i] ?? null;
            if (!$res) continue;
            $request_arr = json_decode($res, true);
            if (!is_array($request_arr)) continue;
            $wtClass = $request_arr['wt'] > Xhprof::$view_wtred ? 'xp-wt-warn' : '';
            $http = Xhprof::getRequest()->header('x-forwarded-proto');
            $http = !empty($http) ? $http . ":" : "http:";
            $path = $http . Xhprof::getRequest()->url();
            $tr = '<tr>'
                . '<td>' . htmlspecialchars($request_arr['method']) . '</td>'
                . '<td><a href="' . htmlspecialchars($path) . '?all=1&run=' . $run_id . '&source=xhprof_foo&requrl=' . urlencode($request_arr['request_uri']) . '">' . htmlspecialchars($request_arr['request_uri']) . '</a></td>'
                . '<td>' . date('Y-m-d H:i:s', $request_arr['create_time']) . '</td>'
                . '<td class="' . trim($wtClass) . '">' . $request_arr['wt'] . '</td>'
                . '<td>' . $request_arr['mu'] . '</td>'
                . '<td>' . htmlspecialchars($request_arr['ip']) . '</td>'
                . '</tr>';
            $table_html .= $tr;
        }

        $str_html = '<div class="xp-main">'
            . '<div class="xp-card"><div class="xp-card-title">请求记录</div>'
            . '<div class="xp-table-wrap"><table id="table_id_example" class="xp-table xp-runs-table">'
            . '<thead><tr>'
            . '<th>方法</th><th>请求地址</th><th>请求时间</th><th>耗时(s)</th><th>内存(Mb)</th><th>IP</th>'
            . '</tr></thead><tbody>' . $table_html . '</tbody></table></div></div></div>';
        return $str_html;
    }
}
