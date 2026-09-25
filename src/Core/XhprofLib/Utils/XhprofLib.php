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
use ErikWang2013\Xhprof\Core\XhprofLib\Display\XhprofDisplay;
use ErikWang2013\Xhprof\Core\Xhprof;


class XhprofLib
{


  public static function xhprof_error($message)
  {
    Xhprof::getLogger()->error("Xhprof：" . $message);
    return;
  }

  /*
 * The list of possible metrics collected as part of XHProf that
 * require inclusive/exclusive handling while reporting.
 *
 *
 */
  public static function xhprof_get_possible_metrics()
  {
    static $possible_metrics = array(
      "wt" => array("Wall", "microsecs", "walltime"),
      "ut" => array("User", "microsecs", "user cpu time"),
      "st" => array("Sys", "microsecs", "system cpu time"),
      "cpu" => array("Cpu", "microsecs", "cpu time"),
      "mu" => array("MUse", "bytes", "memory usage"),
      "pmu" => array("PMUse", "bytes", "peak memory usage"),
      "samples" => array("Samples", "samples", "cpu time")
    );
    return $possible_metrics;
  }

  /**
   * Initialize the metrics we'll display based on the information
   * in the raw data.
   *
   *
   */
  public static function init_metrics($xhprof_data, $rep_symbol, $sort, $diff_report = false)
  {
    // 先按原始数据算出实际采集到的指标，供排序白名单使用。
    // $sortable_columns 是固定的 16 列全集，含 ut/st/samples——本扩展的 flags
    // (NO_BUILTINS|CPU|MEMORY) 永不采集这三项，放行会让 sort_cbk 取到 null。
    $metrics = array();
    $possible_metrics = XhprofLib::xhprof_get_possible_metrics();
    foreach ($possible_metrics as $metric => $desc) {
      if (isset($xhprof_data["main()"][$metric])) $metrics[] = $metric;
    }

    $display_calls = isset($xhprof_data["main()"]["wt"]);

    // 初值用字面量而非 XhprofDisplay::$sort_col：常驻 worker（webman/hyperf）下
    // 静态不随请求结束，读它会把上一请求的排序列继承过来——极端时直接崩在 sort_cbk。
    $sort_col = "wt";
    if (!empty($sort)) {
      $sortable = array("fn" => 1);
      if ($display_calls) $sortable["ct"] = 1;
      foreach ($metrics as $metric) {
        $sortable[$metric] = 1;
        $sortable["excl_" . $metric] = 1;
      }
      if (isset($sortable[$sort])) {
        $sort_col = $sort;
      } else {
        Xhprof::getLogger()->error("Invalid Sort Key $sort specified in URL");
      }
    }

    if (!$display_calls && $sort_col == "wt") $sort_col = "samples";

    if (!empty($rep_symbol)) $sort_col = str_replace("excl_", "", $sort_col);
    $stats = array("fn");
    if ($display_calls) $stats = array("fn", "ct", "Calls%");
    $pc_stats = $stats;
    foreach ($metrics as $metric) {
      $desc = $possible_metrics[$metric];
      $stats[] = $metric;
      $stats[] = "I" . $desc[0] . "%";
      $stats[] = "excl_" . $metric;
      $stats[] = "E" . $desc[0] . "%";
      $pc_stats[] = $metric;
      $pc_stats[] = "I" . $desc[0] . "%";
    }
    // 这六个量是按请求算的，写进 XhprofDisplay 的**本协程**渲染状态里
    // （Hyperf 下是协程 Context，其余框架是静态属性，见该方法的注释）。
    XhprofDisplay::set_render_state(array(
      'metrics' => $metrics,
      'stats' => $stats,
      'pc_stats' => $pc_stats,
      'diff_mode' => $diff_report,
      'sort_col' => $sort_col,
      'display_calls' => $display_calls,
    ));
    // 下面五个每次请求写入的值都相同（常量），故意不进协程隔离。
    XhprofDisplay::$vwbar = 'class="vwbar"';
    XhprofDisplay::$vbar = 'class="vbar"';
    XhprofDisplay::$vbbar = 'class="vbbar"';
    XhprofDisplay::$vrbar = 'class="vrbar"';
    XhprofDisplay::$vgbar = 'class="vgbar"';
  }

  /*
 * Get the list of metrics present in $xhprof_data as an array.
 *
 *
 */
  public static function xhprof_get_metrics($xhprof_data)
  {
    $possible_metrics = XhprofLib::xhprof_get_possible_metrics();
    $metrics = array();
    foreach ($possible_metrics as $metric => $desc) {
      if (!isset($xhprof_data["main()"][$metric])) continue;
      $metrics[] = $metric;
    }
    return $metrics;
  }


  public static function xhprof_parse_parent_child($parent_child)
  {
    $ret = explode("==>", $parent_child);
    if (isset($ret[1])) return $ret;
    return array(null, $ret[0]);
  }


  public static function xhprof_build_parent_child_key($parent, $child)
  {
    if ($parent) return $parent . "==>" . $child;
    return $child;
  }


  public static function xhprof_valid_run($run_id, $raw_data)
  {

    // 先判掉「读不到」：get_run() 在 run 过期（默认 TTL 7 天）或缓存被清时返回 false，
    // 而 `false["main()"]` 会立下一条 "Trying to access array offset on false" 警告
    // （PHPUnit 开了 failOnWarning）。过期是**常规**事件，不该是一条看不懂的警告。
    if (!is_array($raw_data)) {
      XhprofLib::xhprof_error("XHProf: no data for Run ID: $run_id");
      return false;
    }

    $main_info = $raw_data["main()"];
    if (empty($main_info)) {
      XhprofLib::xhprof_error("XHProf: main() missing in raw data for Run ID: $run_id");
      return false;
    }

    if (isset($main_info["wt"])) {
      $metric = "wt";
    } else if (isset($main_info["samples"])) {
      $metric = "samples";
    } else {
      XhprofLib::xhprof_error("XHProf: Wall Time information missing from Run ID: $run_id");
      return false;
    }

    foreach ($raw_data as $info) {
      $val = $info[$metric];
      if ($val < 0) {
        XhprofLib::xhprof_error("XHProf: $metric should not be negative: Run ID $run_id"
          . serialize($info));
        return false;
      }
      if ($val > (86400000000)) {
        XhprofLib::xhprof_error("XHProf: $metric > 1 day found in Run ID: $run_id "
          . serialize($info));
        return false;
      }
    }
    return true;
  }


  public static function xhprof_trim_run($raw_data, $functions_to_keep)
  {


    $function_map = array_fill_keys($functions_to_keep, 1);
    $function_map['main()'] = 1;
    $new_raw_data = array();
    foreach ($raw_data as $parent_child => $info) {
      list($parent, $child) = XhprofLib::xhprof_parse_parent_child($parent_child);
      if (isset($function_map[$parent]) || isset($function_map[$child])) {
        $new_raw_data[$parent_child] = $info;
      }
    }

    return $new_raw_data;
  }

  public static function xhprof_normalize_metrics($raw_data, $num_runs)
  {

    if (empty($raw_data) || ($num_runs == 0)) return $raw_data;
    $raw_data_total = array();
    if (isset($raw_data["==>main()"]) && isset($raw_data["main()"])) XhprofLib::xhprof_error("XHProf Error: both ==>main() and main() set in raw data...");
    foreach ($raw_data as $parent_child => $info) {
      foreach ($info as $metric => $value) {
        $raw_data_total[$parent_child][$metric] = ($value / $num_runs);
      }
    }

    return $raw_data_total;
  }


  public static function xhprof_aggregate_runs(
    $runs,
    $wts,
    $source = "phprof",
    $use_script_name = false
  ) {

    $raw_data_total = null;
    $raw_data       = null;
    $metrics        = array();

    $run_count = count($runs);
    $wts_count = is_array($wts) ? count($wts) : 0;

    // wts 直接来自查询串，除了个数还必须都是数值：
    // 否则下面 $wt * $info[$metric] 在 PHP 8 下抛 "string * int" TypeError。
    if (($run_count == 0) ||
      (($wts_count > 0) && ($run_count != $wts_count)) ||
      (($wts_count > 0) && count(array_filter($wts, 'is_numeric')) != $wts_count)
    ) {
      return array(
        'description' => I18n::t('agg.invalidInput'),
        'raw'  => null
      );
    }

    $bad_runs = array();
    foreach ($runs as $idx => $run_id) {
      $raw_data = XHProfRunsDefault::get_run($run_id, $source, $description);

      if (!XhprofLib::xhprof_valid_run($run_id, $raw_data)) {
        $bad_runs[] = $run_id;
        continue;
      }

      // 指标集取自**第一个有效**的 run。此前它写在有效性检查之前、且写死 `$idx == 0`：
      // 第一个 run 过期（TTL 默认 7 天）时 get_run() 返回 false，`foreach (false["main()"])`
      // 只立下警告，$metrics 留空 → 后面每个 `foreach ($metrics …)` 都不进 →
      // $raw_data_total 保持 null → 报告页只剩导航条。第二个 run 明明可读也白搭。
      if (!$metrics) {
        foreach (($raw_data["main()"] ?? array()) as $metric => $val) {
          if ($metric != "pmu" && isset($val)) $metrics[] = $metric;
        }
      }

      if ($use_script_name) {
        $page = $description;
        if ($page) {
          foreach ($raw_data["main()"] as $metric => $val) {
            $fake_edge[$metric] = $val;
            $new_main[$metric]  = $val + 0.00001;
          }
          $raw_data["main()"] = $new_main;
          $raw_data[XhprofLib::xhprof_build_parent_child_key(
            "main()",
            "__script::$page"
          )]
            = $fake_edge;
        } else {
          $use_script_name = false;
        }
      }
      $wt = ($wts_count == 0) ? 1 : $wts[$idx];
      foreach ($raw_data as $parent_child => $info) {
        if ($use_script_name) {
          if (substr($parent_child, 0, 9) == "main()==>") {
            $child = substr($parent_child, 9);
            if (substr($child, 0, 10) != "__script::") {
              $parent_child = XhprofLib::xhprof_build_parent_child_key(
                "__script::$page",
                $child
              );
            }
          }
        }

        if (!isset($raw_data_total[$parent_child])) {
          foreach ($metrics as $metric) {
            $raw_data_total[$parent_child][$metric] = ($wt * $info[$metric]);
          }
        } else {
          foreach ($metrics as $metric) {
            $raw_data_total[$parent_child][$metric] += ($wt * $info[$metric]);
          }
        }
      }
    }

    $runs_string = implode(",", $runs);
    $wts_string = "";
    $normalization_count = $run_count;
    if (isset($wts)) {
      $wts_string  = sprintf(I18n::t('agg.ratio'), implode(":", $wts));
      $normalization_count = array_sum($wts);
    }

    $run_count = $run_count - count($bad_runs);
    // 单复数拆两键：原文案在只聚合到 1 个可用 run 时会印出 "for 1 runs"。
    // 这段描述随后由 XhprofDisplay 的 sprintf("<b>…</b>") 转义，故用 t()。
    $data['description'] = ($run_count === 1
      ? sprintf(I18n::t('agg.titleOne'), $runs_string, $wts_string)
      : sprintf(I18n::t('agg.title'), $run_count, $runs_string, $wts_string)) . "\n";
    $data['raw'] = XhprofLib::xhprof_normalize_metrics(
      $raw_data_total,
      $normalization_count
    );
    $data['bad_runs'] = $bad_runs;

    return $data;
  }


  public static function xhprof_compute_flat_info($raw_data, &$overall_totals)
  {

    $display_calls = XhprofDisplay::display_calls();
    $metrics = XhprofLib::xhprof_get_metrics($raw_data);
    $overall_totals = array(
      "ct" => 0,
      "wt" => 0,
      "ut" => 0,
      "st" => 0,
      "cpu" => 0,
      "mu" => 0,
      "pmu" => 0,
      "samples" => 0
    );

    $symbol_tab = XhprofLib::xhprof_compute_inclusive_times($raw_data);
    foreach ($metrics as $metric) {
      $overall_totals[$metric] = $symbol_tab["main()"][$metric];
    }

    foreach ($symbol_tab as $symbol => $info) {
      foreach ($metrics as $metric) {
        $symbol_tab[$symbol]["excl_" . $metric] = $symbol_tab[$symbol][$metric];
      }
      if ($display_calls) $overall_totals["ct"] += $info["ct"];
    }
    if(false==is_array($raw_data)) return $symbol_tab;
    foreach ($raw_data as $parent_child => $info) {
      list($parent, $child) = XhprofLib::xhprof_parse_parent_child($parent_child);
      if ($parent) {
        foreach ($metrics as $metric) {
          if (isset($symbol_tab[$parent])) $symbol_tab[$parent]["excl_" . $metric] -= $info[$metric];
        }
      }
    }

    return $symbol_tab;
  }

  /**
   * Hierarchical diff:
   * Compute and return difference of two call graphs: Run2 - Run1.
   *
   *
   */
  public static function xhprof_compute_diff($xhprof_data1, $xhprof_data2)
  {
    $display_calls = XhprofDisplay::display_calls();

    // use the second run to decide what metrics we will do the diff on
    $metrics = XhprofLib::xhprof_get_metrics($xhprof_data2);
    $xhprof_delta = $xhprof_data2;
    foreach ($xhprof_data1 as $parent_child => $info) {

      if (!isset($xhprof_delta[$parent_child])) {
        $xhprof_delta[$parent_child] = array();
        if ($display_calls) $xhprof_delta[$parent_child] = array("ct" => 0);
        foreach ($metrics as $metric) {
          $xhprof_delta[$parent_child][$metric] = 0;
        }
      }

      if ($display_calls) $xhprof_delta[$parent_child]["ct"] -= $info["ct"];
      foreach ($metrics as $metric) {
        $xhprof_delta[$parent_child][$metric] -= $info[$metric];
      }
    }

    return $xhprof_delta;
  }


  public static function xhprof_compute_inclusive_times($raw_data)
  {
    $display_calls = XhprofDisplay::display_calls();
    $metrics = XhprofLib::xhprof_get_metrics($raw_data);
    $symbol_tab = array();
    if(false==is_array($raw_data)) return $symbol_tab;
    foreach ($raw_data as $parent_child => $info) {
      list($parent, $child) = XhprofLib::xhprof_parse_parent_child($parent_child);
      if ($parent == $child) {
        XhprofLib::xhprof_error("Error in Raw Data: parent & child are both: $parent");
        return array();   // 不能裸 return：调用方会接着对 null 取下标并 foreach
      }

      if (!isset($symbol_tab[$child])) {
        $symbol_tab[$child] = array();
        if ($display_calls) $symbol_tab[$child] = array("ct" => $info["ct"]);
        foreach ($metrics as $metric) {
          $symbol_tab[$child][$metric] = $info[$metric];
        }
      } else {
        if ($display_calls) $symbol_tab[$child]["ct"] += $info["ct"];
        foreach ($metrics as $metric) {
          $symbol_tab[$child][$metric] += $info[$metric];
        }
      }
    }

    return $symbol_tab;
  }


  /**
   * Set one key in an array and return the array
   *
   *
   */
  public static function xhprof_array_set($arr, $k, $v)
  {
    $arr[$k] = $v;
    return $arr;
  }

  /**
   * Removes/unsets one key in an array and return the array
   *
   *
   */
  public static function xhprof_array_unset($arr, $k)
  {
    unset($arr[$k]);
    return $arr;
  }

  /**
   * 「当前看的是哪一页」的视图参数：页面之间跳转时不该无脑带着它们走
   * （点「首页」时还留着 `?run=` 就会停在原来那个 run 上）。
   */
  public const VIEW_PARAMS = array(
    'run', 'run1', 'run2', 'symbol', 'all', 'sort', 'wts', 'requrl', 'source',
  );

  /**
   * 报告页内部链接 = 当前路径 + 合并后的查询串，**相对 URL**。
   *
   * 三个问题都用「相对」解决，而不是拼一个绝对 URL：
   *
   *  1. `?token=xxx`（鉴权）与 `?lang=xx`（语言）必须随链接传播。此前 run 列表的
   *     查询串是硬编码的、首页与品牌链接干脆不带查询串 —— 于是配了 `auth_token`
   *     后点任何一个 run 都是 403，用 `?lang=` 选了语言点一下也退回浏览器语言。
   *  2. 端口：`host()` 的契约是不含端口，绝对 URL 会把非 80/443 部署的链接指到
   *     错误 origin。相对 URL 由浏览器按当前 origin 补全，天然正确。
   *  3. `x-forwarded-proto` 这个**未校验**的头以前会进 href（实测能把
   *     `javascript:` 塞进去）。这条路径不再读它，注入面消失。
   *
   * @param array      $params 要设/覆盖的参数（值为 null 表示删掉该参数）
   * @param array|null $drop   要从当前请求里摘掉的参数，默认 {@see self::VIEW_PARAMS}
   */
  public static function report_url($params = array(), $drop = null)
  {
    $drop  = $drop === null ? self::VIEW_PARAMS : $drop;
    $query = (array) Xhprof::getRequest()->all();
    foreach ($drop as $k) {
      unset($query[$k]);
    }
    foreach ((array) $params as $k => $v) {
      if ($v === null) {
        unset($query[$k]);
      } else {
        $query[$k] = $v;
      }
    }
    // 查询串与 path 一样要转义：返回值只落进 `href="…"` / `<option value="…">` 两个
    // 属性上下文，而**裸 `&` 会被 HTML 解析器当实体起头**——实测参数名 `copy_x`/`amp_x`/
    // `times_x` 会被浏览器解成 `©_x`/`&_x`/`×_x`，把相邻参数改名、把 token 的值污染
    // （配了 auth_token 时点一下语言就 403）。原先只有 report_path() 转义，是半截防护。
    $qs = http_build_query($query);
    return self::report_path() . ($qs === '' ? '' : '?' . htmlspecialchars($qs, ENT_QUOTES, 'UTF-8'));
  }

  /** 当前请求的路径，已转义（返回值只落进 href="…" 属性）。 */
  public static function report_path()
  {
    // uri 可能没有 path 部分（如 "?run=x"），parse_url 返回 false/null。
    $path = parse_url(Xhprof::getRequest()->uri(), PHP_URL_PATH) ?: '';
    return htmlspecialchars(rtrim($path, '/\\'), ENT_QUOTES, 'UTF-8');
  }


  /**
   * 过滤某些请求
   */
  public static function isIgnore()
  {
    $ignoreArr = Xhprof::$ignore_url_arr;
    if (!is_array($ignoreArr)) return true;
    //当前请求url
    $request_uri = Xhprof::getRequest()->uri();
    if (empty($request_uri)) return false;
    $request_uri = strtolower($request_uri);
    //是否需要忽略当前url
    foreach ($ignoreArr as $value) {
      if (strpos($request_uri, strtolower($value)) !== false) return false;
    }

    return true;
  }

  /**
   * 取客户端ip
   * @return mixed|string
   */
  public static function xhprof_get_ip()
  {
    return Xhprof::getRequest()->getRealIp();
  }

  /**
   * 获取请求详情
   * @param $run_id
   */
  public static function getRequestLog($run_id)
  {
    $key = Xhprof::$key_prefix . ":request_log:" . $run_id;
    $info = Xhprof::getCache()->get($key);
    if ($info) return json_decode($info, true);
    return false;
  }
}
