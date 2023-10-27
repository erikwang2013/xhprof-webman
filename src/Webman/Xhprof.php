<?php

namespace Erik\Xhprof\Webman;

use Erik\Xhprof\Webman\XhprofLib\Utils\XHProfRunsDefault;
use Erik\Xhprof\Webman\XhprofLib\Display\XhprofDisplay;

class Xhprof
{
    protected $config = [];

    public function __construct($config = [])
    {
        self::_defineConfig($config);
    }

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
        $echo_page .= XhprofDisplay::xhprof_include_js_css(X_UI_DIR_URL_PATH);
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
        register_shutdown_function([$this, 'xhprofStop']);
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

    // 配置
    protected  static function _defineConfig($config)
    {
        define('XHPROF_STRING_PARAM', 1);
        define('XHPROF_UINT_PARAM',   2);
        define('XHPROF_FLOAT_PARAM',  3);
        define('XHPROF_BOOL_PARAM',   4);
        defined('XHPROF_SYMBOL_LOOKUP_URL', "");
        /**************  ui_dir_url_path **************/
        defined('X_UI_DIR_URL_PATH') || define('X_UI_DIR_URL_PATH', "../html");

        /**************  redis **************/
        $config['key_prefix'] = $config['key_prefix'] ?? 'xhprof';
        defined('X_KEY_PREFIX') || define('X_KEY_PREFIX', $config['key_prefix']);

        /************* 新增日志 *************/
        $config['time_limit'] = $config['time_limit'] ?? 0;
        $config['log_num'] = $config['log_num'] ?? 1000;

        defined('X_TIME_LIMIT') || define('X_TIME_LIMIT', $config['time_limit']);      //仅记录响应超过多少秒的请求  默认0记录所有
        defined('X_LOG_NUM') || define('X_LOG_NUM', $config['log_num']);      //仅记录最近的多少次请求(最大值有待观察，看日志、查看响应时间) 默认1000

        /********* 日志列表页面展现 *********/
        $config['view_wtred'] = $config['view_wtred'] ?? 3;

        defined('X_VIEW_WTRED') || define('X_VIEW_WTRED', $config['view_wtred']);      //列表耗时超过多少秒标红 默认3s

        /********* 忽略URL配置 *********/
        $config['ignore_url_arr'] = $config['ignore_url_arr'] ?? [];
        defined('X_IGNORE_URL_ARR') || define('X_IGNORE_URL_ARR', $config['ignore_url_arr']);
    }
}
