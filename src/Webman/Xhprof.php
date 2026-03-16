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
    /** @deprecated 已改为从 config plugin.aaron-dev.xhprof.xhprof.assets_url 读取，包内静态资源无需复制 */
    public static $ui_html = '';
    public static $symbol_lookup_url = "";

    public static function getRequest()
    {
      return  request();
    }

    public static function getResponse()
    {
      return  response();
    }
    //页面输出
    public static function index()
    {
        $run = self::getRequest()->get('run');
        $wts = self::getRequest()->get('wts');
        $symbol = self::getRequest()->get('symbol');
        $sort = self::getRequest()->get('sort');
        $run1 = self::getRequest()->get('run1');
        $run2 = self::getRequest()->get('run2');
        $source = self::getRequest()->get('source');
        $params = self::getRequest()->all();
        $echo_page = "<html lang=\"zh-CN\">";
        $assetsUrl = config('plugin.aaron-dev.xhprof.xhprof.assets_url', '');
        if ($assetsUrl === '') {
            $assetsUrl = self::$ui_html ?: '/xhprof-assets';
        }
        $echo_page .= "<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>XHProf 性能分析报告</title>";
        $echo_page .= XhprofDisplay::xhprof_include_js_css($assetsUrl);
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

    protected static function _init(): void
    {
        date_default_timezone_set('PRC');
        if (!extension_loaded('xhprof')) {
            self::getResponse()->withBody('请安装xhprof扩展');
            return;
        }
        if (!extension_loaded('redis')) {
            self::getResponse()->withBody('请安装redis扩展');
            return;
        }
    }
}
