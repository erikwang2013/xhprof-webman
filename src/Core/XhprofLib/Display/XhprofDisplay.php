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

namespace ErikWang2013\Xhprof\Core\XhprofLib\Display;

use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XhprofLib;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;
use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Core\Xhprof;

class XhprofDisplay
{

  public static function base_path()
  {
    // 实现搬到 XhprofLib::report_path()：链接现在统一由 XhprofLib::report_url() 拼，
    // 而它是 Utils 层（不能反向依赖 Display）。这里保留同名入口是为了既有调用点
    // 与测试不必改，转义策略只有一处。
    return XhprofLib::report_path();
  }

  public static $sort_col = "wt";
  public static $diff_mode = false;
  public static $display_calls = true;


  public static $sortable_columns = array(
    "fn" => 1,
    "ct" => 1,
    "wt" => 1,
    "excl_wt" => 1,
    "ut" => 1,
    "excl_ut" => 1,
    "st" => 1,
    "excl_st" => 1,
    "mu" => 1,
    "excl_mu" => 1,
    "pmu" => 1,
    "excl_pmu" => 1,
    "cpu" => 1,
    "excl_cpu" => 1,
    "samples" => 1,
    "excl_samples" => 1
  );

  public static $vwbar;
  public static $vbar;
  public static  $vbbar;
  public static  $vrbar;
  public static  $vgbar;

  public static $descriptions = array(
    "fn" => "函数/方法名",
    "ct" =>  "调用<br>次数",
    "Calls%" => "调用<br>次数<br>占比",

    "wt" => "总耗时<br>(微秒)",
    "IWall%" => "总耗时<br>占比",
    "excl_wt" => "自身耗时<br>(微秒)",
    "EWall%" => "自身耗时<br>占比",

    "ut" => "Incl. User<br>(microsecs)",
    "IUser%" => "IUser%",
    "excl_ut" => "Excl. User<br>(microsec)",
    "EUser%" => "EUser%",

    "st" => "Incl. Sys <br>(microsec)",
    "ISys%" => "ISys%",
    "excl_st" => "Excl. Sys <br>(microsec)",
    "ESys%" => "ESys%",

    "cpu" => "总<br>CPU时间<br>(微秒)",
    "ICpu%" => "总<br>CPU时间<br>占比",
    "excl_cpu" => "自身<br>CPU时间<br>(微秒)",
    "ECpu%" => "自身<br>CPU时间<br>占比",

    "mu" => "总<br>内存占用<br>(bytes)",
    "IMUse%" => "总<br>内存占用<br>占比",
    "excl_mu" => "自身<br>内存占用<br>(bytes)",
    "EMUse%" => "自身<br>内存占用<br>占比",

    "pmu" => "总<br>内存峰值<br>(bytes)",
    "IPMUse%" => "总<br>内存峰值<br>占比",
    "excl_pmu" => "自身<br>内存峰值<br>(bytes)",
    "EPMUse%" => "自身<br>内存峰值<br>占比",

    "samples" => "Incl. Samples",
    "ISamples%" => "ISamples%",
    "excl_samples" => "Excl. Samples",
    "ESamples%" => "ESamples%",
  );

  public static $format_cbk = array(
    "fn" => "",
    "ct" => "XhprofDisplay::xhprof_count_format",
    "Calls%" => "XhprofDisplay::xhprof_percent_format",
    "wt" => "number_format",
    "IWall%" => "XhprofDisplay::xhprof_percent_format",
    "excl_wt" => "number_format",
    "EWall%" => "XhprofDisplay::xhprof_percent_format",

    "ut" => "number_format",
    "IUser%" => "XhprofDisplay::xhprof_percent_format",
    "excl_ut" => "number_format",
    "EUser%" => "XhprofDisplay::xhprof_percent_format",

    "st" => "number_format",
    "ISys%" => "XhprofDisplay::xhprof_percent_format",
    "excl_st" => "number_format",
    "ESys%" => "XhprofDisplay::xhprof_percent_format",

    "cpu" => "number_format",
    "ICpu%" => "XhprofDisplay::xhprof_percent_format",
    "excl_cpu" => "number_format",
    "ECpu%" => "XhprofDisplay::xhprof_percent_format",

    "mu" => "number_format",
    "IMUse%" => "XhprofDisplay::xhprof_percent_format",
    "excl_mu" => "number_format",
    "EMUse%" => "XhprofDisplay::xhprof_percent_format",

    "pmu" => "number_format",
    "IPMUse%" => "XhprofDisplay::xhprof_percent_format",
    "excl_pmu" => "number_format",
    "EPMUse%" => "XhprofDisplay::xhprof_percent_format",

    "samples" => "number_format",
    "ISamples%" => "XhprofDisplay::xhprof_percent_format",
    "excl_samples" => "number_format",
    "ESamples%" => "XhprofDisplay::xhprof_percent_format",
  );


  public static $diff_descriptions = array(
    "fn" => "Function Name",
    "ct" =>  "Calls Diff",
    "Calls%" => "Calls<br>Diff%",

    "wt" => "Incl. Wall<br>Diff<br>(microsec)",
    "IWall%" => "IWall<br> Diff%",
    "excl_wt" => "Excl. Wall<br>Diff<br>(microsec)",
    "EWall%" => "EWall<br>Diff%",

    "ut" => "Incl. User Diff<br>(microsec)",
    "IUser%" => "IUser<br>Diff%",
    "excl_ut" => "Excl. User<br>Diff<br>(microsec)",
    "EUser%" => "EUser<br>Diff%",

    "cpu" => "Incl. CPU Diff<br>(microsec)",
    "ICpu%" => "ICpu<br>Diff%",
    "excl_cpu" => "Excl. CPU<br>Diff<br>(microsec)",
    "ECpu%" => "ECpu<br>Diff%",

    "st" => "Incl. Sys Diff<br>(microsec)",
    "ISys%" => "ISys<br>Diff%",
    "excl_st" => "Excl. Sys Diff<br>(microsec)",
    "ESys%" => "ESys<br>Diff%",

    "mu" => "Incl.<br>MemUse<br>Diff<br>(bytes)",
    "IMUse%" => "IMemUse<br>Diff%",
    "excl_mu" => "Excl.<br>MemUse<br>Diff<br>(bytes)",
    "EMUse%" => "EMemUse<br>Diff%",

    "pmu" => "Incl.<br> PeakMemUse<br>Diff<br>(bytes)",
    "IPMUse%" => "IPeakMemUse<br>Diff%",
    "excl_pmu" => "Excl.<br>PeakMemUse<br>Diff<br>(bytes)",
    "EPMUse%" => "EPeakMemUse<br>Diff%",

    "samples" => "Incl. Samples Diff",
    "ISamples%" => "ISamples Diff%",
    "excl_samples" => "Excl. Samples Diff",
    "ESamples%" => "ESamples Diff%",
  );

  public static $stats = array();
  public static $pc_stats = array();
  public static $totals = 0;
  public static $totals_1 = 0;
  public static $totals_2 = 0;
  public static $metrics = null;

  /**
   * Generate references to required stylesheets & javascript.
   *
   * If the calling script (such as index.php) resides in
   * a different location that than 'xhprof_html' directory the
   * caller must provide the URL path to 'xhprof_html' directory
   * so that the correct location of the style sheets/javascript
   * can be specified in the generated HTML.
   *
   */
  public static function xhprof_include_js_css($ui_dir_url_path = null)
  {

    if (empty($ui_dir_url_path)) $ui_dir_url_path = rtrim(dirname(Xhprof::getRequest()->url()), '/\\');

    // style sheets
    $echo_page = "<link href='$ui_dir_url_path/css/xhprof.css' rel='stylesheet' " .
      " type='text/css' />";
    $echo_page .= "<link href='$ui_dir_url_path/css/bootstrap.css' rel='stylesheet' " .
      " type='text/css' />";
    $echo_page .= "<link href='$ui_dir_url_path/css/dataTables.bootstrap.css' rel='stylesheet' type='text/css' />";

    // 报告页 JS 要用的文案：请求记录表的 DataTable 界面文案（分页/搜索/“没有匹配结果”）。
    // 以 JSON 注入而不是一堆 data-* 属性 —— JS 只认一个数据来源，加一个字符串只改这里。
    // 用 t()（原始文案）+ json_encode，不要用 plain()：那是给 HTML 上下文转义的，
    // 会把 `&` 变成 `&amp;` 显示给用户。HEX 标志把 `<`/`>`/`&`/引号转成 \uXXXX，
    // 词表里万一混进 `</script>` 也跑不出这个 <script> 块（JS 里解码回来照常）。
    $data_table_i18n = array();
    foreach (array(
        'processing' => 'runs.dt.processing',
        'loadingRecords' => 'runs.dt.loadingRecords',
        'lengthMenu' => 'runs.dt.lengthMenu',
        'zeroRecords' => 'runs.dt.zeroRecords',
        'emptyTable' => 'runs.dt.emptyTable',
        'info' => 'runs.dt.info',
        'infoEmpty' => 'runs.dt.infoEmpty',
        'infoFiltered' => 'runs.dt.infoFiltered',
        // 千位分隔符（`sInfoThousands`）**不进词表**：报告页上的数字是 PHP 的
        // `number_format()` 打的（`$format_cbk`），它永远是英式的 `123,456`；
        // 而 DataTables 只负责分页那一行的 `_TOTAL_`。两边各用本地分隔符的结果是
        // **同一张页面上两种写法**（pt 的译者实测报回：表里 123,456、分页行 1.234）。
        // 统一取英式：不给 DataTables 传这个键，它就用自带的默认值。
        // 想让整页数字真正本地化是另一件事（要连 number_format 调用点一起改，
        // 见 `$format_cbk`），那时再把它作为一对（thousands + decimal）加回来。
        'search' => 'runs.dt.search',
        'first' => 'runs.dt.first',
        'previous' => 'runs.dt.previous',
        'next' => 'runs.dt.next',
        'last' => 'runs.dt.last',
        'sortAsc' => 'runs.dt.sortAsc',
        'sortDesc' => 'runs.dt.sortDesc',
    ) as $js_key => $catalog_key) {
        $data_table_i18n[$js_key] = I18n::t($catalog_key);
    }
    $echo_page .= '<script>window.xpI18n = '
        . json_encode(
            array('dataTable' => $data_table_i18n),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        )
        . ';</script>';

    // javascript —— 顺序有意义：这两个 <script> 都没有 defer/async，浏览器按文档顺序
    // **同步**执行，而 xhprof_report.js 的顶层就是 `$(document).ready(...)`。jQuery 排在
    // 它后面 = `$` 未定义 → ReferenceError，该脚本剩余部分不再执行，搜索按钮的点击处理器
    // 与请求列表的 DataTable（分页/排序/搜索）全都注册不上，且页面不报错、看起来「就是没反应」。
    $echo_page .= "<script src='$ui_dir_url_path/jquery/jquery-3.0.0.min.js'></script>";
    $echo_page .= "<script src='$ui_dir_url_path/js/xhprof_report.js'></script>";
    $echo_page .= "<script src='$ui_dir_url_path/js/bootstrap.min.js'></script>";
    $echo_page .= "<script src='$ui_dir_url_path/js/jquery.dataTables.min.js'></script>";
    $echo_page .= "<script src='$ui_dir_url_path/js/dataTables.bootstrap.js'></script>";
    return $echo_page;
  }


  public static function xhprof_count_format($num)
  {
    $num = round($num, 3);
    if (round($num) == $num) return number_format($num);
    return number_format($num, 3);
  }

  public static function xhprof_percent_format($s, $precision = 1)
  {
    return sprintf('%.' . $precision . 'f%%', 100 * $s);
  }

  /**
   * Implodes the text for a bunch of actions (such as links, forms,
   * into a HTML list and returns the text.
   */
  public static function xhprof_render_actions($actions)
  {
    $out = array();
    if (count($actions)) {
      $out[] = '<ul class="xhprof_actions">';
      foreach ($actions as $action) {
        $out[] = '<li>' . $action . '</li>';
      }
      $out[] = '</ul>';
    }

    return implode('', $out);
  }


  public static function xhprof_render_link(
    $content,
    $href,
    $class = '',
    $id = '',
    $title = '',
    $target = '',
    $onclick = '',
    $style = '',
    $access = '',
    $onmouseover = '',
    $onmouseout = '',
    $onmousedown = ''
  ) {

    if (!$content) return '';
    $link = '<span';
    if ($href) $link = '<a href="' . ($href) . '"';
    if ($class)  $link .= ' class="' . ($class) . '"';
    if ($id) $link .= ' id="' . ($id) . '"';
    if ($title) $link .= ' title="' . ($title) . '"';
    if ($target) $link .= ' target="' . ($target) . '"';
    if ($onclick && $href) $link .= ' onclick="' . ($onclick) . '"';
    if ($style && $href) $link .= ' style="' . ($style) . '"';
    if ($access && $href) $link .= ' accesskey="' . ($access) . '"';
    if ($onmouseover) $link .= ' onmouseover="' . ($onmouseover) . '"';
    if ($onmouseout) $link .= ' onmouseout="' . ($onmouseout) . '"';
    if ($onmousedown) $link .= ' onmousedown="' . ($onmousedown) . '"';

    $link .= '>';
    $link .= $content;
    if ($href) {
      $link .= '</a>';
    } else {
      $link .= '</span>';
    }

    return $link;
  }


  public static function sort_cbk($a, $b)
  {
    $sort_col = XhprofDisplay::$sort_col;
    $diff_mode = XhprofDisplay::$diff_mode;
    if ($sort_col == "fn") {
      $left = strtoupper($a["fn"]);
      $right = strtoupper($b["fn"]);
      if ($left == $right) return 0;
      return ($left < $right) ? -1 : 1;
    } else {
      // 作为 usort 回调不能抛异常：$sort_col 是 public static，任何调用方都可能把它
      // 设成本次 run 未采集的指标（如 ut/st/samples），此时取值为 null，
      // abs(null) 在 PHP 8 下抛 TypeError。
      $left = $a[$sort_col] ?? 0;
      $right = $b[$sort_col] ?? 0;
      if ($diff_mode) {
        $left = abs($left);
        $right = abs($right);
      }
      if ($left == $right)  return 0;
      return ($left > $right) ? -1 : 1;
    }
  }


  /**
   * 表格列头文案：按 `col.<统计项>` 从当前语言词表取，已转义、保留 `<br>` 折行。
   *
   * `$descriptions` 那张字面量表仍是中文源（词表的 zh_CN 与它逐字相同，改一边
   * 不改另一边测试会红），这里只是换了个取用方式。取不到时 I18n::t() 逐级回落到
   * 中文源，不会因为某份词表漏了一条就白掉一列。
   */
  public static function col_text($stat)
  {
    return I18n::html('col.' . $stat);
  }

  public static function stat_description($stat)
  {
    $diff_descriptions = XhprofDisplay::$diff_descriptions;
    $diff_mode = XhprofDisplay::$diff_mode;
    // 非 diff 模式走词表；diff 模式仍用 $diff_descriptions 的英文字面量
    // （diff 列头这次没纳入翻译范围，行为保持不变）。
    $result = $diff_mode ? ($diff_descriptions[$stat] ?? '') : XhprofDisplay::col_text($stat);
    return $result;
  }

  public static function profiler_report(
    $url_params,
    $rep_symbol,
    $run1,
    $run1_desc,
    $run1_data,
    $run2 = 0,
    $run2_desc = "",
    $run2_data = array()
  ) {
    $totals = 0;
    $totals_1 = 0;
    $totals_2 = 0;

    $diff_mode = XhprofDisplay::$diff_mode;
    $base_path = XhprofDisplay::base_path();

    if (!empty($rep_symbol)) {
      $run1_data = XhprofLib::xhprof_trim_run($run1_data, array($rep_symbol));
      if ($diff_mode) $run2_data = XhprofLib::xhprof_trim_run($run2_data, array($rep_symbol));
    }
    $symbol_tab = XhprofLib::xhprof_compute_flat_info($run1_data, $totals);
    XhprofDisplay::$totals = $totals;
    if ($diff_mode) {
      $run_delta = XhprofLib::xhprof_compute_diff($run1_data, $run2_data);
      $symbol_tab  = XhprofLib::xhprof_compute_flat_info($run_delta, $totals);
      $symbol_tab1 = XhprofLib::xhprof_compute_flat_info($run1_data, $totals_1);
      $symbol_tab2 = XhprofLib::xhprof_compute_flat_info($run2_data, $totals_2);
      XhprofDisplay::$totals = $totals;
      XhprofDisplay::$totals_1 = $totals_1;
      XhprofDisplay::$totals_2 = $totals_2;
    }
    // 模板 + 两个**已转义**的参数（run_id 来自查询串、描述来自缓存）
    $run1_txt = '<b>' . sprintf(
      I18n::plain('diff.run'),
      htmlspecialchars((string) $run1, ENT_QUOTES, 'UTF-8'),
      htmlspecialchars((string) $run1_desc, ENT_QUOTES, 'UTF-8')
    ) . '</b>';

    $base_url_params = XhprofLib::xhprof_array_unset(XhprofLib::xhprof_array_unset($url_params, 'symbol'), 'all');
    if ($diff_mode) {
      $diff_text = I18n::plain('common.diff');
      $base_url_params = XhprofLib::xhprof_array_unset($base_url_params, 'run1');
      $base_url_params = XhprofLib::xhprof_array_unset($base_url_params, 'run2');
      $run1_link = XhprofDisplay::xhprof_render_link(
        sprintf(I18n::plain('diff.viewRun'), htmlspecialchars((string) $run1, ENT_QUOTES, 'UTF-8')),
        "$base_path?" .
          http_build_query(XhprofLib::xhprof_array_set(
            $base_url_params,
            'run',
            $run1
          ))
      );
      $run2_txt = '<b>' . sprintf(
        I18n::plain('diff.run'),
        htmlspecialchars((string) $run2, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars((string) $run2_desc, ENT_QUOTES, 'UTF-8')
      ) . '</b>';

      $run2_link = XhprofDisplay::xhprof_render_link(
        sprintf(I18n::plain('diff.viewRun'), htmlspecialchars((string) $run2, ENT_QUOTES, 'UTF-8')),
        "$base_path?" .
          http_build_query(XhprofLib::xhprof_array_set(
            $base_url_params,
            'run',
            $run2
          ))
      );
    } else {
      $diff_text = I18n::plain('common.run');
    }

    // set up the action links for operations that can be done on this report
    $links = array();
    if ($diff_mode) {
      $inverted_params = $url_params;
      $inverted_params['run1'] = $url_params['run2'];
      $inverted_params['run2'] = $url_params['run1'];

      // view the different runs or invert the current diff
      $links[] = $run1_link;
      $links[] = $run2_link;
      $links[] = XhprofDisplay::xhprof_render_link(
        sprintf(I18n::plain('diff.invert'), I18n::plain($diff_mode ? 'common.diff' : 'common.run')),
        "$base_path?" .
          http_build_query($inverted_params)
      );
    }


    $links[] = '<div class="xp-search"><input type="text" class="xhprof-search-input" placeholder="'
      . I18n::plain('search.placeholder') . '" id="xhprofFuncSearch"><button type="button" id="funcSub">'
      . I18n::plain('search.button') . '</button></div>';
    $echo_page = XhprofDisplay::xhprof_render_actions($links);
    // 这两个描述此前只 sprintf 了却从不输出，导致聚合报告的
    // "Aggregated Report for N runs..." 等说明性文案被静默丢弃。
    $echo_page .= '<div style="padding:10px 20px 0;color:#555;font-size:13px">'
      . $run1_txt
      . ($diff_mode ? ' &nbsp;|&nbsp; ' . $run2_txt : '')
      . '</div>';


    // data tables
    if (!empty($rep_symbol)) {
      if (!isset($symbol_tab[$rep_symbol])) {
        $echo_page .= '<div class="xp-main"><div class="xp-card"><p class="xp-card-title">'
          . str_replace(
              '%s',
              '<b>' . htmlspecialchars($rep_symbol, ENT_QUOTES, 'UTF-8') . '</b>',
              I18n::plain('symbol.notFound')
          )
          . '</p></div></div>';
        // 符号不存在时必须就此返回：继续往下会把 null 传进 symbol_report()，
        // 在 round()/算术处抛 TypeError，整页 500。
        return $echo_page;
      }

      /* single public static function report with parent/child information */
      if ($diff_mode) {
        // 符号可能只存在于其中一个 run（新增/删除的函数，恰是 diff 模式的目标场景）。
        // 用零值行代替 null：既消除下游海量 "array offset on null" warning，
        // 又让 diff 语义正确——run1 未出现即 0，差额正是 run2 的实际值。
        $zero = array('ct' => 0);
        foreach (XhprofDisplay::$metrics as $metric) {
          $zero[$metric] = 0;
          $zero["excl_" . $metric] = 0;
        }
        $info1 = $symbol_tab1[$rep_symbol] ?? $zero;
        $info2 = $symbol_tab2[$rep_symbol] ?? $zero;
        $echo_page .= XhprofDisplay::symbol_report(
          $url_params,
          $run_delta,
          $symbol_tab[$rep_symbol],
          $rep_symbol,
          $run1,
          $info1,
          $run2,
          $info2
        );
      } else {
        $echo_page .= XhprofDisplay::symbol_report(
          $url_params,
          $run1_data,
          $symbol_tab[$rep_symbol],
          $rep_symbol,
          $run1
        );
      }
    } else {
      /* flat top-level report of all public static functions */
      $echo_page .= XhprofDisplay::full_report($url_params, $symbol_tab, $run1, $run2);
    }
    return $echo_page;
  }

  /**
   * Given a number, returns the td class to use for display.
   *
   * For instance, negative numbers in diff reports comparing two runs (run1 & run2)
   * represent improvement from run1 to run2. We use green to display those deltas,
   * and red for regression deltas.
   */
  public static function get_print_class($num, $bold)
  {
    $vbar = XhprofDisplay::$vbar;
    $vbbar = XhprofDisplay::$vbbar;
    $vrbar = XhprofDisplay::$vrbar;
    $vgbar = XhprofDisplay::$vgbar;
    $diff_mode = XhprofDisplay::$diff_mode;

    if ($bold) {
      if ($diff_mode) {
        $class = $vrbar; // red (regression)
        if ($num <= 0) $class = $vgbar; // green (improvement)
      } else {
        $class = $vbbar; // blue
      }
    } else {
      $class = $vbar;  // default (black)
    }

    return $class;
  }

  /**
   * Prints a <td> element with a numeric value.
   */
  public static function print_td_num($num, $fmt_func, $bold = false, $attributes = null)
  {

    $class = XhprofDisplay::get_print_class($num, $bold);
    if (!empty($fmt_func) && is_numeric($num)) {
      if (is_string($fmt_func) && str_starts_with($fmt_func, 'XhprofDisplay::')) {
        $fmt_func = [self::class, substr($fmt_func, strlen('XhprofDisplay::'))];
      }
      $num = call_user_func($fmt_func, $num);
    }
    return "<td $attributes $class>$num</td>\n";
  }

  /**
   * Prints a <td> element with a pecentage.
   */
  public static function print_td_pct($numer, $denom, $bold = false, $attributes = null)
  {
    $class = XhprofDisplay::get_print_class($numer, $bold);
    $pct = "N/A%";
    // 调用方会传入 'N/A'（无调用次数的均值）等非数值占位符；
    // 直接 abs()/除法在 PHP 8 下抛 TypeError，故统一在此收口。
    if (is_numeric($numer) && is_numeric($denom) && $denom != 0) {
      $pct = XhprofDisplay::xhprof_percent_format($numer / abs($denom));
    }
    return "<td $attributes $class>$pct</td>\n";
  }

  /**
   * Print "flat" data corresponding to one public static function.
   *
   *
   */
  public static function print_function_info($url_params, $info, int $row_index = 0)
  {
    $totals = XhprofDisplay::$totals;
    $sort_col = XhprofDisplay::$sort_col;
    $metrics = XhprofDisplay::$metrics;
    $format_cbk = XhprofDisplay::$format_cbk;
    $display_calls = XhprofDisplay::$display_calls;
    $base_path = XhprofDisplay::base_path();

    $echo_page = "";
    $echo_page .= ($row_index % 2 === 0) ? '<tr>' : '<tr class="xp-tr-alt">';

    $href = "$base_path?" .
      http_build_query(XhprofLib::xhprof_array_set(
        $url_params,
        'symbol',
        $info["fn"]
      ));

    $echo_page .= '<td>';
    $echo_page .= XhprofDisplay::xhprof_render_link(htmlspecialchars($info["fn"], ENT_QUOTES, 'UTF-8'), $href);
    $echo_page .= XhprofDisplay::print_source_link($info);
    $echo_page .= "</td>\n";

    if ($display_calls) {
      // Call Count..
      $echo_page .= XhprofDisplay::print_td_num($info["ct"], $format_cbk["ct"], ($sort_col == "ct"));
      $echo_page .= XhprofDisplay::print_td_pct($info["ct"], $totals["ct"], ($sort_col == "ct"));
    }

    // Other metrics..
    foreach ($metrics as $metric) {
      // Inclusive metric
      $echo_page .= XhprofDisplay::print_td_num(
        $info[$metric],
        $format_cbk[$metric],
        ($sort_col == $metric)
      );
      $echo_page .= XhprofDisplay::print_td_pct(
        $info[$metric],
        $totals[$metric],
        ($sort_col == $metric)
      );

      // Exclusive Metric
      $echo_page .= XhprofDisplay::print_td_num(
        $info["excl_" . $metric],
        $format_cbk["excl_" . $metric],
        ($sort_col == "excl_" . $metric)
      );
      $echo_page .= XhprofDisplay::print_td_pct(
        $info["excl_" . $metric],
        $totals[$metric],
        ($sort_col == "excl_" . $metric)
      );
    }

    $echo_page .= "</tr>\n";
    return $echo_page;
  }

  /**
   * Print non-hierarchical (flat-view) of profiler data.
   *
   *
   */
  public static function print_flat_data($url_params, $title, $flat_data, $limit)
  {

    $stats = XhprofDisplay::$stats;
    $sortable_columns = XhprofDisplay::$sortable_columns;
    $vwbar = XhprofDisplay::$vwbar;
    $base_path = XhprofDisplay::base_path();
    $size  = count($flat_data);
    if (!$limit) {              // no limit
      $limit = $size;
      $display_link = "";
    } else {
      $display_link = XhprofDisplay::xhprof_render_link(
        ' [ <b class=bubble>' . I18n::plain('flat.displayAll') . ' </b>]',
        "$base_path?" .
          http_build_query(XhprofLib::xhprof_array_set(
            $url_params,
            'all',
            1
          ))
      );
    }


    $echo_page = '<div class="xp-card"><div class="xp-card-title">' . htmlspecialchars(strip_tags($title)) . ' ' . $display_link . '</div>';
    $echo_page .= '<div class="xp-table-wrap"><table class="xp-table">';
    $echo_page .= '<thead><tr>';

    foreach ($stats as $stat) {
      $desc = XhprofDisplay::stat_description($stat);
      if (array_key_exists($stat, $sortable_columns)) {
        $href = "$base_path?"
          . http_build_query(XhprofLib::xhprof_array_set($url_params, 'sort', $stat));
        $header = XhprofDisplay::xhprof_render_link($desc, $href);
      } else {
        $header = $desc;
      }

      if ($stat == "fn")
        $echo_page .= "<th><nobr>$header</th>";
      else $echo_page .= "<th " . $vwbar . "><nobr>$header</th>";
    }
    $echo_page .= "</tr></thead>\n<tbody>";

    if ($limit >= 0) {
      $limit = min($size, $limit);
      for ($i = 0; $i < $limit; $i++) {
        $echo_page .= XhprofDisplay::print_function_info($url_params, $flat_data[$i], $i);
      }
    } else {
      // if $limit is negative, print abs($limit) items starting from the end
      $limit = min($size, abs($limit));
      for ($i = 0; $i < $limit; $i++) {
        $echo_page .= XhprofDisplay::print_function_info($url_params, $flat_data[$size - $i - 1], $i);
      }
    }
    $echo_page .= '</tbody></table></div>';
    if ($display_link) {
      $echo_page .= '<div style="padding: 16px 20px">' . $display_link . '</div>';
    }
    $echo_page .= '</div>';
    return $echo_page;
  }

  /**
   * Generates a tabular report for all public static functions. This is the top-level report.
   *
   *
   */
  public static function full_report($url_params, $symbol_tab, $run1, $run2)
  {
    $vwbar = XhprofDisplay::$vwbar;
    $totals = XhprofDisplay::$totals;
    $totals_1 = XhprofDisplay::$totals_1;
    $totals_2 = XhprofDisplay::$totals_2;
    $metrics = XhprofDisplay::$metrics;
    $diff_mode = XhprofDisplay::$diff_mode;
    $sort_col = XhprofDisplay::$sort_col;
    $format_cbk = XhprofDisplay::$format_cbk;
    $display_calls = XhprofDisplay::$display_calls;
    $base_path = XhprofDisplay::base_path();

    $echo_page = '<div class="xp-main">';
    $possible_metrics = XhprofLib::xhprof_get_possible_metrics();
    $echo_page .= '<div class="xp-summary">';
    if ($diff_mode) {
      $base_url_params = XhprofLib::xhprof_array_unset(
        XhprofLib::xhprof_array_unset(
          $url_params,
          'run1'
        ),
        'run2'
      );
      $href1 = "$base_path?" .
        http_build_query(XhprofLib::xhprof_array_set(
          $base_url_params,
          'run',
          $run1
        ));
      $href2 = "$base_path?" .
        http_build_query(XhprofLib::xhprof_array_set(
          $base_url_params,
          'run',
          $run2
        ));

      $echo_page .= '<h3 style="margin:0 0 12px 0;font-size:15px">' . I18n::plain('diff.summary') . '</h3>';
      $echo_page .= '<table class="xp-table"><tr>';
      $echo_page .= "<th></th>";
      $echo_page .= "<th $vwbar>" . XhprofDisplay::xhprof_render_link(sprintf(I18n::plain('diff.runShort'), htmlspecialchars((string) $run1, ENT_QUOTES, 'UTF-8')), $href1) . "</th>";
      $echo_page .= "<th $vwbar>" . XhprofDisplay::xhprof_render_link(sprintf(I18n::plain('diff.runShort'), htmlspecialchars((string) $run2, ENT_QUOTES, 'UTF-8')), $href2) . "</th>";
      $echo_page .= "<th $vwbar>" . I18n::plain('common.diff') . "</th>";
      $echo_page .= "<th $vwbar>" . I18n::plain('diff.diffPct') . "</th>";
      $echo_page .= '</tr>';

      if ($display_calls) {
        $echo_page .= '<tr>';
        $echo_page .= "<td>" . I18n::plain('diff.callCount') . "</td>";
        $echo_page .= XhprofDisplay::print_td_num($totals_1["ct"], $format_cbk["ct"]);
        $echo_page .= XhprofDisplay::print_td_num($totals_2["ct"], $format_cbk["ct"]);
        $echo_page .= XhprofDisplay::print_td_num($totals_2["ct"] - $totals_1["ct"], $format_cbk["ct"], true);
        $echo_page .= XhprofDisplay::print_td_pct($totals_2["ct"] - $totals_1["ct"], $totals_1["ct"], true);
        $echo_page .= '</tr>';
      }

      foreach ($metrics as $metric) {
        $m = $metric;
        $echo_page .= '<tr>';
        $echo_page .= "<td>" . str_replace("<br>", " ", XhprofDisplay::col_text($m)) . "</td>";
        $echo_page .= XhprofDisplay::print_td_num($totals_1[$m], $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($totals_2[$m], $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($totals_2[$m] - $totals_1[$m], $format_cbk[$m], true);
        $echo_page .= XhprofDisplay::print_td_pct($totals_2[$m] - $totals_1[$m], $totals_1[$m], true);
        $echo_page .= '</tr>';
      }
      $echo_page .= '</table>';

    } else {
      $request_info = XhprofLib::getRequestLog(Xhprof::getRequest()->get('run')) ?: [];
      $request_uri = isset($request_info['request_uri']) ? htmlspecialchars(urldecode($request_info['request_uri'])) : "";
      $method = $request_info['method'] ?? "";
      $create_time_text = "";
      if (isset($request_info['create_time']) && !empty($request_info['create_time'])) {
        $create_time_text = date('Y-m-d H:i:s', $request_info['create_time']);
      }
      $ip = $request_info['ip'] ?? "";

      $echo_page .= '<table class="xp-table"><tr>';
      $echo_page .= "<td colspan='8' style='text-align:center;font-weight:600'>{$request_uri}</td>";
      $echo_page .= "</tr><tr>";
      $echo_page .= "<td>" . I18n::plain('run.col.method') . "</td><td>" . htmlspecialchars($method, ENT_QUOTES, 'UTF-8') . "</td>";
      $echo_page .= "<td>" . I18n::plain('run.col.time') . "</td><td>{$create_time_text}</td>";
      $echo_page .= "<td>" . I18n::plain('run.col.ip') . "</td><td>" . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . "</td>";
      if ($display_calls) {
        $echo_page .= "<td>" . I18n::plain('run.col.totalCalls') . "</td><td>" . number_format($totals['ct']) . "</td>";
      }
      $echo_page .= "</tr><tr>";
      foreach ($metrics as $metric) {
        $echo_page .= "<td>" . str_replace("<br>", " ", XhprofDisplay::stat_description($metric)) . "</td>";
        // 单位（microsecs/bytes/samples）也进词表：它出现在汇总表里，和列头一样是给人看的。
        // 键名由单位本身派生（unit.xxx），词表里没有就原样输出——不硬编码第二张映射表。
        $unit = (string) $possible_metrics[$metric][1];
        $echo_page .= "<td>" . number_format($totals[$metric]) . " "
          . (I18n::has('unit.' . $unit) ? I18n::plain('unit.' . $unit) : $unit) . "</td>";
      }
      $echo_page .= "</tr></table>";
    }
    $echo_page .= '</div>';



    $flat_data = array();
    foreach ($symbol_tab as $symbol => $info) {
      $tmp = $info;
      $tmp["fn"] = $symbol;
      $flat_data[] = $tmp;
    }

    usort($flat_data, [self::class, 'sort_cbk']);

    //  print("<br>");
    $all = false;
    $limit = 100;
    if (!empty($url_params['all'])) {
      $all = true;
      $limit = 0;    // display all rows
    }

    $desc = str_replace("<br>", " ", XhprofDisplay::col_text($sort_col));
    // 标题进词表，`%s` 由 sprintf 填。这里用 t()（原始文案）而不是 plain()：
    // 下游 print_flat_data() 会对整串 strip_tags + htmlspecialchars，用转义过的
    // 文案会被二次转义。`$desc` 是 col_text() 的转义结果，但列头里没有特殊字符。
    if ($diff_mode) {
      $title = sprintf(I18n::t('flat.title.diff'), $desc);
      if ($all) $title = sprintf(I18n::t('flat.title.diffAll'), $desc);
    } else {
      $title = sprintf(I18n::t('flat.title.top'), $limit, $desc);
      if ($all)  $title = sprintf(I18n::t('flat.title.sorted'), $desc);
    }
    $echo_page .= XhprofDisplay::print_flat_data($url_params, $title, $flat_data, $limit);
    $echo_page .= '</div>';
    return $echo_page;
  }


  /**
   * 父/子行上的数据属性，供将来的悬浮提示实现使用。
   *
   * **这里曾经还有一个 `onmouseover="return …RowToolTip(this, 'wt')"`，已删除**：
   * `onmouseover` 的返回值会被浏览器丢弃（只有 `return false` 在个别事件上有意义），
   * 所以它从来不是触发器 —— 真正缺的是**消费端**。
   *
   * 现状核对过（别凭印象）：`xhprof_report.js` 里那两个函数依赖的全局量
   * （`diff_mode`/`func_name`/`metrics_desc`/`func_metrics`/`metrics_col`…）
   * **页面是有的**，就注入在父/子表上方那段内联 `<script>` 里（见 `symbol_report()`
   * 末尾）。所以缺的只有消费端：原版依赖的 `jquery.tooltip.js` 不在加载列表里
   * （`xhprof_include_js_css()` 只加载 5 个脚本），且该插件用了 jQuery 1.x 的
   * `$.browser.msie`，与 jQuery 3 不兼容。
   *
   * 也就是说：恢复父/子悬浮提示是一个特性（要一个消费端 + 浮层样式 + 13 语言的
   * 文案——那两句英文句子目前写在 JS 里），不是把这一行加回来就行的修复。
   * 数据属性保留，`type`/`metric` 正是那个特性需要的输入；本方法因此只返回数据属性。
   */
  public static function get_tooltip_attributes($type, $metric)
  {
    return "type='$type' metric='$metric'";
  }

  /**
   * Print info for a parent or child public static function in the
   * parent & children report.
   *
   *
   */
  public static function pc_info($info, $base_ct, $base_info, $parent)
  {
    $sort_col = XhprofDisplay::$sort_col;
    $metrics = XhprofDisplay::$metrics;
    $format_cbk = XhprofDisplay::$format_cbk;
    $display_calls = XhprofDisplay::$display_calls;
    $type = "Child";
    if ($parent) $type = "Parent";
    $echo_page = "";
    if ($display_calls) {
      $mouseoverct = XhprofDisplay::get_tooltip_attributes($type, "ct");
      /* call count */
      $echo_page .= XhprofDisplay::print_td_num($info["ct"], $format_cbk["ct"], ($sort_col == "ct"), $mouseoverct);
      $echo_page .= XhprofDisplay::print_td_pct($info["ct"], $base_ct, ($sort_col == "ct"), $mouseoverct);
    }

    /* Inclusive metric values  */
    foreach ($metrics as $metric) {
      $echo_page .= XhprofDisplay::print_td_num(
        $info[$metric],
        $format_cbk[$metric],
        ($sort_col == $metric),
        XhprofDisplay::get_tooltip_attributes($type, $metric)
      );
      $echo_page .= XhprofDisplay::print_td_pct(
        $info[$metric],
        $base_info[$metric],
        ($sort_col == $metric),
        XhprofDisplay::get_tooltip_attributes($type, $metric)
      );
    }
    return $echo_page;
  }

  public static function print_pc_array(
    $url_params,
    $results,
    $base_ct,
    $base_info,
    $parent,
    $run1,
    $run2
  ) {
    $base_path = XhprofDisplay::base_path();
    // 这里的 "public static " 是移植时一次全局替换留下的残留（把 `function` 当成 PHP
    // 关键字替换了，连字符串也没放过）：它插在 `function` 前面，把下面那句复数拼接
    // `.'s'` 的语义也打断了（读起来是 "Child public static functions"）。上游原句就是
    // `Child function` / `Parent function` + 复数后缀。
    // 单复数拆成两个键：`.'s'` 是英语的构词法，日/韩/中文没有复数后缀，
    // 俄语三种形式、阿拉伯语六种——拼接后缀在别的语言里只会印出错东西。
    $many = count($results) > 1;
    $title = I18n::plain($parent
      ? ($many ? 'pc.parentMany' : 'pc.parent')
      : ($many ? 'pc.childMany' : 'pc.child'));
    $colspan = count(XhprofDisplay::$pc_stats);
    $echo_page = "<tr class=\"xp-pc-section-title\"><td colspan=\"{$colspan}\">";
    $echo_page .= "<b>" . $title . "</b>";
    $echo_page .= "</td></tr>";

    $odd_even = 0;
    foreach ($results as $info) {
      $href = "$base_path?" .
        http_build_query(XhprofLib::xhprof_array_set(
          $url_params,
          'symbol',
          $info["fn"]
        ));

      $odd_even = 1 - $odd_even;
      if ($odd_even) {
        $echo_page .= '<tr>';
      } else {
        $echo_page .= '<tr bgcolor="#e5e5e5">';
      }

      $echo_page .= "<td>" . XhprofDisplay::xhprof_render_link(htmlspecialchars($info["fn"], ENT_QUOTES, 'UTF-8'), $href);
      $echo_page .= XhprofDisplay::print_source_link($info);
      $echo_page .= "</td>";
      $echo_page .= XhprofDisplay::pc_info($info, $base_ct, $base_info, $parent);
      $echo_page .= "</tr>";
    }
    return $echo_page;
  }

  public static function print_source_link($info)
  {
    $echo_page = "";
    if (strncmp($info['fn'], 'run_init', 8) && $info['fn'] !== 'main()') {
      if (Xhprof::$symbol_lookup_url) {
        $link = XhprofDisplay::xhprof_render_link(
          'source',
          Xhprof::$symbol_lookup_url . '?symbol=' . rawurlencode($info["fn"])
        );
        $echo_page .= ' (' . $link . ')';
      }
    }
    return $echo_page;
  }


  /**
   * Generates a report for a single public static function/symbol.
   *
   *
   */
  public static function symbol_report(
    $url_params,
    $run_data,
    $symbol_info,
    $rep_symbol,
    $run1,
    $symbol_info1 = null,
    $run2 = 0,
    $symbol_info2 = null
  ) {
    $vwbar = XhprofDisplay::$vwbar;
    $vbar = XhprofDisplay::$vbar;
    $totals = XhprofDisplay::$totals;
    $pc_stats = XhprofDisplay::$pc_stats;
    $sortable_columns = XhprofDisplay::$sortable_columns;
    $metrics = XhprofDisplay::$metrics;
    $diff_mode = XhprofDisplay::$diff_mode;
    $format_cbk = XhprofDisplay::$format_cbk;
    $sort_col = XhprofDisplay::$sort_col;
    $display_calls = XhprofDisplay::$display_calls;
    $base_path = XhprofDisplay::base_path();

    $echo_page = '<div class="xp-main"><div class="xp-card">';
    $possible_metrics = XhprofLib::xhprof_get_possible_metrics();
    $diff_text = "";
    $regr_impr = "";
    if ($diff_mode) {
      // 颜色标记留在代码里，只把词交给译者
      $diff_text = I18n::plain('common.diff');
      $regr_impr = "<i style='color:red'>" . I18n::plain('common.regression')
        . "</i>/<i style='color:green'>" . I18n::plain('common.improvement') . "</i>";
    }

    if ($diff_mode) {

      $base_url_params = XhprofLib::xhprof_array_unset(
        XhprofLib::xhprof_array_unset(
          $url_params,
          'run1'
        ),
        'run2'
      );
      $href1 = "$base_path?"
        . http_build_query(XhprofLib::xhprof_array_set($base_url_params, 'run', $run1));
      $href2 = "$base_path?"
        . http_build_query(XhprofLib::xhprof_array_set($base_url_params, 'run', $run2));

      $echo_page .= "<h3 align=center>"
        . sprintf(I18n::plain('pc.summary'), $regr_impr, htmlspecialchars($rep_symbol, ENT_QUOTES, 'UTF-8'))
        . "<br><br></h3>";
      $echo_page .= '<table border=1 cellpadding=2 cellspacing=1 width="30%" '
        . 'rules=rows bordercolor="#bdc7d8" align=center>' . "\n";
      $echo_page .= '<tr bgcolor="#bdc7d8" align=right>';
      $echo_page .= "<th align=left>" . htmlspecialchars($rep_symbol, ENT_QUOTES, 'UTF-8') . "</th>";
      $echo_page .= "<th $vwbar><a href=" . $href1 . ">" . sprintf(I18n::plain('diff.runShort'), htmlspecialchars((string) $run1, ENT_QUOTES, 'UTF-8')) . "</a></th>";
      $echo_page .= "<th $vwbar><a href=" . $href2 . ">" . sprintf(I18n::plain('diff.runShort'), htmlspecialchars((string) $run2, ENT_QUOTES, 'UTF-8')) . "</a></th>";
      $echo_page .= "<th $vwbar>" . I18n::plain('common.diff') . "</th>";
      $echo_page .= "<th $vwbar>" . I18n::plain('diff.diffPct') . "</th>";
      $echo_page .= '</tr>';
      $echo_page .= '<tr>';

      if ($display_calls) {
        $echo_page .= "<td>" . I18n::plain('diff.callCount') . "</td>";
        $echo_page .= XhprofDisplay::print_td_num($symbol_info1["ct"], $format_cbk["ct"]);
        $echo_page .= XhprofDisplay::print_td_num($symbol_info2["ct"], $format_cbk["ct"]);
        $echo_page .= XhprofDisplay::print_td_num(
          $symbol_info2["ct"] - $symbol_info1["ct"],
          $format_cbk["ct"],
          true
        );
        $echo_page .= XhprofDisplay::print_td_pct(
          $symbol_info2["ct"] - $symbol_info1["ct"],
          $symbol_info1["ct"],
          true
        );
        $echo_page .= '</tr>';
      }


      foreach ($metrics as $metric) {
        $m = $metric;

        // Inclusive stat for metric
        $echo_page .= '<tr>';
        $echo_page .= "<td>" . str_replace("<br>", " ", XhprofDisplay::col_text($m)) . "</td>";
        $echo_page .= XhprofDisplay::print_td_num($symbol_info1[$m], $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($symbol_info2[$m], $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($symbol_info2[$m] - $symbol_info1[$m], $format_cbk[$m], true);
        $echo_page .= XhprofDisplay::print_td_pct($symbol_info2[$m] - $symbol_info1[$m], $symbol_info1[$m], true);
        $echo_page .= '</tr>';

        // AVG (per call) Inclusive stat for metric
        $echo_page .= '<tr>';
        $echo_page .= "<td>" . str_replace("<br>", " ", XhprofDisplay::col_text($m)) . " per call </td>";
        $avg_info1 = 'N/A';
        $avg_info2 = 'N/A';
        if ($symbol_info1['ct'] > 0) $avg_info1 = ($symbol_info1[$m] / $symbol_info1['ct']);
        if ($symbol_info2['ct'] > 0) $avg_info2 = ($symbol_info2[$m] / $symbol_info2['ct']);
        // 任一侧 ct 为 0 时 avg 保持 'N/A'（字符串），不能直接相减：
        // PHP 8 下 float - 'N/A' 抛 TypeError。
        $avg_diff = (is_numeric($avg_info1) && is_numeric($avg_info2))
          ? ($avg_info2 - $avg_info1)
          : 'N/A';
        $echo_page .= XhprofDisplay::print_td_num($avg_info1, $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($avg_info2, $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($avg_diff, $format_cbk[$m], true);
        $echo_page .= XhprofDisplay::print_td_pct($avg_diff, $avg_info1, true);
        $echo_page .= '</tr>';

        // Exclusive stat for metric
        $m = "excl_" . $metric;
        $echo_page .= '<tr style="border-bottom: 1px solid black;">';
        $echo_page .= "<td>" . str_replace("<br>", " ", XhprofDisplay::col_text($m)) . "</td>";
        $echo_page .= XhprofDisplay::print_td_num($symbol_info1[$m], $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($symbol_info2[$m], $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($symbol_info2[$m] - $symbol_info1[$m], $format_cbk[$m], true);
        $echo_page .= XhprofDisplay::print_td_pct($symbol_info2[$m] - $symbol_info1[$m], $symbol_info1[$m], true);
        $echo_page .= '</tr>';
      }
      $echo_page .= '</table>';
    }

    $echo_page .= "<h4><center>";
    // 两个模板而不是一个「%s 可能是空串」的：非 diff 模式下 $regr_impr 是空串，
    // 单模板会印出 "Parent/Child  report"（多一个空格）/「 的父/子报告」这种残句。
    $symbol_html = '<b>' . htmlspecialchars($rep_symbol, ENT_QUOTES, 'UTF-8') . '</b>';
    $echo_page .= $regr_impr === ''
      ? sprintf(I18n::plain('pc.report'), $symbol_html)
      : sprintf(I18n::plain('pc.reportDiff'), $regr_impr, $symbol_html);

    $echo_page .= "</center></h4>";

    $echo_page .= '<div class="xp-table-wrap"><table class="xp-table xp-pc-section">';
    $echo_page .= '<thead><tr>';

    foreach ($pc_stats as $stat) {
      $desc = XhprofDisplay::stat_description($stat);
      if (array_key_exists($stat, $sortable_columns)) {
        $href = "$base_path?" .
          http_build_query(XhprofLib::xhprof_array_set(
            $url_params,
            'sort',
            $stat
          ));
        $header = XhprofDisplay::xhprof_render_link($desc, $href);
      } else {
        $header = $desc;
      }

      if ($stat == "fn")
        $echo_page .= "<th><nobr>$header</th>";
      else $echo_page .= "<th " . $vwbar . "><nobr>$header</th>";
    }
    $echo_page .= "</tr></thead><tbody>";

    $echo_page .= "<tr class=\"xp-pc-current\"><td colspan=\"" . (count($pc_stats)) . "\">";
    $echo_page .= "<b>" . I18n::plain('pc.current') . "</b>";
    $echo_page .= "</td></tr>";

    $echo_page .= "<tr>";
    // make this a self-reference to facilitate copy-pasting snippets to e-mails
    $echo_page .= "<td><a href=''>" . htmlspecialchars($rep_symbol, ENT_QUOTES, 'UTF-8') . "</a>";
    $echo_page .= XhprofDisplay::print_source_link(array('fn' => $rep_symbol));
    $echo_page .= "</td>";

    if ($display_calls) {
      // Call Count
      $echo_page .= XhprofDisplay::print_td_num($symbol_info["ct"], $format_cbk["ct"]);
      $echo_page .= XhprofDisplay::print_td_pct($symbol_info["ct"], $totals["ct"]);
    }

    // Inclusive Metrics for current public static function
    foreach ($metrics as $metric) {
      $echo_page .= XhprofDisplay::print_td_num($symbol_info[$metric], $format_cbk[$metric], ($sort_col == $metric));
      $echo_page .= XhprofDisplay::print_td_pct($symbol_info[$metric], $totals[$metric], ($sort_col == $metric));
    }
    $echo_page .= "</tr>";
    $echo_page .= "<tr class=\"xp-excl-row\">";
    $echo_page .= "<td style='text-align:right'>"
      . sprintf(I18n::plain('pc.exclusive'), $diff_text === '' ? '' : ' ' . $diff_text)
      . "</td>";

    if ($display_calls) {
      // Call Count
      $echo_page .= "<td $vbar></td>";
      $echo_page .= "<td $vbar></td>";
    }

    // Exclusive Metrics for current public static function
    foreach ($metrics as $metric) {
      $echo_page .= XhprofDisplay::print_td_num(
        $symbol_info["excl_" . $metric],
        $format_cbk["excl_" . $metric],
        ($sort_col == $metric),
        XhprofDisplay::get_tooltip_attributes("Child", $metric)
      );
      $echo_page .= XhprofDisplay::print_td_pct(
        $symbol_info["excl_" . $metric],
        $symbol_info[$metric],
        ($sort_col == $metric),
        XhprofDisplay::get_tooltip_attributes("Child", $metric)
      );
    }
    $echo_page .= "</tr>";

    // list of callers/parent public static functions
    $results = array();
    $base_ct = 0;
    if ($display_calls) $base_ct = $symbol_info["ct"];
    foreach ($metrics as $metric) {
      $base_info[$metric] = $symbol_info[$metric];
    }
    foreach ($run_data as $parent_child => $info) {
      list($parent, $child) = XhprofLib::xhprof_parse_parent_child($parent_child);
      if (($child == $rep_symbol) && ($parent)) {
        $info_tmp = $info;
        $info_tmp["fn"] = $parent;
        $results[] = $info_tmp;
      }
    }
    usort($results, [self::class, 'sort_cbk']);

    if (count($results) > 0) {
      $echo_page .= XhprofDisplay::print_pc_array(
        $url_params,
        $results,
        $base_ct,
        $base_info,
        true,
        $run1,
        $run2
      );
    }

    // list of callees/child public static functions
    $results = array();
    $base_ct = 0;
    foreach ($run_data as $parent_child => $info) {
      list($parent, $child) = XhprofLib::xhprof_parse_parent_child($parent_child);
      if ($parent == $rep_symbol) {
        $info_tmp = $info;
        $info_tmp["fn"] = $child;
        $results[] = $info_tmp;
        if ($display_calls) $base_ct += $info["ct"];
      }
    }
    usort($results, [self::class, 'sort_cbk']);

    if (count($results)) {
      $echo_page .= XhprofDisplay::print_pc_array(
        $url_params,
        $results,
        $base_ct,
        $base_info,
        false,
        $run1,
        $run2
      );
    }

    $echo_page .= "</tbody></table></div>";
    $echo_page .= '</div></div>';

    // These will be used for pop-up tips/help.
    // Related javascript code is in: xhprof_report.js
    $echo_page .= "\n";
    $echo_page .= '<script language="javascript">' . "\n";
    $echo_page .= "var func_name = " . json_encode($rep_symbol) . ";\n";
    $echo_page .= "var total_child_ct  = " . $base_ct . ";\n";
    if ($display_calls) $echo_page .= "var func_ct   = " . $symbol_info["ct"] . ";\n";
    $echo_page .= "var func_metrics = new Array();\n";
    $echo_page .= "var metrics_col  = new Array();\n";
    $echo_page .= "var metrics_desc  = new Array();\n";
    if ($diff_mode) {
      $echo_page .= "var diff_mode = true;\n";
    } else {
      $echo_page .= "var diff_mode = false;\n";
    }
    $column_index = 3; // First three columns are Func Name, Calls, Calls%
    foreach ($metrics as $metric) {
      $echo_page .= "func_metrics[\"" . $metric . "\"] = " . round($symbol_info[$metric]) . ";\n";
      $echo_page .= "metrics_col[\"" . $metric . "\"] = " . $column_index . ";\n";
      $echo_page .= "metrics_desc[\"" . $metric . "\"] = \"" . $possible_metrics[$metric][2] . "\";\n";

      // each metric has two columns..
      $column_index += 2;
    }
    $echo_page .= '</script>';
    $echo_page .= "\n";
    return  $echo_page;
  }

  /**
   * Generate the profiler report for a single run.
   *
   *
   */
  public static function profiler_single_run_report(
    $url_params,
    $xhprof_data,
    $run_desc,
    $rep_symbol,
    $sort,
    $run
  ) {

    XhprofLib::init_metrics($xhprof_data, $rep_symbol, $sort, false);

    return XhprofDisplay::profiler_report(
      $url_params,
      $rep_symbol,
      $run,
      $run_desc,
      $xhprof_data
    );
  }



  /**
   * Generate the profiler report for diff mode (delta between two runs).
   *
   *
   */
  public static function profiler_diff_report(
    $url_params,
    $xhprof_data1,
    $run1_desc,
    $xhprof_data2,
    $run2_desc,
    $rep_symbol,
    $sort,
    $run1,
    $run2
  ) {


    // Initialize what metrics we'll display based on data in Run2
    XhprofLib::init_metrics($xhprof_data2, $rep_symbol, $sort, true);
    return XhprofDisplay::profiler_report(
      $url_params,
      $rep_symbol,
      $run1,
      $run1_desc,
      $xhprof_data1,
      $run2,
      $run2_desc,
      $xhprof_data2
    );
  }



  public static function displayXHProfReport(
    $url_params,
    $source,
    $run,
    $wts,
    $symbol,
    $sort,
    $run1,
    $run2
  ) {

    $data = XhprofDisplay::show_nav($url_params);
    if ($run) {                              // specific run to display?
      $runs_array = explode(",", $run);
      if (count($runs_array) == 1) {
        $xhprof_data = XHProfRunsDefault::get_run(
          $runs_array[0],
          $source,
          $description
        );
      } else {
        $wts_array = null;
        if (!empty($wts)) $wts_array  = explode(",", $wts);
        $datas = XhprofLib::xhprof_aggregate_runs(
          $runs_array,
          $wts_array,
          $source,
          false
        );
        $xhprof_data = $datas['raw'];
        $description = $datas['description'];
      }

      if ($xhprof_data === false || $xhprof_data === null) {
        // 无数据时优雅降级（run_id 格式合法但缓存里没有 / 聚合后一个有效 run 都没有），
        // 不进入渲染管线。但**不能只留一条导航条**：用户看到的是一个几乎空白的页面，
        // 读起来像「报告坏了」，而事实只是这条记录过期了（默认 TTL 7 天）。
        return $data . '<div class="xp-main"><div class="xp-card">'
          . '<div class="xp-card-title">' . I18n::plain('report.title') . '</div>'
          . '<div class="xp-card-note">' . I18n::plain('report.noData') . '</div>'
          . '</div></div>';
      }

      $data .= XhprofDisplay::profiler_single_run_report(
        $url_params,
        $xhprof_data,
        $description,
        $symbol,
        $sort,
        $run
      );
    } else if ($run1 && $run2) {                  // diff report for two runs

      $xhprof_data1 = XHProfRunsDefault::get_run($run1, $source, $description1);
      $xhprof_data2 = XHProfRunsDefault::get_run($run2, $source, $description2);
      if ($xhprof_data1 === false || $xhprof_data2 === false) {
        return $data;
      }
      $data .= XhprofDisplay::profiler_diff_report(
        $url_params,
        $xhprof_data1,
        $description1,
        $xhprof_data2,
        $description2,
        $symbol,
        $sort,
        $run1,
        $run2
      );
    } else {
      $data .= XHProfRunsDefault::list_runs();
    }
    return $data;
  }

  public static function show_nav($url_params)
  {
    $base_path = XhprofDisplay::base_path();
    $base_url_params = XhprofLib::xhprof_array_unset($url_params, 'symbol');
    $top_link_query_string = "$base_path?" . http_build_query($base_url_params);
    $li_html = "";
    // 文案逐条取词表再拼接：导航是「首页 | 运行报告 | 方法详情」这种 HTML 片段，
    // 不是整串独立文案，不能整段丢给译者（会让 href 一起被改写）。
    //
    // 「首页」的 href 必须由 report_url() 生成：裸 $base_path 会把整个查询串丢掉，
    // 于是 `?token=xxx`（配了鉴权就 403）与 `?lang=xx`（选了语言又退回浏览器语言）
    // 都传不过去。report_url() 默认摘掉视图参数，所以「首页」不会停在原 run 上。
    $home_href = XhprofLib::report_url();
    $nav_home = '<li><a href="' . $home_href . '">' . I18n::plain('nav.home') . '</a></li>';
    if (isset($url_params['run']) && isset($url_params['symbol'])) {
      $li_html = $nav_home
        . '<li><a href="' . $top_link_query_string . '">' . I18n::plain('nav.runs') . '</a></li>'
        . '<li class="active"><span>' . I18n::plain('nav.symbol') . '</span></li>';
    } else if (isset($url_params['run'])) {
      $li_html = $nav_home
        . '<li class="active"><a href="' . $top_link_query_string . '">' . I18n::plain('nav.runs') . '</a></li>';
    } else {
      $li_html = '<li class="active"><a href="' . $home_href . '">' . I18n::plain('nav.home') . '</a></li>';
    }

    // 语言切换器：13 种语言此前在报告页上**没有任何入口**（只能靠 ?lang=／配置／浏览器
    // 协商），而语言参数现在会随链接传播，所以一个下拉就能把整站语言换掉。
    //
    // 用 `<select onchange="location.href=this.value">`，不用表单也不用外部 JS：
    // 表单会把 `?token=` 丢掉（鉴权就 403），而外部 JS 在脚本没加载时控件就废了。
    // 每个 option 的值都由 report_url() 生成 —— 与页面里其它链接同一个构造函数，
    // 所以当前查询串里除 lang 之外的参数（token、排序…）都跟着走。
    // 标签用**各语言的自称**（词表 `_meta.name`，如「한국어」），不必翻译。
    $lang_options = '';
    foreach (I18n::AVAILABLE as $code) {
        $meta = I18n::catalogOf($code)['_meta'] ?? null;
        $name = is_array($meta) && isset($meta['name']) ? (string) $meta['name'] : $code;
        $lang_options .= '<option value="' . XhprofLib::report_url(array('lang' => $code)) . '"'
            . ($code === I18n::locale() ? ' selected' : '') . '>'
            . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    $switcher = '<select class="xp-lang" title="' . I18n::plain('nav.language')
        . '" aria-label="' . I18n::plain('nav.language')
        . '" onchange="location.href=this.value">' . $lang_options . '</select>';

    return '<nav class="xp-nav"><div class="xp-nav-inner">'
      . '<a href="' . $home_href . '" class="xp-brand"><span class="xp-brand-icon"></span>' . I18n::plain('nav.brand') . '</a>'
      . '<ul class="xp-nav-links">' . $li_html . '</ul>'
      . '<div class="xp-nav-extra">' . $switcher
      . '<a href="https://github.com/erikwang2013/xhprof-webman" target="_blank" rel="noopener" title="GitHub">GitHub</a></div>'
      . '</div></nav>';
  }
}
