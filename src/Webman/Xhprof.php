<?php

declare(strict_types=1);

namespace Aaron\Xhprof\Webman;

use Aaron\Xhprof\Webman\XhprofLib\Utils\XHProfRunsDefault;
use Aaron\Xhprof\Webman\XhprofLib\Display\XhprofDisplay;

class Xhprof
{

    public static $time_limit = 0;  //仅记录响应超过多少秒的请求  默认0记录所有
    public static $ignore_url_arr =["/test"];  //忽略URL配置
    public static $key_prefix = 'xhprof'; //redis前缀
    public static $log_num = 1000;  //仅记录最近的多少次请求(最大值有待观察，看日志、查看响应时间) 默认1000
    public static $view_wtred = 3; //列表耗时超过多少秒标红 默认3s
    public static $ui_html = '../html';
    public static $symbol_lookup_url = "";


    //页面输出
    public static function index()
    {
        $run = request()->get('run');
        $wts = request()->get('wts');
        $symbol = request()->get('symbol');
        $sort = request()->get('sort');
        $run1 = request()->get('run1');
        $run2 = request()->get('run2');
        $source = request()->get('source');
        $params = request()->all();
        $echo_page = "<html>";

        $echo_page .= "<head><title>XHProf性能分析报告</title>";
        $echo_page .= XhprofDisplay::xhprof_include_js_css(self::$ui_html);
        $echo_page .= "</head>";
        $echo_page .= "<body>";
        $echo_page .= XhprofDisplay::displayXHProfReport(
            $params,
            $source,
            $run,
            $wts,
            $symbol,
            $sort,
            $run1,
            $run2
        );
        $echo_page .= "</body>";
        $echo_page .= "</html>";
        return $echo_page;
    }

    //监听入口
    public static function xhprofStart()
    {
        self::_init();
        xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
    }

    public static function xhprofStop()
    {
        $xhprof_data = xhprof_disable();
        XHProfRunsDefault::save_run($xhprof_data, "xhprof_foo");
    }

    protected static function _init()
    {
        date_default_timezone_set('PRC');
        extension_loaded("xhprof") || trigger_error('请检查「xhprof」扩展是否安装!', E_USER_ERROR);
        extension_loaded("redis") || trigger_error('请检查「redis」扩展是否安装!', E_USER_ERROR);
    }
}
