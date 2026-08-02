# XHProf Webman 代码审查报告

**日期**: 2026-08-02
**审查范围**: 全项目 (`src/` 下 47 个 PHP 文件)
**审查维度**: 语法正确性、安全漏洞、代码Bug、性能问题、风格一致性、文档完整性

---

## 修复记录 (2026-08-02)

所有发现的 Bug 和代码质量问题已修复：

| # | 问题 | 文件 | 状态 |
|---|------|------|------|
| 1 | `print_td_num` 数字格式化失效 | `XhprofDisplay.php:502` | 已修复 |
| 2 | `xhprof_generate_mime_header` 缺少 break | `CallGraph.php:60` | 已修复 |
| 3 | `init_metrics` 中 `$metrics` 未初始化 | `XhprofLib.php:69` | 已修复 |
| 4 | `init_metrics` 多余参数传递 | `XhprofLib.php:70` | 已修复 |
| 5 | `xhprof_get_float_param` 无效校验 | `XhprofLib.php:508` | 已修复 |
| 6 | 废弃函数 `list_runs2()` | `XHProfRunsDefault.php:108` | 已移除 |
| 7 | Webman `ignore_url_arr` 默认值不一致 | `Webman/config/.../xhprof.php` | 已统一为 `["/xhprof"]` |

---

## 一、语法检查

所有 47 个 PHP 文件通过 `php -l` 语法检查，无语法错误。

| 检查项 | 结果 |
|--------|------|
| PHP lint (全部文件) | 通过 (0 errors) |
| Config 文件可解析性 | 4/4 通过 |

---

## 二、bug 发现

### 2.1 [严重] `print_td_num` 数字格式化完全失效

**文件**: `src/Core/XhprofLib/Display/XhprofDisplay.php:502-505`

```php
// 当前代码（Bug）
$num = call_user_func(function ($fmt_func) {
    return $fmt_func;
}, $num);

// 应改为
$num = call_user_func($fmt_func, $num);
```

**影响**: 报告页面中所有数值列（耗时、内存等）的格式化函数（如 `number_format`、`xhprof_count_format`）完全不生效。例如 `1234567` 不会被格式化为 `1,234,567`。该 Bug 影响 `print_td_num` 的所有调用路径，即整个性能报告表格的数值显示。

**严重程度**: 高 — 核心显示功能受损，所有报告页面的数值格式化均无效。

---

### 2.2 [中等] `xhprof_generate_mime_header` 缺少 break 导致 `ps` 类型失效

**文件**: `src/Core/XhprofLib/Utils/CallGraph.php:59-61`

```php
case 'ps':
    $mime = 'application/postscript';
// 缺少 break!
default:
    $mime = false;
```

**影响**: 当请求 `ps` (PostScript) 格式的 CallGraph 图片时，由于 fall-through，`$mime` 先被设为 `'application/postscript'`，紧接着被 `default` 分支覆盖为 `false`，最终不会设置 MIME header。

**严重程度**: 中 — CallGraph 功能 edge case，`ps` 格式在 `$xhprof_legal_image_types` 中已声明但实际无法使用。

---

### 2.3 [中等] `init_metrics` 中 `$metrics` 未初始化

**文件**: `src/Core/XhprofLib/Utils/XhprofLib.php:70-80`

```php
// $metrics 未在此函数开头初始化为数组
foreach ($possible_metrics as $metric => $desc) {
    if (!isset($xhprof_data["main()"][$metric])) continue;
    $metrics[] = $metric;  // 如果所有 metric 都不存在，$metrics 未定义
}
XhprofDisplay::$metrics = $metrics;  // 可能触发 E_WARNING
```

**影响**: 在极端情况下（xhprof 数据中没有匹配的 metric），会产生 PHP Warning 级别的错误日志，并导致 `XhprofDisplay::$metrics` 为 `null`，后续遍历 `$metrics` 的代码可能产生更多错误。

**严重程度**: 中 — 正常情况下 xhprof 总会返回 `wt` 指标，但在 `samples` 模式等特殊场景可能触发。

---

## 三、安全审查

### 3.1 XSS 防护 — 基本安全

- `XHProfRunsDefault::list_runs()` 中用户输入均经过 `htmlspecialchars()` 处理
- `XhprofDisplay::show_nav()` 中的导航链接使用 `url_params`，经过 `http_build_query` 构建
- `XhprofDisplay::print_flat_data()` 中 `$title` 经过 `htmlspecialchars(strip_tags(...))` 处理

### 3.2 路径遍历防护 — 安全

**文件**: `src/Core/StaticController.php:39-43`

- 包含 `..` 的路径在 `getPathFromRequest()` 中被提前拦截
- 使用 `realpath()` + `str_starts_with()` 双重校验

### 3.3 命令注入 — 安全

**文件**: `src/Core/XhprofLib/Utils/CallGraph.php:81`

`$type` 仅在 `$xhprof_legal_image_types` 定义的 key 集合中传递，用户无法直接控制。

### 3.4 Redis 数据序列化

- 数据通过 `serialize()` 存储，`unserialize()` 读取。数据来源是 Redis（自身写入），非用户直接输入，风险可控
- `json_encode`/`json_decode` 用于请求元数据（request_log），做法正确

---

## 四、代码质量问题

### 4.1 框架中间件行为不一致

**Webman 中间件** (`src/Webman/XhprofMiddleware.php`) 在扩展未安装时直接返回中文错误页面并终止请求：

```php
if (!$extension) {
    return response()->withBody('请安装xhprof扩展');
}
```

而 **Laravel/ThinkPHP/Hyperf 中间件**在相同情况下静默跳过（不启用 profiling，请求正常处理）。Webman 的做法更激进，会阻断所有请求。需确认这是否为刻意设计。

### 4.2 Webman 配置 `ignore_url_arr` 与其他框架不一致

| 框架 | `ignore_url_arr` 默认值 |
|------|------------------------|
| Webman | `["/test"]` |
| Laravel | `["/xhprof"]` |
| ThinkPHP | `["/xhprof"]` |
| Hyperf | `["/xhprof"]` |

Webman 的默认忽略路径是 `/test` 而非 `/xhprof`，与其余三个框架不一致。

### 4.3 三个中间件（Laravel/ThinkPHP/Hyperf）几乎完全重复

核心 profiling 启停逻辑可以提取为 Core 层共享方法，减少重复代码。

### 4.4 `xhprof_get_float_param` 中参数校验无效

```php
if (true) return (float)$val;
```

`if (true)` 恒为真，永远不会执行后面的 `xhprof_error(...)`。该函数的参数类型校验已被绕过。

### 4.5 废弃函数 `list_runs2()` 仍然存在

`XHProfRunsDefault::list_runs2()` 使用旧的 `<li>` 列表渲染方式，与当前 `list_runs()` 的表格渲染不一致，未被任何代码调用。

### 4.6 其他小问题

- `print_td_pct` 参数命名拼写 `numer`/`denom` 不完整
- `init_metrics` 调用 `xhprof_get_possible_metrics($xhprof_data)` 传了多余参数（函数签名无参数）

---

## 五、文档审查

### README.md / README.EN.md

- 中文版底部支付二维码已使用 HTML `<img>` 标签，130x130 尺寸，alt/title 清晰区分微信支付/支付宝
- 英文版为完整翻译，底部包含「Support Open Source」和支付二维码
- 文档结构清晰，覆盖四个框架的安装配置说明

### 文档不足

- 无 API 参考文档（Contract 接口说明）
- 无架构设计文档
- 无 CHANGELOG
- 无 CONTRIBUTING 指南

---

## 六、架构评价

### 优点

1. **适配器模式设计合理** — `Core/Contract` 定义 5 个接口，四个框架各自实现，Core 层不依赖具体框架
2. **Hyperf 协程支持** — 通过 Context 存储适配器引用，避免协程间数据污染
3. **静态资源防路径遍历** — 双重 `realpath` + `str_starts_with` 验证
4. **PSR-4 命名空间清晰** — `ErikWang2013\Xhprof\Core` → `ErikWang2013\Xhprof\<Framework>` 分层合理

### 可改进

1. Core 层 `XhprofDisplay` 使用过多静态属性（20+ 个），在非 Hyperf 的常驻内存框架下可能存在并发问题
2. `XhprofLib` 承担了过多职责：参数解析、数据聚合、数据计算、过滤逻辑均在其中

---

## 七、审查总结

| 维度 | 评级 | 说明 |
|------|------|------|
| 语法正确性 | A | 全部通过 PHP lint |
| 核心 Bug | C | 1 个高+2 个中优先级 Bug |
| 安全性 | A- | XSS/路径遍历/命令注入防护充分 |
| 代码一致性 | B | 中间件行为不一致，配置默认值不统一 |
| 文档 | B | README 中英文完整，缺少 CHANGELOG |
| 架构 | B+ | 适配器设计合理，Core 层静态属性偏多 |

### 建议处理优先级

1. **立即修复**: `print_td_num` 格式化 Bug（影响所有用户看到的数据显示）
2. **尽快修复**: `xhprof_generate_mime_header` 缺少 break
3. **尽快修复**: `init_metrics` 中 `$metrics` 未初始化
4. **建议**: 统一四个框架的 `ignore_url_arr` 默认值
5. **建议**: Webman 中间件扩展检查行为对齐其他框架（或文档说明差异）
6. **建议**: 清理废弃代码 `list_runs2()` 和 `xhprof_get_float_param` 无效校验
