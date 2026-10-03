# 更新日志

本文件按 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 的组织方式（版本新→旧、
分组列出），但**段落由机器生成**：`tools/ci/release-notes.php` 从 conventional commits
分组渲染，`release.yml` 在发版时插入到下面的标记行下方 —— 同一份内容也是该次 Release
的正文。人工**不要**改既往条目的文本（机器只插入、不改写历史）；新版本由发版流程写入。

最初两节（v3.7.0 / v3.8.0）启用本机制之前就已发布（当时的 Release 正文只有 GitHub 自动
生成的一行 compare 链接），2026-10-04 由同一工具按同一规则回溯生成。
<!-- CHANGELOG:INSERT：新段落插在这一行下面（保持新→旧） -->

## v3.9.0 — 2026-10-03

### feat（新增）

- **contracts**：三腿（+Symfony 8/Laravel 13）、ThinkPHP 入环、WP 7.1、Joomla 5.4.9、断言数下限（f76dfd5）
  > - 新增第三条腿 legacy-symfony8（Symfony 8.1 + Laravel 13，PHP 8.5；CI 分腿给 php）；主腿升 Laravel 12.69
  > - 记录实测陷阱：Symfony 8 删除 Request::get()（Laravel 12 继承不可用，13 自覆写才成立）
  > - ThinkPHP 入环（topthink/framework v8.1.4，231 断言 0 skip，58 条冻结差异重签）；Hyperf 如实不入：能装但真跑要 ext-swoole（Context::set() 抛 Class "Swoole\Coroutine" not found）
  > - WordPress 真核 ^6.9→7.1.2（重跑无漂移，新增版本身份断言）；Joomla 5.2.2→5.4.9（在线核到 5.x 顶点、4.4 EOL 保留作对照，差异集按先跑红重签）
  > - 新增 per-case 断言数逐腿冻结下限（EXPECTED_ASSERTIONS，≥ 语义；防编辑型缩水）；GatesTest 负路径 + 4 数据集（tripwire 剥注释后扫描，堵住「注释满足字符串扫描」的假绿洞）
  > - 修复 Symfony.php 缺扩展守卫死码（$ext 被循环变量覆盖 → 缺 ext-xhprof 会 fatal 而非记 36 条 skip；A/B 实测，已装环境三腿输出逐字节不变）
  > - 三腿全绿：main 12 PASS/2 SKIP、6.4 腿 1/0/0、8 腿 2/0/0；SKIP 冻结常量未动
- **laravel**：CLI 与队列采样入口（xhprof:profile 自动注册）（e7b74bd）
  > - XhprofCli：深度计数的采样窗口（真机实测 SyncQueue 嵌套会再派一对事件，bool 会提前关外层窗口）
  > - XhprofQueueListener：JobProcessing/Processed/Failed/ExceptionOccurred 四事件按消息开停（JobFailed 覆盖 max-attempts 在 fire 前判失败的路径）
  > - XhprofProfileCommand（xhprof:profile {line}）：start → 真 Console\Application::call() → finally stop；null 守卫
  > - 命令经包内 XhprofServiceProvider::boot() 自动注册（用户零配置）；队列事件保持用户侧 4 行接线
  > - CliRequestAdapter：无 HTTP 请求的适配器（uri()=''，CLI 契约）
  > - tests：13 例 60 断言（真 Stubs Console\Application 上内层命令真跑、--flag 透传、退出码穿回、恰 1 条 cli: run）；5 变异全红（含桩变异证明非自说自话）
  > - 真机两腿（Laravel 12.69.3 / 13.34.0）arm8 各 38 检查 PASS：has() 真/假对照、自动注册路径、窗口关闭
  > - Illuminate\Console 双桩（Command/Application，收窄处逐条差异注释）；phpstan 归零、php80-smoke 三段 rc=0
- 报告页接线、协程上下文与导出扩展（同 URI 对比/导出入口与列表 JSON/状态条/requrl/火焰图图例与指标/数字本地化）（d9b724a）
  > - 同 URI 上次运行对比链接、动作栏 JSON/CSV 导出入口；?format=json 无 run 返回运行列表 JSON（单读、与 runsOverview 同源），symbol= 带值一律 400
  > - ?requrl= 接线（列表页 JS 预填可见搜索框 + 报告页「该 URI 的其他运行」生产者链接）
  > - 列表状态条（已存 N/上限/保留天数/最早最新，与 list_runs 单次 mget 合并）
  > - 火焰图三档图例 + ?flamemetric= 白名单切换（mu 含子调用说明）
  > - 按请求渲染态的协程上下文后端：Hyperf/workerman 自动侦测（coroutineContextClass()），验证环 Webman 卡以真服务器+真 TCP 交错钉住；htmlLang 收编
  > - 数字本地化（num.thousands/decimal 经 ICU 实测取值，HTML 侧；导出与列表页保持机器口径）
  > - B2 修复：N/A <= 0 恒假导致的 N/A 单元格染红；deny() 死分支清理；respond()/deny() 补 no-store；拒绝路径日志进程级节流
  > - a11y：th scope=col、横滚容器 tabindex、diff 回归侧 + 通道；响应的 N/A→中性
  > - 词表 168 键 ×13（新增 runs.aggregate/path.*/search.matches/flame.*/run.previous/flame.muInclusive/num.*/run.otherRuns/footer.credit），键序同 zh_CN 契约
  > 验收：全量 phpunit 1183 tests / 9862 assertions / 0 failure；generate --verify 48/48；check ×12 PASS
- 报告页接线、协程上下文与导出扩展（同 URI 对比/导出入口与列表 JSON/状态条/requrl/火焰图图例与指标/数字本地化）（e4c4ef7）
  > - 同 URI 上次运行对比链接、动作栏 JSON/CSV 导出入口；?format=json 无 run 返回运行列表 JSON（单读、与 runsOverview 同源），symbol= 带值一律 400
  > - ?requrl= 接线（列表页 JS 预填可见搜索框 + 报告页「该 URI 的其他运行」生产者链接）
  > - 列表状态条（已存 N/上限/保留天数/最早最新，与 list_runs 单次 mget 合并）
  > - 火焰图三档图例 + ?flamemetric= 白名单切换（mu 含子调用说明）
  > - 按请求渲染态的协程上下文后端：Hyperf/workerman 自动侦测（coroutineContextClass()），验证环 Webman 卡以真服务器+真 TCP 交错钉住；htmlLang 收编
  > - 数字本地化（num.thousands/decimal 经 ICU 实测取值，HTML 侧；导出与列表页保持机器口径）
  > - B2 修复：N/A <= 0 恒假导致的 N/A 单元格染红；deny() 死分支清理；respond()/deny() 补 no-store；拒绝路径日志进程级节流
  > - a11y：th scope=col、横滚容器 tabindex、diff 回归侧 + 通道；响应的 N/A→中性
  > - 词表 168 键 ×13（新增 runs.aggregate/path.*/search.matches/flame.*/run.previous/flame.muInclusive/num.*/run.otherRuns/footer.credit），键序同 zh_CN 契约
  > 验收：全量 phpunit 1183 tests / 9862 assertions / 0 failure；generate --verify 48/48；check ×12 PASS

### fix（修复）

- **test**：截图画布指纹钉 UTC——修「本机 +0800 / CI UTC」两个 sha 的环境分叉（9f2fb36）
  > CI 四条 PHP 矩阵格同点红（65462fe0 vs 01b8c961）：报告页把夹具时间戳经 date()
  > 渲染，而 date() 取 date.timezone。page() 内钉 UTC（用完还原），gate 与
  > shots.php 同源生效；26 张截图与清单按 UTC 重新生成。两种时区（本机默认与
  > -d date.timezone=UTC）下 gate 均绿。

### docs（文档）

- 13 份 README 文档批（版本表/Laravel 挂载与 CLI/环三腿/导出/限制表）+ 三图刷新与版权 + 27 张截图重拍（82d0118）
  > - 版本表：Laravel ^12|^13（11+ 用 bootstrap/app.php 的 withMiddleware）、Symfony ^8.0（PHP 8.4）；挂载说明改 slim skeleton 现实
  > - Laravel 节新增「CLI 与队列」（命令自动注册 + 队列四事件 + 手动包裹；三代码块全语种逐字）
  > - 环回填：三腿/10 家/ThinkPHP 入环/Hyperf 的 ext-swoole 运行前提/SKIP 2,0,0/断言数下限
  > - 新增「导出与机器消费」段；限制表：串扰行改写为按协程隔离 + 新增 Hyperf 采样串扰与 Octane 两行
  > - 三张图刷新（本次新增→扩展 8 个；采样判定序；鉴权短路）并加版权行 `© erik · https://erik.xyz`；design.meta.desc 的 six 残留 12 语种清齐
  > - 26 张本地化截图重拍（新 UI：导出按钮/状态条）+ 页脚版权；HTML 指纹清单重生成，LocaleScreenshotGate 4 例 254 断言绿
  > - 12 份产物重生成：--verify 48/48；check ×12 PASS
  > - 顺带修复：pt 的 user:password 标识符回正、zh/en 表标签格恢复、树注释两条旧版腿、设计图句 6→8（fr 首发发现）
  > - demo 示例页补版权行；LICENSE 版权行补 https://erik.xyz（随 feat 提交）

### test（测试）

- **docs**：文档与 i18n 闸批（截图指纹/字面 key/endonym/许可头/配置表/链接改写）（8a989da）
  > - LocaleScreenshotGateTest：26 页 HTML 指纹清单 + 回落扫描（含双向滤除：本语种自己含的汉字块不算回落；ja 的「最新」假阳实测修复）+ 静态量快照防跨文件泄漏
  > - I18nKeyReferenceTest：src/ 字面 i18n key 存在性（99 处字面引用，动态前缀白名单）；I18nParityTest：endonym 双表对齐 + zh↔zh_CN 映射入断言
  > - DocsIntegrityTest：NOTICE 声明的 Apache 头文件集合逐一含头（前 40 行）；ConfigParityTest：13 份 README 配置表键列双向对账（ar 的 LRM 解析伪差先剥再判）
  > - ReadmeLinkRewriteTest：生成器本语种前缀剥除（en/ko 角色对调）；GenerateVerifyTest 交付树普查扩到 6 件/语种
  > - LoadBombTest：落库路径 1 万函数炸弹（阈值 2.0s = 基线 49× 余量）；RenderBombTest 阈值按 xdebug 模式缩放（profile 下实测 61.3s/负载 139.7s 贴着 60s 假红）
  > - DrupalTest 顺序依赖修复（前置条件显式建立，不再依赖类进入时的环境）

### chore（杂务）

- **ci**：PHPStan 闸、php80-smoke 抽取、demo 端到端、随机序格、release notes 与 CHANGELOG 回写（8739de0）
  > - PHPStan 2.2.16（精确 pin）level 5 + 基线 31 条 + ci.yml 新 job；已知基线须在 src 静默后复确认（在制报错不入基线，避免 reportUnmatchedIgnoredErrors 反噬）
  > - 三块 8.0 冒烟逻辑（lint/函数黑名单/类加载）抽成 tools/ci/php80-smoke.php，ci 与 release 同源调用；类清单从盘上按 PSR-4 推导（修掉 ci/release 已发生的清单漂移）；扫描面并入 tools/i18n、tools/ci、tools/purge.php
  > - demo.yml（schedule 周一 + dispatch）：真 compose 构建起栈、业务请求、列表不自我采样、详情页 fib@1、资源 200、容器内扩展与软链校验；失败 dump 日志、不设 continue-on-error
  > - 8.4 测试格固定种子随机序（--random-order-seed=20261004）
  > - tools/ci/release-notes.php（conventional commits 分组、22 断言 selftest）+ CHANGELOG.md 回写（幂等、GITHUB_TOKEN 不重触发的坑入注释、compare 链接钉版本名）
  > - release.yml：非 main 守卫提前到流程首（同时挡住「tag 打错分支」）；notes selftest 与生成排在 tag 之前

**完整变更**：https://github.com/erikwang2013/xhprof-webman/compare/v3.8.0...v3.9.0

## v3.8.0 — 2026-10-03

### feat（新增）

- **demo**：docker compose 演示（原生 PHP 入口，一条命令看报告页）（7fba12d）
  > demo/ 起 app+redis 两个服务：Dockerfile 基于 php:8.3-cli-alpine 编译
  > xhprof 2.3.10 / phpredis 6.3.0 —— pecl 的 REST 接口已不可用（实测
  > "does not have REST info xml available"），改为直取 /get/ tarball 并把
  > 版本号写死在 URL（顺带钉住镜像）；pecl.php.net 会间歇性 reset，curl 带
  > --retry 5 --retry-all-errors。仓库根挂进 /app，path repository 软链工作树，
  > 改 src/ 下一个请求即生效；端口只发布到 127.0.0.1；composer.lock/vendor
  > 由 compose 每次重装（path 包随分支变，故用 update 而非 install）。
  > 
  > APK_MIRROR 旋钮（AR），实测本机默认源 61 KB/s、40 分钟装不完，换清华源
  > 后那条 RUN 80.9s；README 相应从「约 1-3 分钟」改为实测口径与「慢网
  > 10 分钟以上正常」的风险提示。
  > 
  > 真跑验收（Docker 26.1.5 + Compose v2.26.1）：示例页 200/0.24s
  > （fib(23)=28657，墙钟 242ms），/xhprof 列表与 run 详情（fib@0…fib@22）
  > 200，/xhprof-assets/css/xhprof.css 200，容器内 php -m 有 xhprof/redis，
  > 软链与宿主工作树同源。
- 采样、鉴权与报告页扩展（触发采样 / Basic+白名单 / 预算 / webhook / 导出 / 关键路径 / 火焰图）（3f5610e）
  > 按需触发采样：trigger_token 配好后带 X-Xhprof-Token 头的请求强制采样
  > （hash_equals 常量时间比较、只认请求头不认 query、不绕过 ignore_url_arr）。
  > 
  > 报告页鉴权与来源控制：HTTP Basic（auth_basic，与 auth_token 为**或**关系，
  > 失败 401 带 WWW-Authenticate；Apache+CGI 剥离 Authorization 的坑写进文档）；
  > IP 白名单逐字比对（不支持 CIDR/IPv6 规范化，非数组 fail closed），
  > trusted_proxies 作为**部署声明**（仅声明后才接受转发头 IP）；未配置鉴权时
  > 每次渲染记一条警告日志。
  > 
  > 采样策略：max_runs_per_minute 自适应预算（分钟桶计数、缓存故障 fail-open、
  > 触发采样不受限）；sample_cli（cli:<script> URI 合成）；webhook_url 慢请求
  > 通知（fire-and-forget、连接超时 200ms）。
  > 
  > 报告页：关键路径卡（path.title/path.empty，只走 main() 出发的调用边）、
  > 服务端渲染火焰图 FlameGraph（SplPriorityQueue 修剪、环安全树构建、
  > DEFAULT_MAX_FRAMES=60）、聚合选中入口（runs.aggregate）、函数搜索命中提示
  > （search.matches）、?format=json|csv 导出、symbol_lookup_url 源码链接。
  > 
  > 配置面：11 家框架 config/xhprof.php 与 Drupal typed config 同步新键，
  > 配置表 10→19 键；词表 12 语种各补 6 键（键序以 zh_CN 为契约）。
  > 
  > 验收：全量 phpunit 1115 tests / 9344 assertions / 0 failure；
  > generate.php --verify 48/48 逐字节相同；check.php ×12 全 PASS。

### docs（文档）

- 13 份 README 补演示行与结构树 demo 行、24 张本地化截图、12 份产物重生成（cd05e3b）
  > 快速开始三步之后加 docker 演示引用块（**纯文本反引号而非 Markdown 链接**：
  > 产物在 docs/i18n/<lang>/ 下，demo/ 相对链接无法解析，check.php 会判红；
  > `demo/`、命令、URL、`demo/README.md` 四处字面量各语种逐字保留）；
  > 项目结构树补 demo/ 行（# 落在字符列 34，与相邻行对齐）。
  > 
  > 截图：每语种 runs-list/run-report 改指 docs/i18n/<lang>/images/（源里写
  > 仓库相对全路径，生成器剥前缀成邻居）；en 源同批切换（此前一直指中文 UI
  > 截图）；zh 根图 docs/images/run-report.png 重拍（1280×1617，补上关键
  > 路径/火焰图两张新卡；渲染时显式 ?lang=zh_CN，否则 Chrome 的
  > Accept-Language 会把页面协商成英文）。
  > 
  > 顺带修复与回正：
  > - 「能机械证明的」表两行在 e1798a3 扩写描述时丢了行首标签格（GFM 渲染
  >   成单列错位），按 59493fd 原文恢复：适配器调用的方法真实存在 / 适配器
  >   语义正确（en 新撰对应标签）；
  > - pt 把反引号字面量 user:password 本地化成 usuário:senha，被
  >   I18nParityTest 的标识符闸抓到，已回正（其余 11 语种均逐字保留）。
  > 
  > 12 份产物重生成：generate.php --verify 48/48 逐字节相同；check.php ×12
  > 全 PASS（warning 与基线逐条相同，无新增）。

### chore（杂务）

- **tools,ci**：覆盖率双闸、purge 工具、i18n 路径规则与截图回落闸（71a9ce3）
  > tools/ci/coverage-diff.php：PR 差分覆盖率闸（只审新增/修改的 src/ 行，
  > 未覆盖逐行点名）+ 86% 崩落地板；--selftest 7 用例覆盖红/绿/跳过/无基线
  > 各路径。CI：8.5 进 lint/test 矩阵、concurrency 取消旧轮、vendor 缓存键
  > hashFiles('composer.json')（根锁 gitignore，旧键恒为常量）、契约环补
  > svg 资源类型、release 门禁与 dependabot。
  > 
  > tools/purge.php：Redis 清理工具（SCAN 游标不用 KEYS，默认只统计，
  > --yes 才删；参数与连接错误退出码 2）。
  > 
  > tools/i18n/generate.php：源里**本语种**的 docs/i18n/<lang>/… 在产物里
  > 剥前缀写成同目录邻居（产物根可被 I18N_OUT_ROOT 挪走，长路径依赖深度
  > 算术）；ReadmeLinkRewriteTest 用合成夹具 + en/ko 角色对调验证（单测一门
  > 分不开「键当前语种」与「写死某语种」）。
  > 
  > LocaleScreenshotGateTest：12 语种 ×2 页真渲染，扫「含汉字的 zh_CN 词表值」
  > 回落（带占位符的值先按占位符切块——flame.note 渲染后才成形，整值比会漏），
  > 红证用反射清空 ko 的 path.title。事故驱动：ar 截图曾早于其词表 9 秒拍摄、
  > 三处以中文回落；同因补 GenerateVerifyTest 的交付树普查（每语种 6 件）。

**完整变更**：https://github.com/erikwang2013/xhprof-webman/compare/v3.7.0...v3.8.0

## v3.7.0 — 2026-10-03

### feat（新增）

- **drupal**：Drupal 配置随 xhprof.sample_rate 补键（38d89ce）
  > install 的 xhprof.settings.yml 与 schema 的 xhprof.schema.yml 同步加 `sample_rate`
  > （float，默认 1.0）——两者由 DrupalTest 的键集一致性用例互钉，缺一个即红。
- 项目宠物图标、审计修复与两个新特性（diff 对比入口、按比例采样）（16ac521）
  > 宠物：报告页站点图标与品牌图标换成小火苗（src/html/pet.svg 与 docs/images/pet.svg 同源），
  > 表格排序图标换成宠物派生的三张 SVG——灰焰=待排序、红焰尖朝上=升序、尖朝下=降序；顺带修掉
  > 旧版三条指向从未随包发行的 PNG 的死链。两份 pet.svg 加了同步守卫；灰焰对比度提到 4.27:1
  > （过 WCAG 非文本 3:1，原来是 1.36:1）。
  > 
  > 审计修复（Core / 报告页 / 适配器 / 测试）：
  > - `?token[]=` 数组参数在鉴权前 400（原先触发 Array to string conversion，warning 升异常的
  >   宿主上 403 变 500）；缓存值损坏时 unserialize 不再漏 warning
  > - Display 里 13 处手拼 href 统一走已转义的 report_url()；runs 列表删掉写死的 all=1
  >   （恢复「前 100 + 显示全部」）；PHP 渲染表头补 aria-sort/th.sorted；搜索框 aria-label 与
  >   回车提交
  > - XhprofProfiler::stop() 幂等下沉 Core（12 家入口的守卫保留为第二道保险）；未配 auth_token
  >   时每次渲染记一条警告（默认行为不变，公网部署指引在 README 加重）
  > - WordPress 增配置注入面（wp-config 常量 XHPROF_WEBMAN_CONFIG + xhprof_webman_config
  >   过滤器 + redis 子选项）；Yii3 补 logger 注入点；Joomla shutdown 注册改每进程一次；
  >   Symfony 入缺扩展一致性矩阵（12 家全量）；静态量快照 canonical 补齐并迁移 10 个测试类；
  >   索引列表裁剪从「每次弹一条」（实测是永不收敛的不动点）改为一趟收敛
  > 
  > 两个新特性：
  > - runs 列表复选框列 +「对比选中」按钮，恰好选两条进 diff 视图（run1=较早、run2=较晚，
  >   按 create_time 取序，与列表排序无关；颜色语义是 run1→run2 的改善/回归）
  > - `xhprof.sample_rate` 按比例采样（默认 1.0=全采；非法值退全采；12 份配置 + Drupal
  >   install/schema）
  > 
  > 删除：xhprof_report.js 里 5 个死函数（tooltip 消费方早已移除；getTooltipAttributes 渲染的
  > data 属性按既有决定保留，并加反向断言防死代码回来）。

### fix（修复）

- **contracts**：Symfony 卡的资源类型表补 svg，地板 11→14（4d948c1）
  > 三张排序图标落在 `src/html/images/`（两层，正是该卡 glob 的深度），而此前的 .svg
  > （pet.svg）在 `src/html/` 顶层扫不到——这张卡第一次被 svg 触发并按设计报红（两条
  > 断言 ×3 个文件，两条腿同红，拦下了 tag）。按卡自己的指示把 `image/svg+xml` 显式登记
  > （无 charset：prepare() 只给 text/* 补）；资源地板随「当前应有几个」抬到 14。
  > 
  > 本地以 case-runner 单跑本卡复验：PASS，232 项断言全过、skips=0。

### docs（文档）

- 13 份 README 审计批次 + 12 份产物重生成 + 两张截图重拍（d1962ab）
  > README（根 zh 源 + tools/i18n/readme 的 12 份源，产物经 generate.php 重生成并过
  > check ×12 与 --verify 48/48）：
  > - 安装节补 xhprof 扩展的 PECL 装法；配置表加 `sample_rate` 行与按比例采样的说明段
  >   （`false`=不采、非法值退全采）；`auth_token` 行写清「默认不鉴权、接管先于宿主鉴权、
  >   公网/多租户必须设置、未设置每次渲染记一条警告」
  > - WordPress 节补配置注入用法（wp-config 常量 + xhprof_webman_config 过滤器）；首行兼容
  >   清单补「原生 PHP」；「未入环」改为三家并分述原因（原生 PHP 无第三方包可装，语义由
  >   NativeTest 的真超全局量 + 真 php -S 覆盖；ThinkPHP / Hyperf 有真包未装）
  > - 补「对比两次运行」用法段（复选框 + 对比选中，run1=早/run2=晚，颜色=run1→run2）与
  >   「数据清理配方」段（三种键名、`DEL <prefix>:run_id`、`--scan --pattern` 全清、
  >   索引无 TTL 是设计）；宠物说明句补排序图标与文件清单
  > - 新增/替换的 34 个 UI 词表键（diff 视图 31 个列头 + per call + N/A + source 链接）
  >   的 11 语种译文随本批写入各语言 lang 文件
  > 
  > 截图：docs/images/runs-list.png 与 run-report.png 用仓库真实渲染路径（Fakes 注入 +
  > Xhprof::index() + php -S + headless Chrome）重拍——左上角小火苗、表头焰形排序图标、
  > 词表化的 run 说明（「XHProf 运行（命名空间=…）」）、以及删 all=1 后的「前 100 + 显示
  > 全部」默认视图；旧的绿方块品牌与紫色三角图标由此退役。
  > 
  > 另：docs/code-review-report-20260802.md 顶部加状态横幅（范围数字以 2026-08-02 为准）。

**完整变更**：https://github.com/erikwang2013/xhprof-webman/compare/v3.6.0...v3.7.0
