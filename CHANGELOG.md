# 更新日志

本文件按 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 的组织方式（版本新→旧、
分组列出），但**段落由机器生成**：`tools/ci/release-notes.php` 从 conventional commits
分组渲染，`release.yml` 在发版时插入到下面的标记行下方 —— 同一份内容也是该次 Release
的正文。人工**不要**改既往条目的文本（机器只插入、不改写历史）；新版本由发版流程写入。

最初两节（v3.7.0 / v3.8.0）启用本机制之前就已发布（当时的 Release 正文只有 GitHub 自动
生成的一行 compare 链接），2026-10-04 由同一工具按同一规则回溯生成。
<!-- CHANGELOG:INSERT：新段落插在这一行下面（保持新→旧） -->

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
