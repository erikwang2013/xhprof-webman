<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\XhprofLib\Display;

use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XhprofLib;
use ErikWang2013\Xhprof\Core\XhprofLib\Utils\XHProfRunsDefault;
use ErikWang2013\Xhprof\Core\Xhprof;

class XhprofDisplay
{

  public static function base_path()
  {
    $arr = parse_url(Xhprof::getRequest()->uri());
    return rtrim($arr['path'], '/\\');
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

    // javascript
    $echo_page .= "<script src='$ui_dir_url_path/js/xhprof_report.js'></script>";

    $echo_page .= "<script src='$ui_dir_url_path/jquery/jquery-3.0.0.min.js'></script>";
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
      $left = $a[$sort_col];
      $right = $b[$sort_col];
      if ($diff_mode) {
        $left = abs($left);
        $right = abs($right);
      }
      if ($left == $right)  return 0;
      return ($left > $right) ? -1 : 1;
    }
  }


  public static function stat_description($stat)
  {
    $descriptions = XhprofDisplay::$descriptions;
    $diff_descriptions = XhprofDisplay::$diff_descriptions;
    $diff_mode = XhprofDisplay::$diff_mode;
    $result = $descriptions[$stat];;
    if ($diff_mode) $result = $diff_descriptions[$stat];
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
    $run1_txt = sprintf(
      "<b>Run #%s:</b> %s",
      $run1,
      $run1_desc
    );

    $base_url_params = XhprofLib::xhprof_array_unset(XhprofLib::xhprof_array_unset($url_params, 'symbol'), 'all');
    if ($diff_mode) {
      $diff_text = "Diff";
      $base_url_params = XhprofLib::xhprof_array_unset($base_url_params, 'run1');
      $base_url_params = XhprofLib::xhprof_array_unset($base_url_params, 'run2');
      $run1_link = XhprofDisplay::xhprof_render_link(
        'View Run #' . $run1,
        "$base_path?" .
          http_build_query(XhprofLib::xhprof_array_set(
            $base_url_params,
            'run',
            $run1
          ))
      );
      $run2_txt = sprintf(
        "<b>Run #%s:</b> %s",
        $run2,
        $run2_desc
      );

      $run2_link = XhprofDisplay::xhprof_render_link(
        'View Run #' . $run2,
        "$base_path?" .
          http_build_query(XhprofLib::xhprof_array_set(
            $base_url_params,
            'run',
            $run2
          ))
      );
    } else {
      $diff_text = "Run";
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
        'Invert ' . $diff_text . ' Report',
        "$base_path?" .
          http_build_query($inverted_params)
      );
    }


    $links[] = '<div class="xp-search"><input type="text" class="xhprof-search-input" placeholder="查找 函数/方法名..." id="xhprofFuncSearch"><button type="button" id="funcSub">搜索</button></div>';
    $echo_page = XhprofDisplay::xhprof_render_actions($links);


    // data tables
    if (!empty($rep_symbol)) {
      if (!isset($symbol_tab[$rep_symbol])) {
        $echo_page .= '<div class="xp-main"><div class="xp-card"><p class="xp-card-title">Symbol <b>' . htmlspecialchars($rep_symbol, ENT_QUOTES, 'UTF-8') . '</b> not found in XHProf run.</p></div></div>';
      }

      /* single public static function report with parent/child information */
      if ($diff_mode) {
        $info1 = isset($symbol_tab1[$rep_symbol]) ?
          $symbol_tab1[$rep_symbol] : null;
        $info2 = isset($symbol_tab2[$rep_symbol]) ?
          $symbol_tab2[$rep_symbol] : null;
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
   * Computes percentage for a pair of values, and returns it
   * in string format.
   */
  public static function pct($a, $b)
  {
    $res = "N/A";
    if ($b != 0) $res = (round(($a * 1000 / $b)) / 10);
    return $res;
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
    if ($denom != 0) $pct = XhprofDisplay::xhprof_percent_format($numer / abs($denom));
    return "<td $attributes $class>$pct</td>\n";
  }

  /**
   * Print "flat" data corresponding to one public static function.
   *
   *
   */
  public static function print_function_info($url_params, $info)
  {
    static $odd_even = 0;

    $totals = XhprofDisplay::$totals;
    $sort_col = XhprofDisplay::$sort_col;
    $metrics = XhprofDisplay::$metrics;
    $format_cbk = XhprofDisplay::$format_cbk;
    $display_calls = XhprofDisplay::$display_calls;
    $base_path = XhprofDisplay::base_path();

    // Toggle $odd_or_even
    $odd_even = 1 - $odd_even;
    $echo_page = "";
    $echo_page .= $odd_even ? '<tr>' : '<tr class="xp-tr-alt">';

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
        " [ <b class=bubble>display all </b>]",
        "$base_path?" .
          http_build_query(XhprofLib::xhprof_array_set(
            $url_params,
            'all',
            1
          ))
      );
    }


    //$echo_page ='<h3 align=center>'.$title.' '.$display_link.'</h3><br>';
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
        $echo_page .= XhprofDisplay::print_function_info($url_params, $flat_data[$i]);
      }
    } else {
      // if $limit is negative, print abs($limit) items starting from the end
      $limit = min($size, abs($limit));
      for ($i = 0; $i < $limit; $i++) {
        $echo_page .= XhprofDisplay::print_function_info($url_params, $flat_data[$size - $i - 1]);
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
    $vbar = XhprofDisplay::$vbar;
    $totals = XhprofDisplay::$totals;
    $totals_1 = XhprofDisplay::$totals_1;
    $totals_2 = XhprofDisplay::$totals_2;
    $metrics = XhprofDisplay::$metrics;
    $diff_mode = XhprofDisplay::$diff_mode;
    $descriptions = XhprofDisplay::$descriptions;
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

      $echo_page .= '<h3 style="margin:0 0 12px 0;font-size:15px">Overall Diff Summary</h3>';
      $echo_page .= '<table class="xp-table"><tr>';
      $echo_page .= "<th></th>";
      $echo_page .= "<th $vwbar>" . XhprofDisplay::xhprof_render_link("Run #$run1", $href1) . "</th>";
      $echo_page .= "<th $vwbar>" . XhprofDisplay::xhprof_render_link("Run #$run2", $href2) . "</th>";
      $echo_page .= "<th $vwbar>Diff</th>";
      $echo_page .= "<th $vwbar>Diff%</th>";
      $echo_page .= '</tr>';

      if ($display_calls) {
        $echo_page .= '<tr>';
        $echo_page .= "<td>Number of Function Calls</td>";
        $echo_page .= XhprofDisplay::print_td_num($totals_1["ct"], $format_cbk["ct"]);
        $echo_page .= XhprofDisplay::print_td_num($totals_2["ct"], $format_cbk["ct"]);
        $echo_page .= XhprofDisplay::print_td_num($totals_2["ct"] - $totals_1["ct"], $format_cbk["ct"], true);
        $echo_page .= XhprofDisplay::print_td_pct($totals_2["ct"] - $totals_1["ct"], $totals_1["ct"], true);
        $echo_page .= '</tr>';
      }

      foreach ($metrics as $metric) {
        $m = $metric;
        $echo_page .= '<tr>';
        $echo_page .= "<td>" . str_replace("<br>", " ", $descriptions[$m]) . "</td>";
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
      $echo_page .= "<td>请求方法</td><td>" . htmlspecialchars($method, ENT_QUOTES, 'UTF-8') . "</td>";
      $echo_page .= "<td>请求时间</td><td>{$create_time_text}</td>";
      $echo_page .= "<td>来源IP</td><td>" . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . "</td>";
      if ($display_calls) {
        $echo_page .= "<td>函数/方法调用总次数</td><td>" . number_format($totals['ct']) . "</td>";
      }
      $echo_page .= "</tr><tr>";
      foreach ($metrics as $metric) {
        $echo_page .= "<td>" . str_replace("<br>", " ", XhprofDisplay::stat_description($metric)) . "</td>";
        $echo_page .= "<td>" . number_format($totals[$metric]) . " " . $possible_metrics[$metric][1] . "</td>";
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

    $desc = str_replace("<br>", " ", $descriptions[$sort_col]);
    if ($diff_mode) {
      $title = "Top 100 <i style='color:red'>Regressions</i>/"
        . "<i style='color:green'>Improvements</i>: "
        . "Sorted by $desc Diff";
      if ($all) $title = "Total Diff Report: Sorted by absolute value of regression/improvement in $desc";
    } else {
      $title = "Displaying top $limit public static functions: Sorted by $desc";
      if ($all)  $title = "Sorted by $desc";
    }
    $echo_page .= XhprofDisplay::print_flat_data($url_params, $title, $flat_data, $limit);
    $echo_page .= '</div>';
    return $echo_page;
  }


  /**
   * Return attribute names and values to be used by javascript tooltip.
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
    if ($display_calls) {
      $mouseoverct = XhprofDisplay::get_tooltip_attributes($type, "ct");
      /* call count */
      XhprofDisplay::print_td_num($info["ct"], $format_cbk["ct"], ($sort_col == "ct"), $mouseoverct);
      XhprofDisplay::print_td_pct($info["ct"], $base_ct, ($sort_col == "ct"), $mouseoverct);
    }

    /* Inclusive metric values  */
    foreach ($metrics as $metric) {
      XhprofDisplay::print_td_num(
        $info[$metric],
        $format_cbk[$metric],
        ($sort_col == $metric),
        XhprofDisplay::get_tooltip_attributes($type, $metric)
      );
      XhprofDisplay::print_td_pct(
        $info[$metric],
        $base_info[$metric],
        ($sort_col == $metric),
        XhprofDisplay::get_tooltip_attributes($type, $metric)
      );
    }
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
    $title = 'Child public static function';
    if ($parent) $title = 'Parent public static function';
    if (count($results) > 1) $title .= 's';
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


  public static function print_symbol_summary($symbol_info, $stat, $base)
  {

    $val = $symbol_info[$stat];
    $desc = str_replace("<br>", " ", XhprofDisplay::stat_description($stat));

    $echo_page = "$desc: </td>";
    $echo_page .= number_format($val);
    $echo_page .= " (" . XhprofDisplay::pct($val, $base) . "% of overall)";
    if (substr($stat, 0, 4) == "excl") {
      $func_base = $symbol_info[str_replace("excl_", "", $stat)];
      $echo_page .= " (" . XhprofDisplay::pct($val, $func_base) . "% of this public static function)";
    }
    $echo_page .= "<br>";
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
    $descriptions = XhprofDisplay::$descriptions;
    $format_cbk = XhprofDisplay::$format_cbk;
    $sort_col = XhprofDisplay::$sort_col;
    $display_calls = XhprofDisplay::$display_calls;
    $base_path = XhprofDisplay::base_path();

    $echo_page = '<div class="xp-main"><div class="xp-card">';
    $possible_metrics = XhprofLib::xhprof_get_possible_metrics();
    $diff_text = "";
    $regr_impr = "";
    if ($diff_mode) {
      $diff_text = "<b>Diff</b>";
      $regr_impr = "<i style='color:red'>Regression</i>/<i style='color:green'>Improvement</i>";
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

      $echo_page .= "<h3 align=center>$regr_impr summary for " . htmlspecialchars($rep_symbol, ENT_QUOTES, 'UTF-8') . "<br><br></h3>";
      $echo_page .= '<table border=1 cellpadding=2 cellspacing=1 width="30%" '
        . 'rules=rows bordercolor="#bdc7d8" align=center>' . "\n";
      $echo_page .= '<tr bgcolor="#bdc7d8" align=right>';
      $echo_page .= "<th align=left>" . htmlspecialchars($rep_symbol, ENT_QUOTES, 'UTF-8') . "</th>";
      $echo_page .= "<th $vwbar><a href=" . $href1 . ">Run #$run1</a></th>";
      $echo_page .= "<th $vwbar><a href=" . $href2 . ">Run #$run2</a></th>";
      $echo_page .= "<th $vwbar>Diff</th>";
      $echo_page .= "<th $vwbar>Diff%</th>";
      $echo_page .= '</tr>';
      $echo_page .= '<tr>';

      if ($display_calls) {
        $echo_page .= "<td>Number of Function Calls</td>";
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
        $echo_page .= "<td>" . str_replace("<br>", " ", $descriptions[$m]) . "</td>";
        $echo_page .= XhprofDisplay::print_td_num($symbol_info1[$m], $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($symbol_info2[$m], $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($symbol_info2[$m] - $symbol_info1[$m], $format_cbk[$m], true);
        $echo_page .= XhprofDisplay::print_td_pct($symbol_info2[$m] - $symbol_info1[$m], $symbol_info1[$m], true);
        $echo_page .= '</tr>';

        // AVG (per call) Inclusive stat for metric
        $echo_page .= '<tr>';
        $echo_page .= "<td>" . str_replace("<br>", " ", $descriptions[$m]) . " per call </td>";
        $avg_info1 = 'N/A';
        $avg_info2 = 'N/A';
        if ($symbol_info1['ct'] > 0) $avg_info1 = ($symbol_info1[$m] / $symbol_info1['ct']);
        if ($symbol_info2['ct'] > 0) $avg_info2 = ($symbol_info2[$m] / $symbol_info2['ct']);
        $echo_page .= XhprofDisplay::print_td_num($avg_info1, $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($avg_info2, $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($avg_info2 - $avg_info1, $format_cbk[$m], true);
        $echo_page .= XhprofDisplay::print_td_pct($avg_info2 - $avg_info1, $avg_info1, true);
        $echo_page .= '</tr>';

        // Exclusive stat for metric
        $m = "excl_" . $metric;
        $echo_page .= '<tr style="border-bottom: 1px solid black;">';
        $echo_page .= "<td>" . str_replace("<br>", " ", $descriptions[$m]) . "</td>";
        $echo_page .= XhprofDisplay::print_td_num($symbol_info1[$m], $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($symbol_info2[$m], $format_cbk[$m]);
        $echo_page .= XhprofDisplay::print_td_num($symbol_info2[$m] - $symbol_info1[$m], $format_cbk[$m], true);
        $echo_page .= XhprofDisplay::print_td_pct($symbol_info2[$m] - $symbol_info1[$m], $symbol_info1[$m], true);
        $echo_page .= '</tr>';
      }
      $echo_page .= '</table>';
    }

    $echo_page .= "<h4><center>";
    $echo_page .= "Parent/Child $regr_impr report for <b>" . htmlspecialchars($rep_symbol, ENT_QUOTES, 'UTF-8') . "</b>";

    $echo_page .= "</center></h4>";

    //    print('<table class="table table-condensed table-bordered">');
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
    $echo_page .= "<b>Current Function</b>";
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
      . "Exclusive Metrics $diff_text for Current Function</td>";

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
        // 无数据时优雅降级（run_id 合法但缓存缺失 / 聚合全部无效），不进入渲染管线
        return $data;
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
    if (isset($url_params['run']) && isset($url_params['symbol'])) {
      $li_html = '<li><a href="' . $base_path . '">首页</a></li><li><a href="' . $top_link_query_string . '">运行报告</a></li><li class="active"><span>方法详情</span></li>';
    } else if (isset($url_params['run'])) {
      $li_html = '<li><a href="' . $base_path . '">首页</a></li><li class="active"><a href="' . $top_link_query_string . '">运行报告</a></li>';
    } else {
      $li_html = '<li class="active"><a href="' . $base_path . '">首页</a></li>';
    }

    return '<nav class="xp-nav"><div class="xp-nav-inner">'
      . '<a href="' . $base_path . '" class="xp-brand"><span class="xp-brand-icon"></span>XHProf 性能分析</a>'
      . '<ul class="xp-nav-links">' . $li_html . '</ul>'
      . '<div class="xp-nav-extra"><a href="https://github.com/erikwang2013/xhprof-webman" target="_blank" rel="noopener" title="GitHub">GitHub</a></div>'
      . '</div></nav>';
  }
}
