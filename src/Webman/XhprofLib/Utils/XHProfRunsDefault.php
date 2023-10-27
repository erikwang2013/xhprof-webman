<?php

namespace Erik\Xhprof\Webman\XhprofLib\Utils;

use support\Redis;

class XHProfRunsDefault implements XHProfRuns
{

    private static $dir = '';
    private static $suffix = 'xhprof';


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
        self::$dir = $dir;
    }

    public static function get_run($run_id, $type, &$run_desc)
    {
        $run_desc = "XHProf Run (Namespace=$type)";
        $res = Redis::get(X_KEY_PREFIX . ':xhprof_log:' . $run_id);
        return unserialize($res);
    }

    //实现接口方法
    public static function save_run($xhprof_data, $type, $run_id = null)
    {
        //根据响应时间判断是否需要记录
        if (X_TIME_LIMIT > 0 && $xhprof_data['main()']['wt'] < (X_TIME_LIMIT * 1000 * 1000)) return false;
        //根据忽略配置判断是否忽略当前请求
        if (!XhprofLib::isIgnore()) return false;
        //控制日志长度
        self::_checkLogNum();
        //数据存储至redis
        $run_id = self::_saveToRedis($xhprof_data);
        return $run_id;
    }


    /**
     * 控制日志长度
     * @return bool
     */
    protected static function _checkLogNum()
    {

        $num = Redis::incr(X_KEY_PREFIX . ":run_id_num");
        if ($num > X_LOG_NUM) {
            $old_run_id = Redis::rpop(X_KEY_PREFIX . ':run_id');
            Redis::delete(X_KEY_PREFIX . ':request_log:' . $old_run_id);
            Redis::delete(X_KEY_PREFIX . ':xhprof_log:' . $old_run_id);
            Redis::decr(X_KEY_PREFIX . ':run_id_num');  //计数-1
        }
        return true;
    }

    /**
     * 数据存储至redis
     * @return string
     */
    protected static function _saveToRedis($xhprof_data)
    {

        $run_id = uniqid();
        Redis::lPush(X_KEY_PREFIX . ":run_id", $run_id);
        $wt = 0;   //请求总耗时
        $mu = 0;   //总消耗内存
        if (!empty($xhprof_data['main()']['wt']) && $xhprof_data['main()']['wt'] > 0) {
            $wt = round($xhprof_data['main()']['wt'] / 1000000, 4);        //1秒=1000毫秒=1000*1000微秒
            $mu = round($xhprof_data['main()']['mu'] / 1024 / 1024, 4);      //消耗内存 单位mb   1mb=1024kb=1024*1024b(字节)
        }

        $method = XhprofLib::getRequest()->method();
        $http = XhprofLib::getRequest()->header('x-forwarded-proto');
        $http = !empty($http) ? $http . "://" : "";
        $row = array(
            'request_uri' => $http . XhprofLib::getRequest()->host() . XhprofLib::getRequest()->uri(),
            'method'      => $method,
            'wt'          => $wt,
            'mu'          => $mu,
            'ip'          => XhprofLib::xhprof_get_ip(),
            'create_time' => time(),  //请求时间
        );
        $key = X_KEY_PREFIX . ':request_log:' . $run_id;  //请求列表log
        Redis::set($key, json_encode($row));
        $key = X_KEY_PREFIX . ':xhprof_log:' . $run_id;   //列表存储log
        $xhprof_data_str = serialize($xhprof_data);
        if (!empty($xhprof_data_str)) Redis::set($key, $xhprof_data_str);
        return $run_id;
    }



    public static function list_runs2()
    {
        $echo_page = "<meta charset='utf-8'>";
        $echo_page .= "<hr/>Existing runs:\n<ul>\n";
        $echo_page .= '<li><small class="small_filemtime">请求时间</small><small class="small_wt">耗时(s)</small><small class="small_wt">内存(MB)</small><small class="small_log">xhprof日志</small><small class="small_method">Method</small><small>请求url</small></li>';

        //取所有请求数据
        $run_id_lists = Redis::lrange(X_KEY_PREFIX . ':run_id', 0, X_LOG_NUM);
        foreach ($run_id_lists as $run_id) {
            $res = Redis::get(X_KEY_PREFIX . ":request_log:" . $run_id);
            if (!$res) continue;
            $request_arr = json_decode($res, true);
            if (!is_array($request_arr)) continue;
            //耗时是否标红显示
            $wtClass = $request_arr['wt'] > X_VIEW_WTRED ? "red" : "";
            $echo_page .= '<li><small class="small_filemtime">'
                . date("Y-m-d H:i:s", $request_arr['create_time'])
                . '</small><small class="small_wt ' . $wtClass . '">' . $request_arr['wt'] . '</small></small><small class="small_wt">' . $request_arr['mu'] . '</small><small class="small_log"><a href="' . htmlentities($_SERVER['SCRIPT_NAME'])
                . '?run=' . $run_id . '&source=xhprof_foo&requrl=' . urlencode($request_arr['request_uri']) . '">'
                . $run_id . "</a></small>"
                . '<small class="small_method">' . $request_arr['method'] . '</small>'
                . "<small>" . $request_arr['request_uri'] . "</small></li>\n";
        }
        $echo_page .= "</ul>\n";
        return $echo_page;
    }

    public static function list_runs()
    {
        //取所有请求数据
        $run_id_lists = Redis::lrange(X_KEY_PREFIX . ':run_id', 0, X_LOG_NUM);
        $table_html = "";
        foreach ($run_id_lists as $run_id) {
            $res = Redis::get(X_KEY_PREFIX . ":request_log:" . $run_id);
            if (!$res) continue;
            $request_arr = json_decode($res, true);
            if (!is_array($request_arr)) continue;
            //耗时是否标红显示
            $wtClass = $request_arr['wt'] > X_VIEW_WTRED ? "red" : "";
            $http = XhprofLib::getRequest()->header('x-forwarded-proto');
            $http = !empty($http) ? $http . ":" : "http:";
            $path = $http . XhprofLib::getRequest()->url();
            $tr = '<tr>'
                . '<td>' . $request_arr['method'] . '</td>'
                . '<td><a href="' . htmlentities($path) . '?all=1&run=' . $run_id . '&source=xhprof_foo&requrl=' . urlencode($request_arr['request_uri']) . '">' . $request_arr['request_uri'] . "</a></td>"
                . '<td>' . date("Y-m-d H:i:s", $request_arr['create_time']) . '</td>'
                . '<td class="' . $wtClass . '">' . $request_arr['wt'] . '</small></small>'
                . '<td>' . $request_arr['mu'] . '</td>'
                . '<td>' . $request_arr['ip'] . '</td>'
                . '</tr>';
            $table_html .= $tr;
        }

        $str_html = <<<HTML
<div class="container-fluid" style="width: 90%">
<div class="row">
<div class="col-xs-12">
<!--第二步：添加如下 HTML 代码-->
<table id="table_id_example" class="table table-bordered table-hover">
    <thead>
        <tr>
            <th width="40">方法</th>
            <th>请求地址</th>
            <th>请求时间</th>
            <th width="90">运行耗时(s)</th>
            <th width="100">内存占用(Mb)</th>
            <th width="100">IP地址</th>
        </tr>
    </thead>
    <tbody>
        {$table_html}
    </tbody>
</table>
</div>
</div>
</div>
HTML;
        return $str_html;
    }
}
