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
 * Helper javascript functions for XHProf report tooltips.
 *
 * @author Kannan Muthukkaruppan
 */

// Take a string which is actually a number in comma separated format
// and return a string representing the absolute value of the number.
function stringAbs(x) {
  return x.replace("-", "");
}

// Takes a number in comma-separated string format, and
// returns a boolean to indicate if the number is negative
// or not.
function isNegative(x) {

  return (x.indexOf("-") == 0);

}

function addCommas(nStr)
{
  nStr += '';
  x = nStr.split('.');
  x1 = x[0];
  x2 = x.length > 1 ? '.' + x[1] : '';
  var rgx = /(\d+)(\d{3})/;
  while (rgx.test(x1)) {
    x1 = x1.replace(rgx, '$1' + ',' + '$2');
  }
  return x1 + x2;
}

// Mouseover tips for parent rows in parent/child report..
function ParentRowToolTip(cell, metric)
{
  var metric_val;
  var parent_metric_val;
  var parent_metric_pct_val;
  var col_index;
  var diff_text;

  row = cell.parentNode;
  tds = row.getElementsByTagName("td");

  parent_func    = tds[0].innerHTML;  // name

  if (diff_mode) {
    diff_text = " diff ";
  } else {
    diff_text = "";
  }

  s = '<center>';

  if (metric == "ct") {
    parent_ct      = tds[1].innerHTML;  // calls
    parent_ct_pct  = tds[2].innerHTML;

    func_ct = addCommas(func_ct);

    if (diff_mode) {
      s += 'There are ' + stringAbs(parent_ct) +
        (isNegative(parent_ct) ? ' fewer ' : ' more ') +
        ' calls to ' + func_name + ' from ' + parent_func + '<br>';

      text = " of diff in calls ";
    }  else {
      text = " of calls ";
    }

    s += parent_ct_pct + text + '(' + parent_ct + '/' + func_ct + ') to '
      + func_name + ' are from ' + parent_func + '<br>';
  } else {

    // help for other metrics such as wall time, user cpu time, memory usage
    col_index = metrics_col[metric];
    parent_metric_val     = tds[col_index].innerHTML;
    parent_metric_pct_val = tds[col_index+1].innerHTML;

    metric_val = addCommas(func_metrics[metric]);

    s += parent_metric_pct_val + '(' + parent_metric_val + '/' + metric_val
      + ') of ' + metrics_desc[metric] +
      (diff_mode ? ((isNegative(parent_metric_val) ?
                    " decrease" : " increase")) : "") +
      ' in ' + func_name + ' is due to calls from ' + parent_func + '<br>';
  }

  s += '</center>';

  return s;
}

// Mouseover tips for child rows in parent/child report..
function ChildRowToolTip(cell, metric)
{
  var metric_val;
  var child_metric_val;
  var child_metric_pct_val;
  var col_index;
  var diff_text;

  row = cell.parentNode;
  tds = row.getElementsByTagName("td");

  child_func   = tds[0].innerHTML;  // name

  if (diff_mode) {
    diff_text = " diff ";
  } else {
    diff_text = "";
  }

  s = '<center>';

  if (metric == "ct") {

    child_ct     = tds[1].innerHTML;  // calls
    child_ct_pct = tds[2].innerHTML;

    s += func_name + ' called ' + child_func + ' ' + stringAbs(child_ct) +
      (diff_mode ? (isNegative(child_ct) ? " fewer" : " more") : "" )
        + ' times.<br>';
    s += 'This accounts for ' + child_ct_pct + ' (' + child_ct
        + '/' + total_child_ct
        + ') of function calls made by '  + func_name + '.';

  } else {

    // help for other metrics such as wall time, user cpu time, memory usage
    col_index = metrics_col[metric];
    child_metric_val     = tds[col_index].innerHTML;
    child_metric_pct_val = tds[col_index+1].innerHTML;

    metric_val = addCommas(func_metrics[metric]);

    if (child_func.indexOf("Exclusive Metrics") != -1) {
      s += 'The exclusive ' + metrics_desc[metric] + diff_text
        + ' for ' + func_name
        + ' is ' + child_metric_val + " <br>";

      s += "which is " + child_metric_pct_val + " of the inclusive "
        + metrics_desc[metric]
        + diff_text + " for " + func_name + " (" + metric_val + ").";

    } else {

      s += child_func + ' when called from ' + func_name
        + ' takes ' + stringAbs(child_metric_val)
        + (diff_mode ? (isNegative(child_metric_val) ? " less" : " more") : "")
        + " of " + metrics_desc[metric] + " <br>";

      s += "which is " + child_metric_pct_val + " of the inclusive "
        + metrics_desc[metric]
        + diff_text + " for " + func_name + " (" + metric_val + ").";
    }
  }

  s += '</center>';

  return s;
}

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
  
    $("#funcSub").click(function(){
      cur_params['symbol'] = $("input.xhprof-search-input").val();
      location.search = '?' + jQuery.param(cur_params);
    });
  
  
    // 界面文案由 PHP 按当前语言注入（window.xpI18n，见 XhprofDisplay::xhprof_include_js_css）。
    // 没有它（例如单独打开这段 JS）就什么都不传，DataTables 用它自带的英文默认值 ——
    // 比猜一个语言好。
    var dtI18n = (window.xpI18n && window.xpI18n.dataTable) || null;
  
    $('#table_id_example').DataTable({
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
      "order": [[ 2, "desc" ]],
      "columns": [
        { "orderable": false},
        { "orderable": false},
        null,
        null,
        null,
        { "orderable": false},
      ]
  
    });
  
  
  } catch (e) {
    if (window.console && console.error) console.error('xhprof report init failed:', e);
  }
});
