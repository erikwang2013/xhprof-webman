/*  Copyright (c) 2009 Facebook
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 */

/**
 * Helper javascript functions for the XHProf report pages.
 *
 * @author Kannan Muthukkaruppan
 */

// 父/子行的悬浮提示已于 2026-10 删除（消费端两个函数 + 仅它们使用的三个辅助：
// stringAbs/isNegative/addCommas）：触发器（onmouseover）早就移除，原版依赖的
// jquery.tooltip.js 不在加载列表里，没有任何调用点。
// 注意：这里刻意**不逐字写出**那两个被删的函数名 —— tests/Unit/Lib/XhprofDisplayTest.php
// 有一条断言按字面量扫本文件（"死代码不许回来"），写出来就会把它自己变成假红。
// 数据属性（get_tooltip_attributes 的 type/metric）与内联全局量保留，恢复该特性
// 时需要新写消费端与 13 语言文案。

$(document).ready(function() {
  // 整段包在 try/catch 里：jQuery 3 的 $(document).ready(fn) 内部走 Deferred，
  // 回调里抛出的异常会被**静默吞掉**（不触发 window.onerror，控制台也不报），
  // 表现就是「搜索框和分页没反应」而页面看着正常 —— 这种问题最费排查时间。
  // 至少把它变成一条 console.error。
  try {
    var cur_params = {};
    $.each(location.search.replace('?','').split('&'), function(i, x) {
      var y = x.split('='); cur_params[y[0]] = y[1];
    });
  
    var submitSearch = function(){
      cur_params['symbol'] = $("input.xhprof-search-input").val();
      location.search = '?' + jQuery.param(cur_params);
    };

    $("#funcSub").click(submitSearch);

    // 回车 = 点「搜索」。以前只绑了 click，键盘用户敲完回车页面毫无反应
    // （控件看起来能搜，实际什么都不发生）。用 keydown（keypress 已废弃），
    // 判 13 用 e.which —— jQuery 对 keydown 也会填它，旧 WebView 同样认。
    $("input.xhprof-search-input").keydown(function(e){
      if (e.which === 13) {
        e.preventDefault();
        submitSearch();
      }
    });

  
    // 界面文案由 PHP 按当前语言注入（window.xpI18n，见 XhprofDisplay::xhprof_include_js_css）。
    // 没有它（例如单独打开这段 JS）就什么都不传，DataTables 用它自带的英文默认值 ——
    // 比猜一个语言好。
    var dtI18n = (window.xpI18n && window.xpI18n.dataTable) || null;
  
    var table = $('#table_id_example').DataTable({
      language: dtI18n ? {
        "sProcessing": dtI18n.processing,
        "sLengthMenu": dtI18n.lengthMenu,
        "sZeroRecords": dtI18n.zeroRecords,
        "sInfo": dtI18n.info,
        "sInfoEmpty": dtI18n.infoEmpty,
        "sInfoFiltered": dtI18n.infoFiltered,
        "sInfoPostFix": "",
        "sSearch": dtI18n.search,
        "sUrl": "",
        "sEmptyTable": dtI18n.emptyTable,
        "sLoadingRecords": dtI18n.loadingRecords,
        // sInfoThousands 不传：页面上的数字是 PHP number_format 打的（英式 123,456），
      // 这里若按语言给分隔符，同一张页面上会出现两种写法。取 DataTables 的默认值。
        "oPaginate": {
          "sFirst": dtI18n.first,
          "sPrevious": dtI18n.previous,
          "sNext": dtI18n.next,
          "sLast": dtI18n.last
        },
        "oAria": {
          "sSortAscending": dtI18n.sortAsc,
          "sSortDescending": dtI18n.sortDesc
        }
      } : undefined,
      "paging":true,
      "pagingType":"full_numbers",
      "lengthMenu":[20,50,100,200],
      // 列索引与 PHP 渲染的 <th> 一一对应，**加列时两边必须一起改**：
      // 0 复选框 / 1 方法 / 2 请求地址 / 3 请求时间 / 4 耗时 / 5 内存 / 6 IP。
      // 默认按「请求时间」（索引 3）倒序 —— 索引没跟着复选框列右移的话，
      // 默认排序会落到别的列上（页面看着只是「排序怪怪的」，不报错）。
      "order": [[ 3, "desc" ]],
      "columns": [
        { "orderable": false},
        { "orderable": false},
        { "orderable": false},
        null,
        null,
        null,
        { "orderable": false},
      ]

    });
  
  
    // ---- 「对比选中」：勾选恰好两条 → run1/run2 对比页；>2 条 → 聚合报告 ----
    // 选择状态就存在复选框节点上（DataTables 翻页/排序复用同一批 TR 节点，
    // 节点被移出文档时 checked 属性照样有效），不另建一份 JS 状态，避免两边不同步。
    var $compareBtn = $('#xp-compare-btn');
    var $compareHint = $('.xp-compare-hint');
    var compareHintText = $compareHint.text();
    // 两个按钮文案：静态渲染的是「对比选中」（compareLabel）；选中 >2 条时换成
    // 聚合文案。它来自 window.xpI18n.runsAggregate —— 按钮的静态 HTML 在 Utils 层
    // 的 list_runs() 里，Display 层改不了；缺了注入（单独打开这段 JS）就退回对比文案。
    var compareLabel = $compareBtn.text();
    var aggregateLabel = (window.xpI18n && window.xpI18n.runsAggregate) || '';

    // 按**列表顺序**收集选中的行（rows({order:'index'}) 是原始数据顺序，不受当前列
    // 排序影响；同一秒入库的两条靠它分先后，见点击处的规则）。id 之外带上下单时间，
    // 先后判定用它、不用行位置。
    var selectedRuns = function () {
      var picks = [];
      table.rows({order: 'index'}).every(function () {
        var cb = this.node().querySelector('input.xp-run-cb');
        if (cb && cb.checked) {
          picks.push({ id: cb.value, t: parseInt(cb.getAttribute('data-create-time'), 10) || 0 });
        }
      });
      return picks;
    };

    var refreshCompare = function () {
      var n = selectedRuns().length;
      // ≥2 条都有动作（2 = 对比，>2 = 聚合），只有 0/1 条时按钮不可点
      $compareBtn.prop('disabled', n < 2);
      $compareBtn.text(n > 2 && aggregateLabel ? aggregateLabel : compareLabel);
      // 选够了收起提示；其余情况说明为什么按钮不可点（role=status，读屏会播报）
      $compareHint.text(n >= 2 ? '' : compareHintText);
    };

    $('#table_id_example').on('change', 'input.xp-run-cb', refreshCompare);

    // 表头全选：作用于**所有**行（含翻页后被移出文档的那些），不是只勾当前页
    $('#table_id_example').on('change', 'input.xp-run-all', function () {
      var on = this.checked;
      table.rows().every(function () {
        var cb = this.node().querySelector('input.xp-run-cb');
        if (cb) cb.checked = on;
      });
      refreshCompare();
    });

    $compareBtn.click(function () {
      var picks = selectedRuns();
      if (picks.length < 2) return;

      // >2 条走聚合报告（run=a,b,c → XhprofLib::xhprof_aggregate_runs）。
      // id 按 create_time 升序进 run=：聚合本身与顺序无关，这里排序只为链接稳定可读
      // （Array.sort 是稳定排序，同一秒入库的保持列表顺序）。
      if (picks.length > 2) {
        var ids = picks.slice().sort(function (a, b) { return a.t - b.t; })
                       .map(function (p) { return p.id; });
        cur_params['run'] = ids.join(',');
        delete cur_params['run1'];
        delete cur_params['run2'];
        cur_params['source'] = $compareBtn.attr('data-source') || 'xhprof_foo';
        location.search = '?' + jQuery.param(cur_params);
        return;
      }

      // 恰好两条：run1 必须是**时间早的那条**（基线）、run2 是之后：diff 的 delta = run2 − run1，
      // 负值（变快）才显示成绿色（XhprofDisplay::get_print_class 的注释）。列表新在前、
      // 还能被用户点列头重排，所以先后不能按行位置取，只能按每行上的 data-create-time 取。
      // 时间相等（同一秒入库）时，列表里靠后的那条反而更早（lPush 前插）→ 相等也要反转。
      if (picks[0].t >= picks[1].t) picks.reverse();
      // 与 submitSearch 同款：从当前查询串出发只改这三个参数，
      // token/lang 等原样保留（丢了 token 就是 403）
      // 清掉可能残留的 run=（否则 run 分支在派发时排在 run1/run2 前面，
      // 「恰好两条」会被当成聚合页）；与 >2 分支清 run1/run2 对称。
      delete cur_params['run'];
      cur_params['run1'] = picks[0].id;
      cur_params['run2'] = picks[1].id;
      cur_params['source'] = $compareBtn.attr('data-source') || 'xhprof_foo';
      location.search = '?' + jQuery.param(cur_params);
    });

    refreshCompare();

  } catch (e) {
    if (window.console && console.error) console.error('xhprof report init failed:', e);
  }
});
