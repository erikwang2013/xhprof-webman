# xhprof-webman 单元测试报告

日期：2026-08-27
PHP 版本：8.3（本地）/ CI 矩阵 8.0–8.4（lint）、8.2–8.4（测试）
PHPUnit：11.5.56

## 结论

**284 个测试，838 个断言，全部通过。**

| 模块 | 文件 | 测试数 | 断言数 |
|------|------|-------:|-------:|
| Core（Xhprof 门面 / 鉴权 / 报告页 / 静态资源） | tests/Unit/Core/ | 81 | 212 |
| Adapter（webman / Laravel / ThinkPHP / Hyperf 四框架接线） | tests/Unit/Adapter/ | 74 | 352 |
| Lib（XhprofLib / XHProfRunsDefault / XhprofDisplay） | tests/Unit/Lib/ | 129 | 269 |
| **合计** | | **284** | **838** |

代码覆盖率（pcov）：语句 87.3%（1417/1623），方法 92.5%（184/199）。

## 测试覆盖范围

- 门面类存在性、继承关系、静态配置默认值
- 5 个 Core 契约（Cache/Config/Logger/Request/Response）的适配器委托
- 4 框架 Middleware 全流程：enable=true 记录 / enable=false 不记录 / handler 抛异常时 finally 仍保存
- save_run 落库完整链路（run_id 生成、日志计数、request_log/xhprof_log 键写入、超限清理）
- 报告页渲染：单 run、多 run 聚合、diff 对比、方法详情、symbol 搜索
- 鉴权：auth_token 403、非法 run_id/source 400、数组参数拒绝
- 静态资源服务：路径穿越拦截（含编码绕过）、MIME 委托、缓存头
- 参数解析：uint/float/bool/string 的合法值、非法值、缺失默认值

## 修复的问题（共 10 处）

| # | 位置 | 问题 | 修复 |
|---|------|------|------|
| 1 | XhprofDisplay.php 全文件 | 字符串回调 `'XhprofDisplay::sort_cbk'` 等在命名空间化后解析到不存在的全局类，报告渲染必 TypeError | usort 改 `[self::class, 'sort_cbk']`；`print_td_num` 内对 `XhprofDisplay::` 前缀字符串归一化为 `self::class` |
| 2 | XHProfRunsDefault::get_run | 缓存缺失时对 null 调 `unserialize()` → TypeError | 非字符串/空串直接返回 false |
| 3 | Xhprof.php 4 个 getter | 非 nullable 返回类型，未绑定适配器时返回 null → TypeError，deny() 的 null 分支成死代码 | 改为 `?RequestInterface` 等 nullable，deny() 无 Response 时正确走 `http_response_code` 分支 |
| 4 | Xhprof::index() | `?run[]=x` 数组绕过 is_string 校验 → 后续 `explode()` TypeError | 非字符串非空 run 参数直接 400 拒绝 |
| 5 | XhprofLib::xhprof_aggregate_runs | 多 run 未传 wts 时 `count(null)` → TypeError | `is_array($wts) ? count($wts) : 0` |
| 6 | XhprofLib 参数助手 ×3 | 缺失参数时对 int/bool 默认值 `trim()` → TypeError | 非字符串值直接返回默认值，不再 trim |
| 7 | XhprofLib::xhprof_get_matching_functions | 裸 `main()` 键解析出 null parent → `stripos(null,...)` TypeError | null parent 跳过（main() 作为 child 仍可匹配） |
| 8 | XhprofDisplay::displayXHProfReport | get_run 返回 false 后渲染管线崩溃 | 单 run/聚合/diff 三处无数据时优雅降级返回导航页 |
| 9 | StaticController | `%2e%2e` 编码穿越 | 无需修改：realpath + 前缀校验对编码/解码路径均生效，防御纵深已覆盖 |
| 10 | 测试基建 | FakeCache/4 个框架 stub 的 `array_unshift($x ??= [], ...)` 在 PHP 8.3+ 崩溃 | 拆分为 `$x ??= []; array_unshift($x, ...)` |

## 遗留说明

- 第 9 项为「评审确认无风险」，未改动代码。
- 覆盖率缺口集中在报告页边缘渲染分支（diff 模式下的异常数据组合、超长 run_id 列表分页等），后续可按需补充。
- 报告产物：junit.xml、clover.xml、coverage/（HTML）随本目录提交。
