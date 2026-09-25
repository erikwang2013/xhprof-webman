<?php

declare(strict_types=1);

/**
 * Joomla 契约卡（Joomla 4.4 / 5.x 系统插件）。
 *
 * 本卡的可验证面**全部**建立在真实包上：环的 composer 里既有四个独立小包
 * （joomla/event、joomla/input、joomla/registry、joomla/uri），也钉了两个**完整 CMS
 * 发布包**（JOOMLA_CMS_PACKAGES：5.2.2 与 4.4.14，官方 Full_Package zip，版本 + sha1）。
 * 两个 CMS 包故意不声明 autoload —— 环自己的进程绝不能自动加载到 Joomla\CMS\*（会与桩
 * 的同名声明在加载期对撞），只有本卡起的子进程显式 require 它们的 vendor/autoload.php。
 *
 * 覆盖手法（沿用 WordPress 卡确立的**扫描方向**）：
 *   1) 语法：src/Joomla/**、joomla/** 每个文件都要编译通过；
 *   2) 冻结清单 ↔ 源码扫描**双向相等**：源码里用到的每个框架成员都必须在清单里，
 *      清单里每条也必须在源码里真的用到。多一个 = 有人加了没核对过的调用；
 *      少一个 = 调用点被删了（或扫描器坏了，那也是坏消息）；
 *   3) L0 桩保真，两段：
 *      §4 可安装侧：与四个小包逐字段**精确相等**；
 *      §8 CMS 侧：与两个完整 CMS 包按「桩可更窄、不可更宽」比，差异集**按版本冻结**
 *         （多一条少一条都 FAIL），桩对 CMSApplicationInterface 的**合成**
 *         单独核对真身（兄弟接口与三个具体应用类上确有）；
 *   4) L2 语义，三处：
 *      §5 可安装侧：真实 Input / Registry / UriHelper / Dispatcher 驱动 src/Joomla/Adapter/**；
 *      §9 **真 CMS 端到端**：真 SiteApplication + 真事件类 + 真 Dispatcher + 本包入口类，
 *         跑报告页 / 鉴权失败 / 静态资源 / 常开采样四种请求，断言正文、响应头、真 exit()、
 *         以及 4.4 双次 onAfterRespond 下的幂等；
 *      §10 真 Log 通路：真 Joomla\CMS\Log\Log + 本包 LogAdapter（回调 logger 看级别与分类）；
 *   5) §6 采样（ext-xhprof 门控）、§11 provider 协议 + 安装形态、§12 分发点静态钉（版本相关）。
 *
 * 仍然不可验证的只剩 2 条（JOOMLA_SKIPS_DECLARED），理由在 §11 的 detail 里逐条量过。
 *
 * 环境相关：采样类断言要 ext-xhprof，报告页正文/头要 ext-redis。缺哪个就把对应的声明数
 * 计入 skips（不伪装成通过），run.php 会因 skips ≠ 冻结期望而红 —— 这是故意的：
 * 那条红是要人显式决定「给 contracts.yml 装扩展」还是「接受这批断言在 CI 上不验」。
 */

/**
 * 冻结：源码里用到的**框架成员**（`接收者:成员`，双向比对，见 §2）。
 *
 * 接收者取「`->` / `::` 左边那个词的末段」：`$this->input->get()` 里 `get` 的接收者是
 * `input`，`$server->get()` 的接收者是 `server`，`UriHelper::parse_url()` 是 `UriHelper`。
 *
 * 为什么连 `this:*`（本包自己的成员）也冻：`$this->` 能指到 CMSPlugin 的成员
 * （`this:getApplication` 就是），那是真实框架面；把这一族整个冻住，新增任何成员访问
 * 都要人过一次眼。`self::` 反过来不冻：它只能指向本包自己的成员，写错了 PHP 自己会报，
 * 契约环不必替 PHP 兜底。
 *
 * 扫描器的**上限**（写在这里，免得有人以为它比实际更强）：它认的是接收者**名字**，
 * 不是类型推断 —— `$x = $app->getInput(); $x->getInt(...)` 这种换名的接收者会漏。
 * 越过这条线要靠代码评审，本环不声称覆盖。
 */
const JOOMLA_MEMBERS = [
    // ---- 可安装侧：下面每个成员都在真实包里逐个核对过（JOOMLA_MEMBERS_VERIFIABLE）----
    'input:get',              // RequestAdapter::get()/all()：第三参 'raw'，绕开默认 cmd 过滤
    'input:getArray',         // RequestAdapter::all()：只取键名，值另用 'raw' 重读
    'input:getMethod',        // RequestAdapter::method()
    'input:server',           // Input::__get('server') → 包着 $_SERVER 的子 Input
    'server:exists',          // RequestAdapter::method()/header()：先 exists 再 get
    'server:get',             // RequestAdapter：HTTP_HOST / REQUEST_URI / REMOTE_ADDR / HTTPS
    'registry:get',           // ConfigAdapter::get()
    'Registry:__construct',   // ConfigAdapter::__construct()
    'UriHelper:parse_url',    // RequestAdapter::host()/uri()：无异常版本的 parse_url
    'Priority:MIN',           // getSubscribedEvents()：onAfterRespond
    'Priority:NORMAL',        // getSubscribedEvents()：onAfterInitialise

    // ---- CMS 侧：环里没有真实类，只能靠桩代跑（§3 把「装不上」钉成事实）----
    'app:getInput',           // 入口类：$app->getInput() → Input
    'app:setHeader',          // ResponseAdapter::send()
    'app:sendHeaders',        // ResponseAdapter::send()
    'app:close',              // 入口类：报告页/资源页收尾（真实 CMS 里是 exit()）
    'this:getApplication',    // 入口类：CMSPlugin::getApplication()
    'parent:__construct',     // 入口类：CMSPlugin::__construct()，4.4 与 5.x 签名不同
    'plugin:setApplication',  // services/provider.php：$plugin->setApplication(...)
    'Log:add',                // LogAdapter::log()
    'Log:ERROR',              // LogAdapter::log()：真实的 Log::ERROR 是位掩码 8
    'PluginHelper:getPlugin', // services/provider.php：读 #__extensions 的 params
    'Factory:getApplication', // services/provider.php：拿 CMS 应用

    // ---- 本包自己的成员（一并冻住：新增访问要过眼）----
    'this:cache', 'this:logger', 'this:input', 'this:registry', 'this:redis',
    'this:app', 'this:body', 'this:headers', 'this:status',
    'this:scheme', 'this:host', 'this:uri',
    'this:withBody', 'this:withStatus',
];

/**
 * 冻结：JOOMLA_MEMBERS 里**能在环里真核对**的那部分，分两个子集、走两段核对：
 *
 *  - 可安装侧：本文件的 L0 反射比对（joomla_member_diff，逐字段精确相等）覆盖
 *    joomla/event、joomla/input、joomla/registry、joomla/uri 四个真实包；
 *  - CMS 侧（JOOMLA_CMS_VERIFIABLE）：§8 对**两个真实 CMS 发布包**反射比对，
 *    方向「桩可更窄、不可更宽」。这一半在 2026-09-25 之前整段是 SKIP，现在两个包
 *    钉进了 composer.json（版本 + sha1）。
 *
 * `app:setHeader`/`app:sendHeaders` 也在下面：桩把这两个方法**合成**到了
 * CMSApplicationInterface 上，真实 CMS 里它们在 Joomla\Application\WebApplicationInterface
 * （§8 的合成项核对单独验「接口上没有 + 兄弟接口/具体应用类上确有 + 桩的型别只更窄」）。
 */
const JOOMLA_MEMBERS_VERIFIABLE = [
    'input:get', 'input:getArray', 'input:getMethod', 'input:server',
    'server:exists', 'server:get',
    'registry:get', 'Registry:__construct',
    'UriHelper:parse_url',
    'Priority:MIN', 'Priority:NORMAL',
];

/** 冻结：CMS 侧的 `接收者:成员`（§8 逐个反射核对；与 §2 的清单双向自检，防止这里漂） */
const JOOMLA_CMS_VERIFIABLE = [
    'app:getInput', 'app:setHeader', 'app:sendHeaders', 'app:close',
    'this:getApplication', 'parent:__construct', 'plugin:setApplication',
    'Log:add', 'Log:ERROR', 'PluginHelper:getPlugin', 'Factory:getApplication',
];

/**
 * 钉住的两个真实 CMS 发布包：`版本 => tools/contracts/vendor 下的相对路径`。
 *
 * 都是官方 **Full_Package** zip（不是 update/patch 包）：只有完整包自带可用的
 * `libraries/vendor/autoload.php`（GitHub 上 tag 的 tar 包里没有 libraries/vendor，
 * 实测 API 查 `libraries/vendor?ref=5.2.2` 404）。Joomla\CMS\* 不在 packagist 上，
 * 只能这么钉进 composer。
 *
 * 两个包在 composer.json 里**故意不声明 autoload**：环自己的进程不许自动加载到真实
 * Joomla\CMS\*（会与桩的同名声明在加载期对撞），只有本卡起的子进程显式 require。
 */
const JOOMLA_CMS_PACKAGES = [
    '5.2.2' => 'joomla/cms-full-package-5',
    '4.4.14' => 'joomla/cms-full-package-4',
];

/**
 * L0 反射规格：CMS 侧的类型 → **桩与真实包两侧都反射**的成员。
 *
 * 桩侧这些成员必须全部落在 tests/Stubs/Framework/Joomla.php 里（declaring 类即 FQN）；
 * 真实侧除 JOOMLA_CMS_REAL_MISSING 里那两条外也必须都在。
 */
const JOOMLA_CMS_SHARED_SPEC = [
    'Joomla\CMS\Plugin\CMSPlugin' => ['__construct', 'setApplication', 'getApplication'],
    'Joomla\CMS\Application\CMSApplicationInterface' => ['getInput', 'setHeader', 'sendHeaders', 'close'],
    'Joomla\CMS\Log\Log' => ['add'],
];

/**
 * 只在**真实包侧**核对存在的成员（桩里没有这些类：PluginHelper/Factory 只出现在
 * joomla/services/provider.php，本卡不加载那个文件 —— provider 协议由 §11 单独核对）。
 *
 * WebApplicationInterface/ApplicationInterface 与三个具体应用类是**合成项的出处**：
 * 桩断言 CMSApplicationInterface 有 setHeader/sendHeaders/close，就得证明真实 CMS 的
 * 应用对象上真的有（否则桩的合成是幻觉，单测会替生产代码背书一个不存在的能力）。
 */
const JOOMLA_CMS_REAL_SPEC = [
    'Joomla\CMS\Plugin\PluginHelper' => ['getPlugin', 'importPlugin', 'isEnabled'],
    'Joomla\CMS\Factory' => ['getApplication'],
    'Joomla\Application\WebApplicationInterface' => ['setHeader', 'sendHeaders'],
    'Joomla\Application\ApplicationInterface' => ['close'],
    'Joomla\CMS\Application\SiteApplication' => ['setHeader', 'sendHeaders', 'close', 'getInput'],
    'Joomla\CMS\Application\AdministratorApplication' => ['setHeader', 'sendHeaders', 'close', 'getInput'],
    'Joomla\CMS\Application\ApiApplication' => ['setHeader', 'sendHeaders', 'close', 'getInput'],
];

/** 冻结：桩合成的两个方法 → 真身所在的接口（§8 逐个核对「接口上没有、兄弟接口上有」） */
const JOOMLA_CMS_SYNTHESIS = [
    'setHeader' => 'Joomla\Application\WebApplicationInterface',
    'sendHeaders' => 'Joomla\Application\WebApplicationInterface',
];

/**
 * 冻结：合成项真身的**参数个数**（实测 `setHeader($name, $value, $replace = false)` /
 * `sendHeaders()`，两个版本同形）。无型别不等于「签名是空的」：0 参数的方法算出来的
 * 签名只有返回那一格，「全都无型别」就退化成恒真 —— 参数个数必须单独钉住。
 */
const JOOMLA_CMS_SYNTHESIS_ARITY = ['setHeader' => 3, 'sendHeaders' => 0];

/**
 * 冻结：真实侧 dump 里**应当且只应当缺失**的成员（= 合成项，由 §8 单独核对）。
 * 精确相等：多一条（真实包少了我们断言的能力）少一条（合成项消失，桩该改成不合成）都 FAIL。
 */
const JOOMLA_CMS_REAL_MISSING = [
    'Joomla\CMS\Application\CMSApplicationInterface::setHeader',
    'Joomla\CMS\Application\CMSApplicationInterface::sendHeaders',
];

/**
 * 冻结：每个版本**允许且必须出现**的差异（精确相等 —— 多一条是桩漂了/包换了，
 * 少一条是这条差异的依据消失了，两种都要人重看一遍）。差异串由 joomla_cms_compare() 生成。
 *
 * 5.2.2 是 0 条：桩的 CMS 侧声明与 5.2.2 **逐字段一致**（含 CMSPlugin::__construct）。
 * 4.4.14 只差 1 条：4.4 的 `__construct(&$subject, $config = [])` 第一个参数**按引用**
 * 且无型别，桩是 `DispatcherInterface $dispatcher`（= 5.x 的形状，本包的目标版本）。
 * 桩无法建模「调用点必须传变量」这件事，代价由 joomla/services/provider.php「先用变量接住
 * 容器结果再传」的写法兜住 —— §9 在 4.4.14 上真的 new 了一次入口类，证明这条兜得住。
 */
const JOOMLA_CMS_EXPECTED_DIFFS = [
    '5.2.2' => [],
    '4.4.14' => ['Joomla\CMS\Plugin\CMSPlugin::__construct.p0.byRef'],
];

/**
 * 冻结：CMS 侧成员的声明出处（FQN → 允许的 declaring 文件名）。防止反射到别处的同名声明：
 * 桩侧必须落在 Joomla.php 且 declaring 类就是 FQN；真实侧必须是 CMS 自己的那几个文件。
 */
const JOOMLA_CMS_DECLARING = [
    'Joomla\CMS\Plugin\CMSPlugin' => ['CMSPlugin.php'],
    'Joomla\CMS\Application\CMSApplicationInterface' => ['CMSApplicationInterface.php', 'ApplicationInterface.php'],
    'Joomla\CMS\Log\Log' => ['Log.php'],
];

/**
 * L0 反射规格：可安装侧的类型 → 我们用到（或声明里必须成立）的方法。
 *
 * `Priority` 的方法是空的、常量全比：常量值才是它的内容（MIN=-3 / NORMAL=0）。
 */
const JOOMLA_DUMP_SPEC = [
    'Joomla\Event\SubscriberInterface' => ['getSubscribedEvents'],
    'Joomla\Event\EventInterface' => ['getArgument', 'getName', 'isStopped', 'stopPropagation'],
    'Joomla\Event\Priority' => [],
    'Joomla\Event\DispatcherInterface' => [
        'dispatch', 'addListener', 'addSubscriber', 'removeSubscriber', 'hasListener',
        'getListeners', 'removeListener', 'clearListeners', 'countListeners',
    ],
    'Joomla\Input\Input' => ['__construct', '__get', 'get', 'getArray', 'exists', 'getMethod'],
    'Joomla\Registry\Registry' => ['__construct', 'get'],
    'Joomla\Uri\UriHelper' => ['parse_url'],
];

/**
 * 冻结：CMS 侧的触碰点。§3 起两个子进程，两个方向都要成立：
 *
 *  (A) 环自己的 autoloader（tools/contracts/vendor/autoload.php）**必须看不见**这些类。
 *      两个 CMS 包在 composer.json 里故意不声明 autoload；哪天有人加上，环进程就会加载
 *      真实的 Joomla\CMS\*，与 tests/Stubs/Framework/Joomla.php 的同名声明对撞 —— 桩里那些
 *      声明都在 class_exists 守卫里，真实类会**先赢**，单测于是悄悄改测真实包（这正是要防的）。
 *  (B) 每个钉住的包**自己的** libraries/vendor/autoload.php 必须看得见，且
 *      Joomla\CMS\Version 必须报出钉住的那个版本 —— 钉的是这两个包，不是「某个能装的 CMS」。
 */
const JOOMLA_CMS_TOUCHPOINTS = [
    'Joomla\CMS\Plugin\CMSPlugin',
    'Joomla\CMS\Application\CMSApplicationInterface',
    'Joomla\CMS\Log\Log',
    'Joomla\CMS\Plugin\PluginHelper',
    'Joomla\CMS\Factory',
];

/**
 * 单独一条：**两个版本都期望它不存在**。入口类用字面量事件名而不是
 * `Joomla\CMS\Event\Application\ApplicationEvents::*`，前提就是这个类不存在
 * （写常量会让插件在装载时「类找不到」直接致命）。哪天 Joomla 真加了它，这里红，
 * 提醒人重新评估「字面量 vs 常量」的取舍。
 */
const JOOMLA_CMS_ABSENT = 'Joomla\CMS\Event\Application\ApplicationEvents';

/** 端到端跑四种请求：报告页 / 鉴权失败 / 静态资源 / 常开采样（§9） */
const JOOMLA_E2E_MODES = ['report', 'deny', 'assets', 'sample'];

/**
 * 冻结：两个版本派发 onAfterInitialise 时用的事件对象类（§9 观测、§12 源码钉，两处呼应）。
 * 5.x 用有型别的 AfterInitialiseEvent；4.4 用通用的 Joomla\Event\Event。入口类只管监听
 * **名字**，所以两者都能收 —— 但这条差异必须有人钉住，否则「4.4 也能跑」就成了没依据的说法。
 */
const JOOMLA_CMS_EVENT_CLASS = [
    '5.2.2' => 'Joomla\CMS\Event\Application\AfterInitialiseEvent',
    '4.4.14' => 'Joomla\Event\Event',
];

/**
 * 冻结：**仍然不可验证**的子项数。由 5 降到 2（2026-09-25 解冻三条）：
 *   [SKIP A] `#__extensions.params` 的真实读取路径（PluginHelper::getPlugin → bootPlugin）
 *   [SKIP B] 安装器形态（namespacemap 被写过、bootPlugin 找得到类）
 * 两条的理由都在**干净子进程里量到了抛点**（§11 的容器协议 + §11c 的 SQLite 近路与
 * 安装后目录形态下的 bootPlugin），不是散文；分别对应原先的 SKIP 3 与 SKIP 4。
 * 原 SKIP 1（CMS 类语义）、SKIP 2（裸名分发点）、SKIP 5（4.4 vs 5.x 构造器差异）
 * 已解冻成 §8/§9/§12 的真断言。
 */
const JOOMLA_SKIPS_DECLARED = 2;

/**
 * 冻结：需要 ext-xhprof 的断言数（缺扩展时按这个数计入 skips，末尾自检漂移）。
 * §6 的 4 条（本进程采样通路）+ §9 sample 模式的 6 条（2 版本 × 3：恰好落一条/有 main()/覆盖标记）。
 */
const JOOMLA_EXT_CHECKS_DECLARED = 10;

/**
 * 冻结：需要 ext-redis 的断言数（同上）。报告页正文与它的头在缺扩展时走的是本包自己的
 * 500 拒绝页（src/Core/Xhprof.php:121 的硬守卫，无注入点），环绕不过去也不该绕；
 * 缺扩展时这部分计入 skips，其余（退出/不落库/派发/监听器）在两种环境下都真跑。
 */
const JOOMLA_REDIS_CHECKS_DECLARED = 8;

/** 采样标记：证明落库的数据覆盖到本次请求里真的跑过的代码 */
if (!function_exists('xhprof_joomla_marker')) {
    function xhprof_joomla_marker(): int
    {
        return 42;
    }
}

return static function (): array {
    $repoRoot = contracts_repo_root();
    $autoload = contracts_dir() . '/vendor/autoload.php';
    $stub = $repoRoot . '/tests/Stubs/Framework/Joomla.php';

    if (!is_file($autoload)) {
        // 缺包必须 FAIL 而不是 SKIP：装不上真实包就证明不了任何事，SKIP 会让 CI 假绿。
        return [
            'status' => 'FAIL',
            'detail' => 'tools/contracts/vendor 未安装，先跑 composer install -d tools/contracts',
            'skips' => 0,
        ];
    }
    if (!is_file($stub)) {
        return ['status' => 'FAIL', 'detail' => "桩文件不存在：{$stub}", 'skips' => 0];
    }

    // ================= §1 语法 + 框架成员扫描 =================

    $sources = array_merge(
        glob($repoRoot . '/src/Joomla/*.php') ?: [],
        glob($repoRoot . '/src/Joomla/*/*.php') ?: [],
        glob($repoRoot . '/src/Joomla/*/*/*.php') ?: [],
        glob($repoRoot . '/joomla/*.php') ?: [],
        glob($repoRoot . '/joomla/*/*.php') ?: [],
        glob($repoRoot . '/joomla/*/*/*.php') ?: []
    );
    sort($sources);

    if ($sources === []) {
        return ['status' => 'FAIL', 'detail' => '扫不到 src/Joomla 与 joomla/ 下的源文件（路径写错了？）', 'skips' => 0];
    }

    // 接收者：框架对象变量 + 类名（末段）+ parent。self 故意不在列表里（见 JOOMLA_MEMBERS 注释）。
    $receivers = ['input', 'server', 'app', 'registry', 'plugin', 'this'];
    $staticReceivers = ['Registry', 'UriHelper', 'Priority', 'Log', 'CMSPlugin', 'PluginHelper', 'Factory', 'parent'];

    $used = [];
    $phpFiles = 0;
    foreach ($sources as $file) {
        $code = (string) file_get_contents($file);
        $rel = str_replace($repoRoot . '/', '', $file);

        try {
            token_get_all($code, TOKEN_PARSE);   // 语法：解析不了就抛 ParseError
        } catch (\ParseError $e) {
            return ['status' => 'FAIL', 'detail' => "{$rel} 语法错误：" . $e->getMessage(), 'skips' => 0];
        }
        if (str_ends_with($rel, '.php')) {
            $phpFiles++;
        }

        foreach (joomla_used_members($code, $rel, $receivers, $staticReceivers) as $member => $where) {
            $used[$member] = $where;
        }
    }

    ksort($used);
    $expected = JOOMLA_MEMBERS;
    sort($expected);
    $actual = array_keys($used);

    if ($actual !== $expected) {
        $unknown = array_values(array_diff($actual, $expected));
        $stale = array_values(array_diff($expected, $actual));
        $detail = [];
        if ($unknown !== []) {
            $detail[] = '扫到冻结清单外的框架成员：'
                . implode('、', array_map(static fn (string $m): string => "{$m}@{$used[$m]}", $unknown))
                . '（新增调用请先在真实包里核对签名，再写进 JOOMLA_MEMBERS；若只是笔误，'
                . '它会在生产上 Call to undefined method）';
        }
        if ($stale !== []) {
            $detail[] = '冻结清单里这些成员在源码里已不存在：' . implode('、', $stale)
                . '（调用点被删了？还是扫描器坏了？两者都要人看一眼）';
        }

        return ['status' => 'FAIL', 'detail' => implode('；', $detail), 'skips' => 0];
    }

    // ================= §2 可安装侧的成员都必须真的在真实包里 =================
    // 扫描只证明「我用到了这些成员」，不证明「真实包里有这些成员」——那是 L1，
    // 由 §4 的反射比对负责（JOOMLA_MEMBERS_VERIFIABLE 就是两者的交集）。

    $missingVerifiable = [];
    foreach (JOOMLA_MEMBERS_VERIFIABLE as $member) {
        if (!isset($used[$member])) {
            $missingVerifiable[] = $member;
        }
    }
    if ($missingVerifiable !== []) {
        return [
            'status' => 'FAIL',
            'detail' => 'JOOMLA_MEMBERS_VERIFIABLE 里这些成员不在扫描结果里（清单漂了）：'
                . implode('、', $missingVerifiable),
            'skips' => 0,
        ];
    }

    // ================= §3 CMS 侧的两条前提（两个方向，各起子进程） =================
    // 两个方向都必须成立，缺一条下面的 §8/§9 就是在验别的东西：
    //   (A) 环自己的 autoloader **看不见** CMS —— 看得见就意味着真实类会与桩对撞并先赢，
    //       单测会悄悄改测真实包（这正是这张卡最该防的事）；
    //   (B) 每个**钉住的包自己**必须看得见，且版本号对得上 —— 钉的是这两个包，不是「某个 CMS」。

    // ---- (A) 环的 autoloader：CMS 全部看不见，正对照必须看得见 ----
    // 探针要问的 FQN **直接由 JOOMLA_CMS_TOUCHPOINTS 生成**，不在这儿手抄第二份：
    // 手抄副本会在清单改人名时静默漂移。回退验证 C5 就是这么抓出来的 —— 清单里把一个
    // 触碰点换成真装得上的类，探针却仍去问原来那些名字，于是照样 PASS，前提成了摆设。
    // 'control' 是正对照：探针必须看得见一个**确实装得上**的类（真实包里的 Priority）。
    // 否则「CMS 全部 false」分不清是「CMS 不该被看见」还是「autoload 压根没加载成功」——
    // 后者会让整条前提变成假象（C10 就是把这个控制点改坏来验它真会红）。
    $probe = 'require ' . var_export($autoload, true) . ";\n"
        . "echo json_encode([\n"
        . "    'cms' => array_map(static fn (string \$f): bool => class_exists(\$f) || interface_exists(\$f), "
        . var_export(JOOMLA_CMS_TOUCHPOINTS, true) . "),\n"
        . "    'control' => class_exists('Joomla\\Event\\Priority'),\n"
        . '], JSON_UNESCAPED_SLASHES), "\n";';
    $absent = contracts_run_php(['-r', $probe]);
    $absentDecoded = json_decode(trim($absent['stdout']), true);
    if (!is_array($absentDecoded) || !isset($absentDecoded['cms'])
        || !is_array($absentDecoded['cms']) || count($absentDecoded['cms']) !== count(JOOMLA_CMS_TOUCHPOINTS)) {
        return [
            'status' => 'FAIL',
            'detail' => '隔离探针没有给出预期 JSON（exit ' . $absent['code'] . '）：'
                . trim($absent['stderr'] !== '' ? $absent['stderr'] : $absent['stdout']),
            'skips' => 0,
        ];
    }
    if (($absentDecoded['control'] ?? false) !== true) {
        return [
            'status' => 'FAIL',
            'detail' => '隔离探针的**正对照**失败：连真实包里的 Joomla\Event\Priority 都看不见，'
                . '说明探针没真加载到 vendor/autoload.php —— 这种状态下「CMS 全部 class_exists=false」'
                . '是假象而不是事实，先修探针',
            'skips' => 0,
        ];
    }
    $visibleToRing = [];
    foreach (JOOMLA_CMS_TOUCHPOINTS as $i => $fqn) {
        if ($absentDecoded['cms'][$i] === true) {
            $visibleToRing[] = $fqn;
        }
    }
    if ($visibleToRing !== []) {
        return [
            'status' => 'FAIL',
            'detail' => '环自己的 autoloader 现在看得见这些 CMS 类：' . implode('、', $visibleToRing)
                . ' —— 两个 CMS 发布包在 composer.json 里故意不声明 autoload。加上 autoload 后，'
                . '环进程里的真实 Joomla\CMS\* 会与 tests/Stubs/Framework/Joomla.php 的同名声明对撞'
                . '（桩的声明都在 class_exists 守卫里，真实类先赢），单测会**静默**改测真实包。'
                . '要么去掉那个 autoload，要么把桩改成显式只在本包测试里生效，别在两者之间含糊',
            'skips' => 0,
        ];
    }

    // ---- (B) 每个钉住的包自己：触碰点都在（ApplicationEvents 除外），版本号 == 钉住的版本 ----
    $cmsPackagesSeen = [];
    foreach (JOOMLA_CMS_PACKAGES as $pinnedVersion => $package) {
        $packageRoot = contracts_dir() . '/vendor/' . $package;
        $pkgAutoload = $packageRoot . '/libraries/vendor/autoload.php';
        if (!is_file($pkgAutoload)) {
            return [
                'status' => 'FAIL',
                'detail' => "{$package}（{$pinnedVersion}）没装：找不到 {$pkgAutoload}。"
                    . '先跑 composer install -d tools/contracts（不要加 --ignore-platform-reqs）',
                'skips' => 0,
            ];
        }

        $cmsProbe = contracts_run_php([
            '-r', joomla_cms_probe_script(),
            $pkgAutoload,
            json_encode(JOOMLA_CMS_TOUCHPOINTS, JSON_THROW_ON_ERROR),
            JOOMLA_CMS_ABSENT,
        ]);
        $cmsDecoded = json_decode(trim($cmsProbe['stdout']), true);
        if (!is_array($cmsDecoded) || !isset($cmsDecoded['present'], $cmsDecoded['absent'], $cmsDecoded['version'])) {
            // CMS 类文件里有 `defined('_JEXEC') or die;`：探针若忘了先定义常量，子进程会
            // 静默 die（exit 0 + 空 stdout）。这里必须**响亮**地红，不能当成「类不存在」。
            return [
                'status' => 'FAIL',
                'detail' => "{$package} 的可见性探针没有给出预期 JSON（exit {$cmsProbe['code']}）："
                    . trim($cmsProbe['stderr'] !== '' ? $cmsProbe['stderr'] : $cmsProbe['stdout'])
                    . ' —— 空 stdout 通常是探针没在 require 之前定义 _JEXEC/JPATH_*，CMS 的文件被 '
                    . "`defined('_JEXEC') or die` 提前终止了",
                'skips' => 0,
            ];
        }
        if (($cmsDecoded['package_dir'] ?? null) !== $package) {
            return [
                'status' => 'FAIL',
                'detail' => "{$package} 探针实际加载的是 {$cmsDecoded['package_dir']}，"
                    . '包目录与清单对不上（版本标签被换错了？）',
                'skips' => 0,
            ];
        }
        if ($cmsDecoded['version'] !== $pinnedVersion) {
            return [
                'status' => 'FAIL',
                'detail' => "{$package} 报出的 Joomla 版本是 {$cmsDecoded['version']}，钉的是 {$pinnedVersion}"
                    . ' —— 「按版本冻结的差异集」失去依据，先对齐 composer.json 与本文件',
                'skips' => 0,
            ];
        }
        $missingTouchpoints = [];
        foreach ($cmsDecoded['present'] as $fqn => $present) {
            if ($present !== true) {
                $missingTouchpoints[] = $fqn;
            }
        }
        if ($missingTouchpoints !== []) {
            return [
                'status' => 'FAIL',
                'detail' => "{$package} 里看不见这些类：" . implode('、', $missingTouchpoints)
                    . '（CMS 改命名空间了？还是包被换成了不带这些文件的版本？）',
                'skips' => 0,
            ];
        }
        if ($cmsDecoded['absent'] !== false) {
            return [
                'status' => 'FAIL',
                'detail' => "{$package} 里居然有 " . JOOMLA_CMS_ABSENT
                    . ' —— 入口类「事件名用字面量」的前提失效（那条注释直接引用了它不存在的实测），'
                    . '请重新评估「字面量 vs 常量」并更新 src/Joomla/Extension/Xhprof.php 的注释',
                'skips' => 0,
            ];
        }
        // 版本相关的结构差异（实测）：两个版本的 libraries/bootstrap.php 都用一个常量拼出
        // loader.php 的路径，但 4.4 用的是 `JPATH_PLATFORM . '/loader.php'`（该常量在 4.4 的
        // bootstrap 里默认成 __DIR__ = libraries/），5.x 换成了 `JPATH_LIBRARIES . '/loader.php'`
        // —— 5.x 的 bootstrap 只定义、不拼接 JPATH_PLATFORM。这条钉的是「两个包确实是两个世代」，
        // 也防版本标签被换错（把两个包换成同一份内容，这条立刻红）。
        $platformAsPath = $cmsDecoded['bootstrap_uses_platform_as_path'] ?? null;
        if ($platformAsPath !== ($pinnedVersion === '4.4.14')) {
            return [
                'status' => 'FAIL',
                'detail' => "{$package}：bootstrap.php 里 `JPATH_PLATFORM . '/…'` 这种拼法 = "
                    . var_export($platformAsPath, true) . "，与 {$pinnedVersion} 的预期不符"
                    . '（4.4 拼 JPATH_PLATFORM、5.x 拼 JPATH_LIBRARIES，实测）—— 包或版本标签对不上',
                'skips' => 0,
            ];
        }
        $cmsPackagesSeen[$pinnedVersion] = $cmsDecoded + ['package' => $package];
    }

    // ================= §4 L0：桩 vs 真实包（两个子进程） =================
    // 我们的桩与真实包声明同名类，同进程加载 = 加载期 fatal。本进程两个都不加载，
    // 只起子进程 + 比 JSON。dump 逻辑是**同一段代码**喂两边，差异报告才分得清
    // 「签名不同」与「dump 方式不同」。

    $dumpScript = joomla_dump_script();
    $specJson = json_encode(JOOMLA_DUMP_SPEC, JSON_THROW_ON_ERROR);

    $ours = contracts_run_php(['-r', 'require ' . var_export($stub, true) . ";\n" . $dumpScript, $specJson]);
    $real = contracts_run_php(['-r', 'require ' . var_export($autoload, true) . ";\n" . $dumpScript, $specJson]);

    foreach (['桩侧' => $ours, '真实包侧' => $real] as $side => $run) {
        $decoded = json_decode(trim($run['stdout']), true);
        if (!is_array($decoded) || ($decoded['missing'] ?? []) !== []) {
            return [
                'status' => 'FAIL',
                'detail' => "{$side}反射子进程没有给出完整 JSON（exit {$run['code']}）："
                    . trim($run['stderr'] !== '' ? $run['stderr'] : $run['stdout'])
                    . '；缺失=' . json_encode($decoded['missing'] ?? null, JSON_UNESCAPED_UNICODE),
                'skips' => 0,
            ];
        }
    }

    $oursJson = json_decode(trim($ours['stdout']), true);
    $realJson = json_decode(trim($real['stdout']), true);

    $diffs = [];
    $extras = [];
    foreach (JOOMLA_DUMP_SPEC as $fqn => $_members) {
        $a = $oursJson['types'][$fqn];
        $b = $realJson['types'][$fqn];

        // 声明来源：桩侧的每个成员都必须落在 tests/Stubs/Framework/Joomla.php 里，
        // 真实侧必须落在对应的 joomla/* 包里 —— 防止「反射到了别处的同名声明」。
        foreach ($a['files'] as $member => $file) {
            if ($file !== 'Joomla.php') {
                $diffs[] = "{$fqn}::{$member} 的桩声明来自 {$file}，不是 tests/Stubs/Framework/Joomla.php";
            }
        }
        foreach ($b['files'] as $member => $file) {
            if (!preg_match('/^(Input|Registry|UriHelper|Priority|DispatcherInterface|SubscriberInterface|EventInterface)\.php$/', $file)) {
                $diffs[] = "{$fqn}::{$member} 的真实声明来自 {$file}，不像 joomla/* 包里的文件";
            }
        }

        joomla_member_diff(
            ['kind' => $a['kind'], 'constants' => $a['constants'], 'methods' => $a['methods']],
            ['kind' => $b['kind'], 'constants' => $b['constants'], 'methods' => $b['methods']],
            $fqn,
            $diffs
        );

        // 接口列表只做**单向**要求：桩不许声明真实类没有的接口（那会让单测依赖上
        // 生产里不存在的能力）；真实类多出来的接口是「桩更窄」，方向安全，但要报出来。
        $overClaimed = array_diff($a['extends'], $b['extends']);
        foreach ($overClaimed as $iface) {
            $diffs[] = "{$fqn}: 桩声明了 {$iface}，真实类没有（单测会依赖上生产里不存在的能力）";
        }
        foreach (array_diff($b['extends'], $a['extends']) as $iface) {
            $extras["{$fqn} → {$iface}"] = true;
        }
    }

    if ($diffs !== []) {
        return [
            'status' => 'FAIL',
            'detail' => count($diffs) . " 处桩声明与真实包不一致：\n  - " . implode("\n  - ", array_slice($diffs, 0, 15)),
            'skips' => 0,
        ];
    }

    // ================= §5 L2：真实包语义（不需要 ext-xhprof） =================
    // 这一段是**设计前提的证据**，不是复述代码：每一条都对应 src/Joomla 里的一个写法。
    //
    // 本进程里要同时装上四方：真实 Joomla 包 + 本包 src + 测试 fake + 框架桩。
    // 桩里对**可安装**类的声明都带 class_exists 守卫，真实包先加载就赢；
    // CMS 侧那几个类（CMSPlugin 等）谁也提供不了，只能来自桩 —— 这正是 SKIP 1 的形态。

    require $autoload;
    spl_autoload_register(static function (string $class) use ($repoRoot): void {
        $prefix = 'ErikWang2013\\Xhprof\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = $repoRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
    // Fakes 的加载期就要 implements Core 契约，故必须在 src 自注册**之后**
    require $repoRoot . '/tests/Fixtures/Fakes.php';
    require $stub;

    $checks = 0;
    $failures = [];
    $expect = static function (string $label, mixed $actual, mixed $expected) use (&$checks, &$failures): void {
        $checks++;
        if ($actual !== $expected) {
            $failures[] = $label . '：得到 ' . var_export($actual, true) . '，期望 ' . var_export($expected, true);
        }
    };

    // ---- 5a) 真实 Input 的语义 ----
    // new Input(null) 读 $_REQUEST；->server 是 __get 出来的、包着 $_SERVER 的子 Input。
    // 超全局是这一段唯一的输入源，前后原样恢复。
    $savedServer = $_SERVER;
    $savedRequest = $_REQUEST;
    $savedGet = $_GET;
    $savedPost = $_POST;

    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'HTTP_HOST' => 'example.com:8080',
        'REQUEST_URI' => '/admin/users?page=2&x=%3Cb%3E',
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.1',
        'HTTPS' => 'on',
    ];
    $_REQUEST = ['q' => '<b>a b</b>', 'a.b' => 'literal'];

    $input = new \Joomla\Input\Input($_REQUEST);
    $expect('L2 Input::get() 默认 cmd 过滤会吃掉标签与空格', $input->get('q'), 'babb');
    $expect('L2 Input::get(..., \'raw\') 原样（适配器 get() 因此必传 raw）', $input->get('q', null, 'raw'), '<b>a b</b>');
    $expect('L2 Input 的键是字面量，不做点号路径展开（all() 因此可原样透传键名）', $input->get('a.b', null, 'raw'), 'literal');
    $expect('L2 Input::get() 缺键返回默认值', $input->get('nope', 'dflt', 'raw'), 'dflt');
    $expect('L2 Input::getArray() 逐值清理（值被当成过滤器名 → 落到默认 string 过滤）', $input->getArray()['q'] ?? null, 'a b');
    $expect('L2 Input::getMethod() 读 $_SERVER', $input->getMethod(), 'POST');
    $expect('L2 Input 的 server 子输入是同一个类', $input->server instanceof \Joomla\Input\Input, true);
    $expect('L2 server 子输入读到 $_SERVER（不是 $_REQUEST）', $input->server->get('REQUEST_URI', '', 'string'), '/admin/users?page=2&x=%3Cb%3E');
    $expect('L2 Input::exists() 用 isset，null 值视为不存在（method() 的守卫依据）', $input->server->exists('REQUEST_METHOD'), true);

    // ---- 5b) 真实 RequestAdapter 对真实 Input ----
    $adapter = new \ErikWang2013\Xhprof\Joomla\Adapter\RequestAdapter($input);
    $expect('L2 R-1 uri() 只含 path+query（不含 scheme/host）', $adapter->uri(), '/admin/users?page=2&x=%3Cb%3E');
    $expect('L2 R-1 uri() 不含 fragment 之外的绝对地址', str_contains($adapter->uri(), 'example.com'), false);
    $expect('L2 R-2 host() 去掉端口', $adapter->host(), 'example.com');
    $expect('L2 R-3 getRealIp() 取 X-Forwarded-For 首段，不返回 null', $adapter->getRealIp(), '203.0.113.7');
    $expect('L2 url() 由 HTTPS=on 判 scheme（真实 Input 的 server 子输入）', $adapter->url(), 'https://example.com/admin/users?page=2&x=%3Cb%3E');
    $expect('L2 get() 走 Input::get(raw)：<b>x</b> 不被过滤', $adapter->get('q'), '<b>a b</b>');
    $expect('L2 all() 的键集合与 getArray() 一致（键名透传，不做路径展开）', array_keys($adapter->all()), ['q', 'a.b']);
    $expect('L2 all() 的值也是 raw（不落进 getArray 的逐值过滤）', $adapter->all()['q'] ?? null, '<b>a b</b>');
    $expect('L2 method() 走真实 Input::getMethod()', $adapter->method(), 'POST');
    $expect('L2 header() 读 HTTP_ 前缀并原样给出整行（切分是 getRealIp() 的事）', $adapter->header('X-Forwarded-For'), '203.0.113.7, 10.0.0.1');
    $expect('L2 header() 缺省返回 null', $adapter->header('X-Absent'), null);

    // REQUEST_METHOD 不在 $_SERVER 时：getMethod() 自身会 fatal（Input 没有兜底），
    // 适配器先用 exists() 挡掉 —— 这条是那个守卫的**依据**，不是装饰。
    unset($_SERVER['REQUEST_METHOD']);
    $inputNoMethod = new \Joomla\Input\Input([]);
    $expect('L2 缺 REQUEST_METHOD 时适配器仍不炸且回 GET', (new \ErikWang2013\Xhprof\Joomla\Adapter\RequestAdapter($inputNoMethod))->method(), 'GET');

    // ---- 5c) 真实 UriHelper / Uri：uri() 归一化与 host() 解析的依据 ----
    $expect('L2 UriHelper::parse_url(\'http://\') 返回 false 而不是抛', \Joomla\Uri\UriHelper::parse_url('http://'), false);
    $thrown = null;
    try {
        new \Joomla\Uri\Uri('http://');
    } catch (\Throwable $e) {
        $thrown = get_class($e);
    }
    $expect('L2 对照：new Uri(\'http://\') 直接抛（适配器因此不用 Uri 而用 UriHelper）', $thrown, 'RuntimeException');

    // ---- 5d) 真实 Registry 的双形态（R-6）与 stdClass 陷阱 ----
    // 关键前提（实测）：Registry 在构造时把**关联数组节点也转成 stdClass**（bindData），
    // 所以 get('xhprof') 返回对象 —— 哪怕传进去的是数组；get('xhprof.assets_url') 才是叶子。
    // ConfigAdapter 的 stdClass→array 归一化因此不是为「对象入参」特设的，而是**常态**路径。
    $regArray = new \Joomla\Registry\Registry(['xhprof' => ['assets_url' => '/a', 'ignore_url_arr' => ['/x']]]);
    $expect('L2 R-6 Registry::get(\'xhprof\') 返回 stdClass（数组入参也被 bindData 转成对象）', get_class($regArray->get('xhprof')), 'stdClass');
    $expect('L2 R-6 Registry::get(\'xhprof.assets_url\') 点号形直达叶子', $regArray->get('xhprof.assets_url'), '/a');
    $expect('L2 R-6 列表节点保持数组（isAssociative 判据：键 !== 下标）', $regArray->get('xhprof.ignore_url_arr'), ['/x']);

    // 真实 Joomla 的插件参数来自 #__extensions.params，json_decode 后是 stdClass。
    $regObject = new \Joomla\Registry\Registry(json_decode('{"xhprof":{"assets_url":"/o"}}'));
    $expect('L2 对象入参与数组入参在 get() 上表现一致', $regObject->get('xhprof.assets_url'), '/o');
    $thrown = null;
    try {
        $null = $regObject->get('xhprof')['assets_url'] ?? null;
    } catch (\Throwable $e) {
        $thrown = get_class($e) . ': ' . $e->getMessage();
    }
    $expect('L2 stdClass 上用 ?? / isset 直接下标 = Error（ConfigAdapter 强制转数组的依据）', $thrown, 'Error: Cannot use object of type stdClass as array');

    $configArray = new \ErikWang2013\Xhprof\Joomla\Adapter\ConfigAdapter(['assets_url' => '/p'], ['assets_url' => '/u']);
    $expect('L2 ConfigAdapter 把 Registry 的 stdClass 归一成数组', $configArray->get('xhprof'), ['assets_url' => '/u']);
    $expect('L2 ConfigAdapter 的点号形', $configArray->get('xhprof.assets_url'), '/u');

    // 合并语义：array_replace（浅），用户值覆盖包内值。
    // **必须有一条断言落在真正能分叉的输入上**：包内默认列表只有 1 个元素，用户也给 1 个元素时
    // array_replace 与 array_replace_recursive 逐字节相同（都是按下标覆盖），这种输入上的
    // 「不是递归合并」断言是空转的 —— 环里已经栽过（Slim 卡与 WordPress 卡各自独立撞上同一坑）。
    // 真正分叉的是「用户显式给空列表」：array_replace 清空，*_recursive 会把包内默认值留下。
    // 下面第一条断言用的是不会分叉的输入（旧版只有它，等于没验 R-7），第二条才是判别性的。
    $configMerge = new \ErikWang2013\Xhprof\Joomla\Adapter\ConfigAdapter(
        ['assets_url' => '/p', 'enable' => true, 'ignore_url_arr' => ['/a']],
        ['enable' => false]
    );
    $expect('L2 ConfigAdapter 是浅合并（array_replace）：用户只覆盖同名键', $configMerge->get('xhprof'), ['assets_url' => '/p', 'enable' => false, 'ignore_url_arr' => ['/a']]);
    $configCleared = new \ErikWang2013\Xhprof\Joomla\Adapter\ConfigAdapter(
        ['assets_url' => '/p', 'ignore_url_arr' => ['/xhprof']],
        ['ignore_url_arr' => []]
    );
    $expect('L2 R-7 用户显式给空列表必须真清空（array_replace_recursive 会把包内默认值留下）', $configCleared->get('xhprof.ignore_url_arr'), []);
    $expect('L2 R-7 整块形态同样被清空（bootstrap() 读的是这一形态）', $configCleared->get('xhprof')['ignore_url_arr'], []);
    $expect('L2 ConfigAdapter::get 默认值', $configMerge->get('xhprof.nope', 'dflt'), 'dflt');
    $expect('L2 ConfigAdapter::get(\'xhprof\') 恒为数组（下游用 ?? 直接下标）', is_array($configMerge->get('xhprof')), true);

    // ---- 5e) 真实 Dispatcher：事件名 / 优先级数组真的能注册并被分发 ----
    // getSubscribedEvents() 的键是事件名（Dispatcher::addSubscriber 拿键当事件名注册），
    // 值是 [方法名, 优先级]。这一段用真实 Dispatcher 跑，等于把「字面量事件名」这个
    // 决定的接口面钉死在真实包上（CMS 那一半的分发点仍然见 SKIP 2）。
    $_SERVER = [
        'HTTP_HOST' => 'example.com',
        'REQUEST_URI' => '/xhprof?token=tok',
        'REQUEST_METHOD' => 'GET',
        'REMOTE_ADDR' => '10.0.0.1',
    ];
    $_REQUEST = ['token' => 'tok'];

    $siteRoot = sys_get_temp_dir() . '/xhprof-joomla-contract-' . bin2hex(random_bytes(4));
    if (!mkdir($siteRoot, 0777, true) && !is_dir($siteRoot)) {
        return ['status' => 'FAIL', 'detail' => "临时站点根建不出来：{$siteRoot}", 'skips' => 0];
    }
    // 本卡 §3/§4/§5 有多个提前 return 的 FAIL 分支，走不到末尾那两行收尾，临时站点根就留在
    // /tmp 里了（回退验证跑完实测留下 3 个空壳目录）。挂 shutdown 才覆盖「return/异常/fatal」
    // 所有退出路径；末尾那两行保留，重复清理无害。
    register_shutdown_function(static function () use ($siteRoot): void {
        @unlink($siteRoot . '/xhprof.php');
        @rmdir($siteRoot);
    });
    if (!defined('JPATH_ROOT')) {
        define('JPATH_ROOT', $siteRoot);   // 站点根配置的读法（包内 config + 站点根 xhprof.php）
    }
    // `locale` 钉死：报告页文案随语言协商变化，断言（中文标题 / 拒绝页不是报告页）
    // 不该依赖请求里默认带没带 Accept-Language。同一条配置也喂给下面的 403 用例，
    // 那句「不是报告页」因此不会因为报告页翻成别的语言而假通过。
    file_put_contents($siteRoot . '/xhprof.php', "<?php\n\nreturn ['enable' => false, 'auth_token' => 'tok', 'locale' => 'zh_CN'];\n");

    // 假应用把自己的输入当参数拿：$_REQUEST 只喂给真实 Input 的默认构造，
    // 应用侧的输入必须显式传（真实 CMS 里它就是 $app->getInput()）。
    $app = new \ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaFakeApplication(['token' => 'tok']);
    $cache = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
    $dispatcher = new \Joomla\Event\Dispatcher();
    $plugin = new \ErikWang2013\Xhprof\Joomla\Extension\Xhprof($dispatcher, [], $cache);
    $plugin->setApplication($app);
    $dispatcher->addSubscriber($plugin);

    ob_start();
    $dispatcher->dispatch('onAfterInitialise', new \Joomla\Event\Event('onAfterInitialise'));
    $reportBody = (string) ob_get_clean();

    $expect('L2 真实 Dispatcher 认下 getSubscribedEvents() 的键（onAfterInitialise 上确有监听器）', $dispatcher->getListeners('onAfterInitialise') !== [], true);
    $expect('L2 报告页短路的 200 正文里带报告标题', str_contains($reportBody, 'XHProf 性能分析报告'), true);
    $expect('L2 报告页结束于 $app->close()（环里桩只记录调用）', $app->closeCalls, 1);
    $expect('L2 报告页设了 Content-Type', $app->headerValue('Content-Type'), 'text/html; charset=UTF-8');
    $expect('L2 报告页不发 Status 头（200 是默认）', $app->headerValue('Status'), null);
    $expect('L2 报告页不落库（短路在采样之前）', $cache->lRange('xhprof:run_id', 0, -1), []);

    // 鉴权失败：token 不符 → Core 的 deny() 用响应适配器发 403，入口类不补发 200
    $_SERVER['REQUEST_URI'] = '/xhprof?token=wrong';
    $_REQUEST = ['token' => 'wrong'];
    $app403 = new \ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaFakeApplication(['token' => 'wrong']);
    $cache403 = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
    $plugin403 = new \ErikWang2013\Xhprof\Joomla\Extension\Xhprof(new \Joomla\Event\Dispatcher(), [], $cache403);
    $plugin403->setApplication($app403);
    ob_start();
    $plugin403->onAfterInitialise(new \Joomla\Event\Event('onAfterInitialise'));
    $deniedBody = (string) ob_get_clean();
    $expect('L2 token 不符时发 403', $app403->headerValue('Status'), '403');
    $expect('L2 token 不符时正文是拒绝页（不是报告页）', str_contains($deniedBody, 'XHProf 性能分析报告'), false);
    $expect('L2 token 不符时也只 close 一次（入口类不重复终止）', $app403->closeCalls, 1);
    $expect('L2 token 不符时不落库', $cache403->lRange('xhprof:run_id', 0, -1), []);

    // 静态资源：走 Core 的 StaticController，Content-Type 由 Core 的 MIME 表钉死
    $_SERVER['REQUEST_URI'] = '/xhprof-assets/css/xhprof.css';
    $_REQUEST = [];
    $appAsset = new \ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaFakeApplication([]);
    $pluginAsset = new \ErikWang2013\Xhprof\Joomla\Extension\Xhprof(new \Joomla\Event\Dispatcher(), [], new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache());
    $pluginAsset->setApplication($appAsset);
    ob_start();
    $pluginAsset->onAfterInitialise(new \Joomla\Event\Event('onAfterInitialise'));
    $css = (string) ob_get_clean();
    $expect('L2 资源路径输出非空且被判为 css', $appAsset->headerValue('Content-Type') !== null && $css !== '', true);
    $expect('L2 资源路径也 close 一次', $appAsset->closeCalls, 1);

    // 目录穿越：Core 的 StaticController::readFile 拦住（适配器不自造沙箱）
    $_SERVER['REQUEST_URI'] = '/xhprof-assets/../../composer.json';
    $_REQUEST = [];
    $appTrav = new \ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaFakeApplication([]);
    $pluginTrav = new \ErikWang2013\Xhprof\Joomla\Extension\Xhprof(new \Joomla\Event\Dispatcher(), [], new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache());
    $pluginTrav->setApplication($appTrav);
    ob_start();
    $pluginTrav->onAfterInitialise(new \Joomla\Event\Event('onAfterInitialise'));
    $travBody = (string) ob_get_clean();
    $expect('L2 穿越路径读不到包外文件', str_contains($travBody, 'composer'), false);

    // ================= §6 L2：需要 ext-xhprof（缺扩展时计入 skips） =================

    $ext = extension_loaded('xhprof');
    $extChecks = 0;
    $extFailures = [];
    $extExpect = static function (string $label, mixed $actual, mixed $expected) use (&$extChecks, &$extFailures): void {
        $extChecks++;
        if ($actual !== $expected) {
            $extFailures[] = $label . '：得到 ' . var_export($actual, true) . '，期望 ' . var_export($expected, true);
        }
    };

    $skips = JOOMLA_SKIPS_DECLARED;

    if (!$ext) {
        $skips += JOOMLA_EXT_CHECKS_DECLARED;
    } else {
        // 常开请求：真实 Dispatcher 派发 onAfterInitialise → 采样开；onAfterRespond → 落库一次
        $_SERVER['REQUEST_URI'] = '/index.php';
        $_REQUEST = [];
        file_put_contents($siteRoot . '/xhprof.php', "<?php\n\nreturn ['enable' => true];\n");

        $appRun = new \ErikWang2013\Xhprof\Tests\Stubs\Framework\JoomlaFakeApplication([]);
        $cacheRun = new \ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
        $dispatcherRun = new \Joomla\Event\Dispatcher();
        $pluginRun = new \ErikWang2013\Xhprof\Joomla\Extension\Xhprof($dispatcherRun, [], $cacheRun);
        $pluginRun->setApplication($appRun);
        $dispatcherRun->addSubscriber($pluginRun);

        ob_start();
        $dispatcherRun->dispatch('onAfterInitialise', new \Joomla\Event\Event('onAfterInitialise'));
        xhprof_joomla_marker();
        $dispatcherRun->dispatch('onAfterRespond', new \Joomla\Event\Event('onAfterRespond'));
        ob_end_clean();

        $runs = $cacheRun->lRange('xhprof:run_id', 0, -1);
        $extExpect('L2 采样：常开请求恰好落一条', count($runs), 1);
        $data = $runs === [] ? [] : (array) unserialize((string) $cacheRun->get('xhprof:xhprof_log:' . $runs[0]));
        $extExpect('L2 采样：落库数据是本次采样（有 main()）', array_key_exists('main()', $data), true);
        $covered = false;
        foreach (array_keys($data) as $key) {
            if (str_contains((string) $key, 'xhprof_joomla_marker')) {
                $covered = true;
                break;
            }
        }
        $extExpect('L2 采样：采样窗口覆盖到本次请求里跑过的代码', $covered, true);
        $extExpect('L2 采样：请求结束后采样已停（再 stop 一次无害）', xhprof_disable(), null);
    }

    // ================= §8 L0：桩 vs 两个真实 CMS 包（每个包一个子进程） =================
    // 与 §4 同一手法：桩与真实包不能在同一个进程里共存（同名 FQN 加载期对撞），
    // dump 逻辑是**同一段代码**（joomla_dump_script），比较在**本进程**做。
    // 方向：桩可更窄、不许更宽；差异集**按版本冻结**（JOOMLA_CMS_EXPECTED_DIFFS）：
    // 多一条是桩漂了或包换了，少一条是那条差异的依据消失了 —— 两种都要人重看一遍。

    $cmsSpecJson = json_encode(JOOMLA_CMS_SHARED_SPEC, JSON_THROW_ON_ERROR);
    $cmsAllSpecJson = json_encode(JOOMLA_CMS_SHARED_SPEC + JOOMLA_CMS_REAL_SPEC, JSON_THROW_ON_ERROR);

    $stubCmsRun = contracts_run_php(['-r', 'require ' . var_export($stub, true) . ";\n" . $dumpScript, $cmsSpecJson]);
    $stubCms = json_decode(trim($stubCmsRun['stdout']), true);
    if (!is_array($stubCms) || ($stubCms['missing'] ?? []) !== []) {
        return [
            'status' => 'FAIL',
            'detail' => '桩侧 CMS 反射子进程没有给出完整 JSON（exit ' . $stubCmsRun['code'] . '）：'
                . trim($stubCmsRun['stderr'] !== '' ? $stubCmsRun['stderr'] : $stubCmsRun['stdout'])
                . '；缺失=' . json_encode($stubCms['missing'] ?? null, JSON_UNESCAPED_UNICODE),
            'skips' => 0,
        ];
    }
    // 桩侧每个成员的声明出处必须是那个桩文件 —— 防「反射到别处的同名声明」
    foreach (JOOMLA_CMS_SHARED_SPEC as $fqn => $members) {
        foreach ($members as $member) {
            $file = $stubCms['types'][$fqn]['files'][$member] ?? '?';
            if ($file !== 'Joomla.php') {
                return [
                    'status' => 'FAIL',
                    'detail' => "{$fqn}::{$member} 的桩声明来自 {$file}，"
                        . '不是 tests/Stubs/Framework/Joomla.php（桩文件被改名/搬走了？）',
                    'skips' => 0,
                ];
            }
        }
    }

    $cmsRealDumps = [];
    foreach (JOOMLA_CMS_PACKAGES as $version => $package) {
        $pkgDir = contracts_dir() . '/vendor/' . $package;
        $cmsRun = contracts_run_php([
            '-r',
            joomla_cms_boot_script($pkgDir . '/libraries/vendor/autoload.php', $pkgDir . '/libraries', $siteRoot) . $dumpScript,
            $cmsAllSpecJson,
        ]);
        $cmsReal = json_decode(trim($cmsRun['stdout']), true);
        if (!is_array($cmsReal) || !isset($cmsReal['types'])) {
            return [
                'status' => 'FAIL',
                'detail' => "{$package} 反射子进程没有给出 JSON（exit {$cmsRun['code']}）："
                    . trim($cmsRun['stderr'] !== '' ? $cmsRun['stderr'] : $cmsRun['stdout'])
                    . ' —— 空 stdout 通常是漏了 _JEXEC/JPATH_* 定义，CMS 文件被提前 die 掉了',
                'skips' => 0,
            ];
        }
        $cmsRealDumps[$version] = $cmsReal;

        // 真实侧「缺失」必须**精确等于**冻结的合成项：多一条 = 我们断言的能力真实包没有，
        // 少一条 = 合成项消失了（桩该停止合成，改成与真实一致的写法）
        $realMissing = $cmsReal['missing'];
        sort($realMissing);
        $expectedMissing = JOOMLA_CMS_REAL_MISSING;
        sort($expectedMissing);
        $expect(
            "§8 {$version} 真实侧缺失项精确等于冻结的合成项",
            $realMissing,
            $expectedMissing
        );

        $cmsDiffs = [];
        $cmsNotes = [];
        foreach (JOOMLA_CMS_SHARED_SPEC as $fqn => $members) {
            if (!isset($cmsReal['types'][$fqn])) {
                $cmsDiffs[] = "{$fqn}.missing";
                continue;
            }
            joomla_cms_compare($stubCms['types'][$fqn], $cmsReal['types'][$fqn], $fqn, JOOMLA_CMS_REAL_MISSING, $cmsDiffs, $cmsNotes);

            // 真实侧声明出处：必须是 CMS 自己的那几个文件（防反射到 vendor 里的同名副本）。
            // 合成项本来就没有声明（在 missing 里），由下面那条断言单独钉。
            foreach ($members as $member) {
                if (in_array("{$fqn}::{$member}", JOOMLA_CMS_REAL_MISSING, true)) {
                    continue;
                }
                $file = $cmsReal['types'][$fqn]['files'][$member] ?? '?';
                if (!in_array($file, JOOMLA_CMS_DECLARING[$fqn], true)) {
                    $cmsDiffs[] = "{$fqn}::{$member}.declaring({$file})";
                }
            }
        }
        sort($cmsDiffs);
        $expectedCmsDiffs = JOOMLA_CMS_EXPECTED_DIFFS[$version];
        sort($expectedCmsDiffs);
        $expect(
            "§8 {$version} 差异集精确等于冻结值（多一条少一条都算漂）",
            $cmsDiffs,
            $expectedCmsDiffs
        );

        // §8b 合成项的真身：桩断言 CMSApplicationInterface 有 setHeader/sendHeaders，
        // 真实 CMS 的接口上确实没有（上面 missing 那条），但真身必须在兄弟接口上存在、
        // 参数个数与冻结值一致、且参数与返回**全无型别**（最宽）—— 桩写了型别，只能是更窄，
        // 不会替生产背书假能力。
        foreach (JOOMLA_CMS_SYNTHESIS as $member => $sibling) {
            $sig = [];
            foreach ($cmsReal['types'][$sibling]['methods'][$member]['params'] as $p) {
                $sig[] = $p['type'];
            }
            $sig[] = $cmsReal['types'][$sibling]['methods'][$member]['return'];
            $expect(
                "§8b {$version} 合成项 {$member}：真身 {$sibling} 的 " . JOOMLA_CMS_SYNTHESIS_ARITY[$member]
                    . ' 个参数与返回全无型别（桩只能更窄）',
                [count($sig), array_values(array_filter($sig, static fn ($t): bool => $t !== null))],
                [JOOMLA_CMS_SYNTHESIS_ARITY[$member] + 1, []]
            );
        }
    }

    // §8c 比较器正对照：把桩快照的副本改坏，比较器必须报出对应的两条。
    // 一个从没红过的比较器不是检查（Drupal 卡同款做法）。两条改动落在两个不同的比较分叉上：
    // 常量值（精确相等那一支）与参数型别（方向比较那一支）。
    $mutant = $stubCms;
    $mutant['types']['Joomla\CMS\Log\Log']['constants']['ERROR'] = '16';
    $mutant['types']['Joomla\CMS\Plugin\CMSPlugin']['methods']['setApplication']['params'][0]['type'] = 'stdClass';
    $mutantDiffs = [];
    $mutantNotes = [];
    foreach (JOOMLA_CMS_SHARED_SPEC as $fqn => $_members) {
        joomla_cms_compare($mutant['types'][$fqn], $cmsRealDumps['5.2.2']['types'][$fqn], $fqn, JOOMLA_CMS_REAL_MISSING, $mutantDiffs, $mutantNotes);
    }
    sort($mutantDiffs);
    $expect(
        '§8c 比较器正对照：改坏桩快照后必须报出这两处差异',
        $mutantDiffs,
        ['Joomla\CMS\Log\Log::ERROR.constant', 'Joomla\CMS\Plugin\CMSPlugin::setApplication.p0.type']
    );

    // ================= §9 L2：真 CMS 端到端（每版本四个请求） =================
    // 真 SiteApplication + 真 CMSPlugin + 真事件类 + 真 Dispatcher + 本包入口类。
    // 这一段是 SKIP 1/2 解冻的**主体**：close() 在真实 CMS 里是不是 exit()、setHeader/
    // sendHeaders 落到真实应用上是什么样、4.4 双次 onAfterRespond 下幂等守卫顶不顶得住，
    // 全都在这里以「子进程真的跑了」的形式回答，不再靠源码阅读。
    // 报告页正文/响应头要 ext-redis（src/Core/Xhprof.php:121 的硬守卫），缺扩展时计入 skips。
    $redis = extension_loaded('redis');
    $redisChecks = 0;
    $redisFailures = [];
    $redisExpect = static function (string $label, mixed $actual, mixed $expected) use (&$redisChecks, &$redisFailures): void {
        $redisChecks++;
        if ($actual !== $expected) {
            $redisFailures[] = $label . '：得到 ' . var_export($actual, true) . '，期望 ' . var_export($expected, true);
        }
    };
    $skips += $redis ? 0 : JOOMLA_REDIS_CHECKS_DECLARED;

    $e2eScript = joomla_e2e_script();
    foreach (JOOMLA_CMS_PACKAGES as $version => $package) {
        $pkgDir = contracts_dir() . '/vendor/' . $package;
        foreach (JOOMLA_E2E_MODES as $mode) {
            $e2eRun = contracts_run_php(['-r', $e2eScript, $pkgDir, $repoRoot, $siteRoot, $mode]);
            $obs = joomla_e2e_observe($e2eRun['stderr']);
            if ($obs === null) {
                return [
                    'status' => 'FAIL',
                    'detail' => "§9 {$version}/{$mode} 端到端子进程没有给出观测行（exit {$e2eRun['code']}）："
                        . trim($e2eRun['stderr'] !== '' ? $e2eRun['stderr'] : $e2eRun['stdout']),
                    'skips' => 0,
                ];
            }
            $label = "§9 {$version}/{$mode}";
            $body = $e2eRun['stdout'];

            $expect("{$label} 子进程正常结束", $e2eRun['code'], 0);
            // 采样模式是**正对照**：它不 close()，所以标记必须出现。其余三种请求都该在
            // 派发之后被 close() 掐断（真实 CMS 里 close() = exit()），标记不许出现。
            $expect(
                "{$label} " . ($mode === 'sample' ? '没有 close()、脚本跑到底（正对照）' : '在派发后被真实 close() 掐断（= exit）'),
                [str_contains($body, 'AFTER-INITIALISE-DISPATCH'), str_contains($body, 'END-OF-SCRIPT')],
                $mode === 'sample' ? [true, true] : [false, false]
            );
            if ($mode !== 'sample') {
                // 采样关闭（enable=false）或请求被 close() 掐断：一条都不许落。
                // sample 模式落没落由下面 ext 门控的「恰好一条」负责，这里不比（自比是恒真）。
                $expect("{$label} 常驻采样以外的请求不落库", $obs['cache_runs'], []);
            }

            if ($mode === 'sample') {
                // 4.4 里 onAfterRespond 有**两个**分发点（CMSApplication.php:332 与
                // WebApplication.php:188），同一请求可能各来一次 —— 幂等守卫就是为它准备的。
                $expect("{$label} 两次 onAfterRespond 都真的派发了", $obs['on_after_respond_count'], 2);
                if ($ext) {
                    $extExpect("{$label} 幂等：两次 onAfterRespond 只落一条", count($obs['cache_runs']), 1);
                    $extExpect("{$label} 落库数据是本次采样（有 main()）", $obs['run_has_main'], true);
                    $extExpect("{$label} 采样窗口覆盖到本次请求里跑过的代码", $obs['run_covers_marker'], true);
                }
            }

            if ($mode === 'assets') {
                // 静态资源是**唯一**一条路：正文、两个响应头、真 exit 都在，且不依赖 ext-redis。
                $expect(
                    "{$label} 真实应用上的响应头逐字（Content-Type + Cache-Control，都由 setHeader 落）",
                    $obs['headers'],
                    ['Content-Type: text/css', 'Cache-Control: public, max-age=86400']
                );
                $expect("{$label} 正文是真实样式表（StaticController 读出来的那份）", str_starts_with($body, '/**') && str_contains($body, ':root'), true);
                $expect("{$label} 真实应用上四个方法都在（桩的合成不是幻觉）", $obs['has_method'], ['setHeader', 'sendHeaders', 'close', 'getInput']);
                $expect("{$label} 入口类是真实 CMSPlugin 的子类", [$obs['plugin_is_cms_plugin'], $obs['plugin_parent']], [true, 'Joomla\CMS\Plugin\CMSPlugin']);
                $expect("{$label} 注入的 dispatcher 就是插件持有的那个", $obs['dispatcher_identity'], true);
                $expect("{$label} getApplication() 是 protected（与桩同形）", $obs['get_application_visibility'], 'protected');
                $expect("{$label} setApplication() 之前 getApplication() 为 null", $obs['application_before_set'], true);
                $expect("{$label} setApplication() 之后拿到同一个应用", $obs['application_after_set_is_app'], true);
                $expect(
                    "{$label} registerListeners() 前后每个事件恰好 +1 个监听器",
                    [$obs['listener_count_before'], $obs['listener_count_initialise'], $obs['listener_count_respond']],
                    [1, 2, 2]
                );
                $expect("{$label} 输入是真实 Joomla\CMS\Input\Input", $obs['input_class'], 'Joomla\CMS\Input\Input');
                $expect("{$label} 分发的是真实 CMS 的事件对象", $obs['dispatched'][0] ?? null, 'onAfterInitialise:' . JOOMLA_CMS_EVENT_CLASS[$version]);
            }

            if ($redis && ($mode === 'report' || $mode === 'deny')) {
                // 报告页 / 鉴权失败：正文与头（缺 ext-redis 时走本包自己的 500 拒绝页，
                // 那是另一种响应，不是这里要钉的形状）
                if ($mode === 'report') {
                    $redisExpect("{$label} 正文是真实报告页（zh_CN，语言由配置钉死）", str_starts_with($body, '<html lang="zh-CN">'), true);
                    $redisExpect("{$label} 报告页响应头逐字", $obs['headers'], ['Cache-Control: no-cache, private', 'Content-Type: text/html; charset=UTF-8']);
                } else {
                    $redisExpect("{$label} token 不符时正文是拒绝页", $body, '403 Forbidden');
                    $redisExpect("{$label} token 不符时 Status 头是 403", $obs['headers'], ['Status: 403']);
                }
            }
        }
    }

    // ================= §10 L2：真 Log 通路（本包 LogAdapter → 真 Joomla\CMS\Log\Log） =================
    // 桩里的 Log 只记数组；真实 Log 是静态注册表 + 回调 logger。这里在子进程里用**真** Log
    // 跑本包适配器，钉住四件事在真实类上成立：级别是位掩码 8、分类是 'xhprof'、message 原样、
    // context 一路带到 LogEntry。§4 的反射比对只能证明「常量值对得上」，证明不了这条通路。
    foreach (JOOMLA_CMS_PACKAGES as $version => $package) {
        $pkgDir = contracts_dir() . '/vendor/' . $package;
        $logRun = contracts_run_php([
            '-r',
            joomla_cms_boot_script($pkgDir . '/libraries/vendor/autoload.php', $pkgDir . '/libraries', $siteRoot)
                . joomla_repo_autoload_script($repoRoot)
                . joomla_log_script(),
        ]);
        $logObs = json_decode(trim($logRun['stdout']), true);
        if (!is_array($logObs) || !isset($logObs['entry'])) {
            return [
                'status' => 'FAIL',
                'detail' => "§10 {$version} 真 Log 子进程没有给出 JSON（exit {$logRun['code']}）："
                    . trim($logRun['stderr'] !== '' ? $logRun['stderr'] : $logRun['stdout']),
                'skips' => 0,
            ];
        }
        $expect(
            "§10 {$version} LogAdapter 的调用被真实 Log 的回调 logger 收到，且是 LogEntry",
            $logObs['entry'],
            [
                'class' => 'Joomla\CMS\Log\LogEntry',
                'message' => 'xhprof 契约：真 Log 通路',
                'priority' => 8,
                'category' => 'xhprof',
                'context' => ['k' => 'v'],
            ]
        );
    }

    // ================= §11 provider 协议 + 安装形态（SKIP A/B：静态钉 + 实测阻塞） =================
    // (1) 静态：manifest ↔ 别名文件 ↔ CMS 的 namespacemap 机制，三者必须自洽；
    // (2) 实测：provider 在**真容器**上 register() 成功，但服务**取不出来** —— 卡在真实 CMS
    //     未启动与 #__extensions 要数据库。这两条就是剩下两个 SKIP 的理由（下面逐条断言）。
    $manifestPath = $repoRoot . '/joomla/xhprof.xml';
    $aliasPath = $repoRoot . '/joomla/src/Extension/Xhprof.php';
    $providerPath = $repoRoot . '/joomla/services/provider.php';
    foreach ([$manifestPath, $aliasPath, $providerPath] as $required) {
        if (!is_file($required)) {
            return ['status' => 'FAIL', 'detail' => "插件安装形态缺文件：{$required}", 'skips' => 0];
        }
    }
    $manifestXml = @simplexml_load_string((string) file_get_contents($manifestPath));
    if ($manifestXml === false) {
        return ['status' => 'FAIL', 'detail' => 'joomla/xhprof.xml 解析不了（不是合法 XML）', 'skips' => 0];
    }
    $manifestNamespace = (string) ($manifestXml->namespace ?? '');
    $expect(
        '§11 manifest 的扩展类型/分组/升级方式与 Joomla 约定一致',
        [(string) $manifestXml['type'], (string) $manifestXml['group'], (string) $manifestXml['method'], (string) $manifestXml->namespace['path']],
        ['plugin', 'system', 'upgrade', 'src']
    );
    // 别名文件把 Joomla 惯例的类名指向包里的实现：目标类名必须**正好**是
    // manifest 的 <namespace> + \Extension\Xhprof（安装器把这些文件拷进
    // plugins/system/xhprof/，CMS 的 namespacemap 插件据此注册前缀），
    // 而别名源必须真的在包里声明。两边任何一处改名都要在这里红。
    $aliasSource = (string) file_get_contents($aliasPath);
    $aliasTarget = null;
    if (preg_match('/class_alias\(\s*\\\\?([A-Za-z0-9_\\\\]+)::class\s*,\s*\'([^\']+)\'\s*\)/', $aliasSource, $m) === 1) {
        $aliasTarget = [$m[1], $m[2]];
    }
    $expect(
        '§11 别名文件：源 = 包里声明的入口类，目标 = manifest namespace + \Extension\Xhprof',
        $aliasTarget,
        ['ErikWang2013\\Xhprof\\Joomla\\Extension\\Xhprof', $manifestNamespace . '\\Extension\\Xhprof']
    );
    $expect(
        '§11 manifest namespace 指向的类在包里真的声明了（别名源）',
        str_contains((string) file_get_contents($repoRoot . '/src/Joomla/Extension/Xhprof.php'), 'namespace ErikWang2013\Xhprof\Joomla\Extension;'),
        true
    );
    // CMS 自己的 namespace 映射机制：插件类型走 JPATH_PLUGINS + manifest 里的 <namespace>
    // （libraries/namespacemap.php 由核心插件 plg_extension_namespacemap 生成/读取）。
    // 也就是说「bootPlugin 找得到类」这件事**依赖安装器写过的东西**，不是自动的 —— 这正是
    // SKIP B 的机制依据（下面 runtime 部分量的是它的后果）。
    foreach (JOOMLA_CMS_PACKAGES as $version => $package) {
        $namespaceMap = contracts_dir() . '/vendor/' . $package . '/libraries/namespacemap.php';
        $mapSource = is_file($namespaceMap) ? (string) file_get_contents($namespaceMap) : '';
        $expect(
            "§11 {$version} CMS 的插件 namespace 映射走 JPATH_PLUGINS + manifest namespace",
            [str_contains($mapSource, "getNamespaces('plugin')"), str_contains($mapSource, 'JPATH_PLUGINS')],
            [true, true]
        );
    }
    // runtime：provider 协议成立，但服务取不出来 —— 抛点就是理由本身
    $providerScript = joomla_provider_script();
    foreach (JOOMLA_CMS_PACKAGES as $version => $package) {
        $pkgDir = contracts_dir() . '/vendor/' . $package;
        $providerRun = contracts_run_php([
            '-r',
            joomla_cms_boot_script($pkgDir . '/libraries/vendor/autoload.php', $pkgDir . '/libraries', $siteRoot)
                . $providerScript,
            $providerPath,
            $repoRoot,
        ]);
        $providerObs = json_decode(trim($providerRun['stdout']), true);
        if (!is_array($providerObs)) {
            return [
                'status' => 'FAIL',
                'detail' => "§11 {$version} provider 子进程没有给出 JSON（exit {$providerRun['code']}）："
                    . trim($providerRun['stderr'] !== '' ? $providerRun['stderr'] : $providerRun['stdout'])
                    . ' —— provider.php 第一行是 `defined(\'_JEXEC\') or die`，没定义常量就会静默 die',
                'skips' => 0,
            ];
        }
        $expect(
            "§11 {$version} provider 实现的是真实 ServiceProviderInterface，register() 在真容器上不抛；"
                . '没装（无 namespacemap）时容器找不到插件类',
            [$providerObs['instanceof'], $providerObs['registered'], $providerObs['register_error'], $providerObs['unmapped_error']],
            [
                true,
                true,
                null,
                'Error: Class "Joomla\\Plugin\\System\\Xhprof\\Extension\\Xhprof" not found',
            ]
        );
        // 补上 namespacemap 之后：类找得到（且真身就是我们包里那个），但服务仍取不出来 ——
        // 三条路径（容器取插件 / Factory 要应用 / PluginHelper 读参数）都是**同一个**实测异常。
        // 字符串逐字断言：哪天它能跑了，这里红，两个 SKIP 就该重估。
        $expect(
            "§11 {$version} 补上 namespacemap 后类可加载（真身是我们的入口类），但取插件仍卡在 CMS 未启动",
            [$providerObs['mapped_is_ours'], $providerObs['container_error'], $providerObs['factory_error'], $providerObs['pluginhelper_error']],
            [
                true,
                'Exception: Failed to start application',
                'Exception: Failed to start application',
                'Exception: Failed to start application',
            ]
        );
    }

    // ================= §11c SKIP A 的 SQLite 近路：有界试探 =================
    // 「不用数据库」这条近路是真的试过的，不是没想过：真 sqlite 驱动、真 #__extensions 表、
    // 真 params 行（enable/auth_token），再看 PluginHelper::getPlugin() 能不能读出来。
    // 各步抛点原样上报 —— 这就是 SKIP A 的机器可核实理由（不是散文）。
    // §11c 的后两条另给 SKIP B 的实测：装好的样子 bootPlugin 也拿不到插件（provider 里「类不存在」）。
    $sqliteScript = joomla_sqlite_probe_script();
    foreach (JOOMLA_CMS_PACKAGES as $version => $package) {
        $pkgDir = contracts_dir() . '/vendor/' . $package;
        $sqliteRun = contracts_run_php(['-r', $sqliteScript, $pkgDir, $siteRoot, $repoRoot]);
        $sqliteObs = json_decode(trim($sqliteRun['stdout']), true);
        if (!is_array($sqliteObs) || !isset($sqliteObs['steps']['db'])) {
            return [
                'status' => 'FAIL',
                'detail' => "§11c {$version} SQLite 近路探针没有给出 JSON（exit {$sqliteRun['code']}）："
                    . trim($sqliteRun['stderr'] !== '' ? $sqliteRun['stderr'] : $sqliteRun['stdout'])
                    . ' —— 引子里漏了常量会让 4.4 的 bootstrap.php 直接致命',
                'skips' => 0,
            ];
        }
        $sqlite = $sqliteObs['steps'];
        // 正对照：库和行真建出来了（否则「读不到」是夹具自己没搭好，不是 CMS 的边界）
        $expect(
            "§11c {$version} 近路的正对照：真 sqlite 驱动 + 真 #__extensions 行（含 params）建出来了",
            [$sqlite['db']['ok'], $sqlite['db']['data']],
            [true, 1]
        );
        // 两个抛点：容器不许覆盖 DatabaseInterface（这是 protected key）；就算绕过它，
        // 读插件参数仍死在 session —— 都发生在读 #__extensions 之前
        $expect(
            "§11c {$version} SQLite 近路断在哪（逐字）：容器 protected key，然后 PluginHelper 卡在 session",
            [$sqlite['container']['error'], $sqlite['pluginhelper']['error']],
            [
                'Joomla\DI\Exception\ProtectedKeyException: Key Joomla\\Database\\DatabaseInterface is protected and can\'t be overwritten.',
                'RuntimeException: A Joomla\\Session\\SessionInterface object has not been set.',
            ]
        );
        // SKIP B 的最强形式：manifest 与 services/ 都按安装后的样子摆好了，bootPlugin 仍然拿不到插件 ——
        // provider.php 里 `$plugin = new Xhprof(` 当场丢出
        // `Class "Joomla\Plugin\System\Xhprof\Extension\Xhprof" not found`：那个类只在安装器写过
        // namespacemap 之后才存在（§11 已实测：手工补上 map 类就能加载）。抛点逐字钉（两个版本同）。
        $expect(
            "§11c {$version} 装好的样子 bootPlugin 也拿不到插件：provider.php 当场说类不存在（安装器没写 namespacemap）",
            [$sqlite['bootInstalled']['ok'], $sqlite['bootInstalled']['data']],
            [true, 'Error: Class "Joomla\\Plugin\\System\\Xhprof\\Extension\\Xhprof" not found @ joomla/services/provider.php']
        );
    }

    // ================= §12 分发点静态钉（裸名事件名，逐版本） =================
    // 入口类用字面量事件名的**依据**就在这些行里。钉的是两件事：
    //  (1) 裸名（不是 ApplicationEvents 常量，那个类两个版本都没有 —— §3B 已实测）；
    //  (2) 5.x 用**有型别**的事件类派发、4.4 用通用 Joomla\Event\Event（§9 的 dispatched 观测
    //      与之呼应：一个是 AfterInitialiseEvent，一个是 Event）。
    $dispatchPins = [
        '5.2.2' => [
            'libraries/src/Application/CMSApplication.php' => [
                "new AfterInitialiseEvent('onAfterInitialise', ['subject' => \$this])",
                "new AfterRespondEvent('onAfterRespond', ['subject' => \$this])",
                // 这行是插件在生产里的**装载点**：CMS 自己调 importPlugin('system', …)
                "PluginHelper::importPlugin('system', null, true, \$this->getDispatcher());",
            ],
        ],
        '4.4.14' => [
            'libraries/src/Application/CMSApplication.php' => [
                "\$this->triggerEvent('onAfterInitialise');",
                "\$this->getDispatcher()->dispatch('onAfterRespond');",
            ],
            'libraries/src/Application/WebApplication.php' => [
                "\$this->triggerEvent('onAfterRespond');",
            ],
        ],
    ];
    $cmsEventNames = [];
    foreach ($dispatchPins as $version => $files) {
        $hits = [];
        $names = [];
        foreach ($files as $relFile => $needles) {
            $source = (string) file_get_contents(contracts_dir() . '/vendor/' . JOOMLA_CMS_PACKAGES[$version] . '/' . $relFile);
            foreach ($needles as $needle) {
                if (str_contains($source, $needle)) {
                    $hits[] = $needle;
                }
            }
            if (preg_match_all("/(?:dispatchEvent|triggerEvent|dispatch)\(\s*'(onAfter[A-Za-z]+)'/", $source, $m) > 0) {
                foreach ($m[1] as $name) {
                    $names[$name] = true;
                }
            }
        }
        $expect("§12 {$version} 分发点原文都在（{$relFile}）", count($hits), count(array_merge(...array_values($files))));
        $cmsEventNames[$version] = array_keys($names);
        sort($cmsEventNames[$version]);
    }
    // 两个裸名必须都在 CMS 自己分发的名字集合里 —— 这一条把入口类的字面量与真实分发点绑在
    // 一起：Joomla 改名 → 这里红 → 入口类的 getSubscribedEvents() 必须跟着改。
    $expect(
        '§12 入口类订阅的裸名事件名都在真实 CMS 的分发点里（5.2.2）',
        array_values(array_filter(['onAfterInitialise', 'onAfterRespond'], static fn (string $n): bool => in_array($n, $cmsEventNames['5.2.2'], true))),
        ['onAfterInitialise', 'onAfterRespond']
    );
    $expect(
        '§12 入口类订阅的裸名事件名都在真实 CMS 的分发点里（4.4.14）',
        array_values(array_filter(['onAfterInitialise', 'onAfterRespond'], static fn (string $n): bool => in_array($n, $cmsEventNames['4.4.14'], true))),
        ['onAfterInitialise', 'onAfterRespond']
    );
    $expect(
        '§12 入口类 getSubscribedEvents() 的键与上面两个裸名一致',
        array_keys(\ErikWang2013\Xhprof\Joomla\Extension\Xhprof::getSubscribedEvents()),
        ['onAfterInitialise', 'onAfterRespond']
    );

    // ---- 扩展门控的自检（声明数 ≠ 实际跑数就是漂了）与合并 ----
    if ($ext && $extChecks !== JOOMLA_EXT_CHECKS_DECLARED) {
        $failures[] = '扩展相关检查数漂移：实际跑了 ' . $extChecks . ' 条，声明 ' . JOOMLA_EXT_CHECKS_DECLARED
            . ' 条（声明值决定缺扩展时记多少 skip）';
        $checks++;
    }
    if ($redis && $redisChecks !== JOOMLA_REDIS_CHECKS_DECLARED) {
        $failures[] = 'ext-redis 相关检查数漂移：实际跑了 ' . $redisChecks . ' 条，声明 ' . JOOMLA_REDIS_CHECKS_DECLARED
            . ' 条（声明值决定缺扩展时记多少 skip）';
        $checks++;
    }
    $checks += $extChecks + $redisChecks;
    foreach (array_merge($extFailures, $redisFailures) as $f) {
        $failures[] = $f;
    }

    // 收尾：临时站点根不留垃圾
    @unlink($siteRoot . '/xhprof.php');
    @rmdir($siteRoot);

    $_SERVER = $savedServer;
    $_REQUEST = $savedRequest;
    $_GET = $savedGet;
    $_POST = $savedPost;

    // ================= 结论 =================

    $note = 'ext-xhprof ' . ($ext ? '已加载（采样类断言真跑）' : '**未加载**（采样类断言计入 skips，非静默通过）');
    if ($extras !== []) {
        $note .= '；桩比真实类更窄（方向安全，仅记录）：' . implode('、', array_keys($extras));
    }

    if ($failures !== []) {
        return [
            'status' => 'FAIL',
            'detail' => $note . ' —— 共 ' . $checks . ' 项断言，' . count($failures) . ' 项失败：'
                . implode(' | ', array_slice($failures, 0, 6))
                . (count($failures) > 6 ? ' | …另有 ' . (count($failures) - 6) . ' 项' : ''),
            'skips' => $skips,
        ];
    }

    $verifiable = count(JOOMLA_MEMBERS_VERIFIABLE);
    $cmsVerifiable = count(JOOMLA_CMS_VERIFIABLE);

    return [
        'status' => 'PASS',
        'detail' => $note . '；' . $phpFiles . ' 个源文件语法通过；'
            . '源码用到的 ' . count(JOOMLA_MEMBERS) . ' 个框架成员与冻结清单双向相等'
            . '（可安装侧 ' . $verifiable . ' 个、CMS 侧 ' . $cmsVerifiable . ' 个）；'
            . '两个真实 CMS 发布包（5.2.2 / 4.4.14，composer 钉死版本）全程参与：'
            . '可安装侧签名与常量逐字段一致，CMS 侧的桩与 5.2.2 差异 0 条、与 4.4.14 恰好 1 条'
            . '（4.4 的 CMSPlugin::__construct 首参按引用 —— 已冻结并解释）；'
            . '真 SiteApplication + 真 CMSPlugin + 真事件类跑了 4 模式 × 2 版本共 8 个请求'
            . '（报告页/鉴权失败/静态资源/常开采样，真 exit、真响应头、真落库）；'
            . '真 Joomla\CMS\Log\Log 上验通本包 LogAdapter，provider 在真容器上 register() 成功；'
            . '本文件 ' . $checks . ' 项断言全部通过。'
            . ' 仍然不可验证的只剩 2 条（都在干净子进程里量到了抛点，不是散文）：'
            . ' [SKIP A] 插件参数的真实读取路径：`#__extensions.params` 要数据库与已启动的 CMS ——'
            . ' 子进程里三条路径（容器取 PluginInterface / Factory::getApplication /'
            . ' PluginHelper::getPlugin）逐字都是 `Exception: Failed to start application`'
            . '（Factory.php:158，两个版本同）；SQLite 近路走不通（DatabaseInterface 是容器的'
            . ' protected key，且 CMS 自己还要 SessionInterface；真 sqlite 驱动 + 真 #__extensions'
            . ' 行当正对照，证明这不是夹具没搭好）。'
            . ' [SKIP B] 安装器形态（namespacemap 被写过、bootPlugin 找得到类）：'
            . ' 要真安装器写 namespacemap —— 目录按装好的样子摆齐后 bootPlugin 仍拿不到插件'
            . '（provider.php 当场 `Class "Joomla\Plugin\System\Xhprof\Extension\Xhprof" not found`；'
            . '§11 另静态钉住机制 + 容器协议的实测抛点）。',
        'skips' => $skips,
    ];
};

/**
 * 扫出一段 PHP 源码里用到的**框架成员**（`接收者:成员` => `文件:行`）。
 *
 * 接收者取「`->` / `::` 左边那个词的末段」：`$this->input->get()` 里 `get` 的接收者是
 * `input`，`$input->get()` 也是 `input`，`$server->get()` 是 `server`，
 * `UriHelper::parse_url()` 是 `UriHelper`。只认传进来的那几个接收者名字，其余不看
 * （PHP 内置类、本包自己的局部对象等）。
 *
 * @param list<string> $receivers       变量接收者（取变量名去掉 $）
 * @param list<string> $staticReceivers 类名接收者（取末段，含 `parent`）
 *
 * @return array<string, string>
 */
function joomla_used_members(string $code, string $file, array $receivers, array $staticReceivers): array
{
    $tokens = token_get_all($code);
    $transparent = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ATTRIBUTE];
    $found = [];
    $n = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        $token = $tokens[$i];
        $isOp = is_array($token) && $token[0] === T_OBJECT_OPERATOR;
        $isStaticOp = is_array($token) && $token[0] === T_DOUBLE_COLON;
        $isNew = is_array($token) && $token[0] === T_NEW;

        if (!$isOp && !$isStaticOp && !$isNew) {
            continue;
        }

        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], $transparent, true)) {
            $j++;
        }
        $k = $i - 1;
        while ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], $transparent, true)) {
            $k--;
        }

        $next = $tokens[$j] ?? null;
        $prev = $tokens[$k] ?? null;

        if ($isNew) {
            // new Registry(...) 也是「用到 Registry 的成员」
            if (is_array($next) && $next[0] === T_STRING && in_array($next[1], $staticReceivers, true)) {
                $found[$next[1] . ':__construct'] = $file . ':' . $token[2];
            }
            continue;
        }

        if (!is_array($next) || $next[0] !== T_STRING) {
            continue;   // `->{...}` 动态成员、`::class`
        }
        if (is_array($prev) && $prev[0] === T_STRING && strtolower($prev[1]) === 'class') {
            continue;   // Foo::class
        }

        if ($isStaticOp) {
            $className = null;
            if (is_array($prev) && ($prev[0] === T_STRING || $prev[0] === T_NAME_QUALIFIED)) {
                $parts = explode('\\', $prev[1]);
                $className = end($parts);
            }
            if ($className !== null && in_array($className, $staticReceivers, true)) {
                $found[$className . ':' . $next[1]] = $file . ':' . $token[2];
            }
            continue;
        }

        $receiver = null;
        if (is_array($prev) && $prev[0] === T_STRING) {
            $receiver = $prev[1];                        // $this->input->get()
        } elseif (is_array($prev) && $prev[0] === T_VARIABLE) {
            $receiver = ltrim($prev[1], '$');             // $server->get()
        }
        if ($receiver !== null && in_array($receiver, $receivers, true)) {
            $found[$receiver . ':' . $next[1]] = $file . ':' . $token[2];
        }
    }

    return $found;
}

/**
 * 反射快照器脚本（`php -r`，规格 JSON 从 $argv[1] 进）。
 *
 * 与 lib/dump.php 同一口径（kind / constants / methods，参数字段逐字段），差别只有两点：
 *  - 支持 class（lib/dump.php 只做 interface）；
 *  - 只 dump 冻结清单点名的成员 —— 框架类的公共面比我们用到的宽得多，全量比会把
 *    「我们用不到的成员」也变成失败源。
 * 调用方负责在脚本前面拼 `require <bootstrap>;`，两边（桩 / 真实包）共用这一份脚本，
 * 差异报告才分得清「签名不同」与「dump 方式不同」。
 */
function joomla_dump_script(): string
{
    return <<<'PHP'
$spec = json_decode($argv[1] ?? '[]', true);
$out = ['missing' => [], 'types' => []];

foreach ($spec as $fqn => $members) {
    if (!class_exists($fqn) && !interface_exists($fqn)) {
        $out['missing'][] = $fqn;
        continue;
    }

    $rc = new ReflectionClass($fqn);

    $methods = [];
    $files = [];
    foreach ($members as $member) {
        if (!$rc->hasMethod($member)) {
            $out['missing'][] = $fqn . '::' . $member;
            continue;
        }
        $m = $rc->getMethod($member);
        $files[$member] = basename((string) $m->getFileName());
        $params = [];
        foreach ($m->getParameters() as $p) {
            $params[] = [
                'name' => $p->getName(),
                'type' => $p->getType() === null ? null : (string) $p->getType(),
                'hasDefault' => $p->isDefaultValueAvailable(),
                'default' => $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : null,
                'byRef' => $p->isPassedByReference(),
                'variadic' => $p->isVariadic(),
            ];
        }
        $methods[$member] = [
            'return' => $m->getReturnType() === null ? null : (string) $m->getReturnType(),
            'visibility' => $m->isPublic() ? 'public' : ($m->isProtected() ? 'protected' : 'private'),
            'returnsRef' => $m->returnsReference(),
            'static' => $m->isStatic(),
            'abstract' => $m->isAbstract(),
            'params' => $params,
        ];
    }
    ksort($methods);

    // 只比**公开**常量：桩、真实类各自怎么组织内部实现是私事（我们的 UriHelper 桩把
    // 保留字符表提成了 private const，真实类内联成局部变量 —— 调用方完全观测不到差别），
    // 而公开常量是接口面的一部分，少一个/多一个都算桩撒谎。
    $constants = [];
    foreach ($rc->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $c) {
        $constants[$c->getName()] = var_export($c->getValue(), true);
    }
    ksort($constants);

    $extends = $rc->getInterfaceNames();
    if ($rc->getParentClass() !== false) {
        $extends[] = $rc->getParentClass()->getName();
    }
    sort($extends);

    $out['types'][$fqn] = [
        'kind' => $rc->isInterface() ? 'interface' : 'class',
        'abstract' => $rc->isAbstract(),
        'extends' => $extends,
        'constants' => $constants,
        'methods' => $methods,
        'files' => $files,
    ];
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
PHP;
}

/**
 * 逐字段递归比两份快照，差异写进 &$out（形如 `方法.get.params.0.type: 桩="string" 真实="int"`）。
 *
 * 不复用 cases/Psr7.php 里的 contracts_diff()：case 是一个个**独立子进程**跑的
 * （case-runner 只 require 本文件），Psr7.php 那边不会加载，跨 case 互相 require
 * 会把两个卡耦在一起。代价是这 20 行，换来的是本卡自足。
 *
 * @param array<mixed> $a
 * @param array<mixed> $b
 * @param list<string> $out
 */
function joomla_member_diff(array $a, array $b, string $path, array &$out): void
{
    foreach ($a as $key => $value) {
        $where = $path . '.' . $key;
        if (!array_key_exists($key, $b)) {
            $out[] = "{$where}: 只在桩里存在";
            continue;
        }
        if (is_array($value)) {
            joomla_member_diff($value, $b[$key], $where, $out);
        } elseif ($value !== $b[$key]) {
            $out[] = "{$where}: 桩=" . json_encode($value, JSON_UNESCAPED_SLASHES)
                . ' 真实=' . json_encode($b[$key], JSON_UNESCAPED_SLASHES);
        }
    }
    foreach ($b as $key => $value) {
        if (!array_key_exists($key, $a)) {
            $out[] = $path . '.' . $key . ': 只在真实包里存在';
        }
    }
}

/**
 * §3(B) 探针：在**干净子进程**里只装这一个 CMS 包，量四件事 ——
 *  (1) 包自己的 autoloader 看不看得见 Joomla\CMS\* 触碰点；
 *  (2) JOOMLA_CMS_ABSENT 确实不存在（入口类「事件名用字面量」的前提）；
 *  (3) 载入的确实是清单里那个包目录、版本号对得上；
 *  (4) bootstrap.php 里 JPATH_PLATFORM 的用法（4.4 当**路径**拼 loader.php、5.x 只定义不用）
 *      —— 两个世代的结构差异，也防两个包被换成同一份内容还不自知。
 *
 * CMS 的类文件里有 `defined('_JEXEC') or die;`，常量必须在 require autoload **之前**定义，
 * 否则子进程静默 die（exit 0 + 空 stdout）—— 调用方把「没 JSON」当响亮 FAIL 处理。
 *
 * argv: [1]=libraries/vendor/autoload.php [2]=触碰点 JSON [3]=期望不存在的类
 */
function joomla_cms_probe_script(): string
{
    return <<<'PHP'
$autoload = $argv[1];
$touchpoints = json_decode($argv[2], true);
$absent = $argv[3];

$packageDir = null;
$split = explode('/vendor/', $autoload, 2);
if (isset($split[1])) {
    $rest = explode('/', $split[1]);
    $packageDir = ($rest[0] ?? '?') . '/' . ($rest[1] ?? '?');
}
$libraries = dirname(dirname($autoload));

define('_JEXEC', 1);
define('JPATH_ROOT', sys_get_temp_dir());
define('JPATH_SITE', sys_get_temp_dir());
define('JPATH_ADMINISTRATOR', sys_get_temp_dir() . '/administrator');
define('JPATH_PLUGINS', sys_get_temp_dir() . '/plugins');
define('JPATH_BASE', sys_get_temp_dir());
define('JPATH_LIBRARIES', $libraries);
define('JPATH_PLATFORM', 1);

require $autoload;

$present = [];
foreach ($touchpoints as $fqn) {
    $present[$fqn] = class_exists($fqn) || interface_exists($fqn);
}

$bootstrap = $libraries . '/bootstrap.php';
$bootstrapSource = is_file($bootstrap) ? (string) file_get_contents($bootstrap) : '';

echo json_encode([
    'package_dir' => $packageDir,
    'version' => Joomla\CMS\Version::MAJOR_VERSION . '.' . Joomla\CMS\Version::MINOR_VERSION . '.' . Joomla\CMS\Version::PATCH_VERSION,
    'present' => $present,
    'absent' => class_exists($absent) || interface_exists($absent),
    // 「当路径用」= 把常量拼进字符串（`JPATH_PLATFORM . '/loader.php'`），不是只看有没有定义
    'bootstrap_uses_platform_as_path' => str_contains($bootstrapSource, "JPATH_PLATFORM . '"),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
PHP;
}

/**
 * 给子进程用的引子：先定义 CMS 类文件要的 JPATH_* / _JEXEC，再 require 真实包的 autoloader。
 *
 * `JPATH_PLATFORM` 固定给 1：这里只**加载类**，不跑 bootstrap.php；4.4 的 bootstrap.php 会
 * 把它当路径拼 loader.php（§3B 的 bootstrap_uses_platform_as_path 钉的就是这件事），
 * 但那一步在这段引子里不会执行 —— 真正在 4.4 上跑完整 bootstrap 要 configuration.php。
 */
function joomla_cms_boot_script(string $autoloadPath, string $librariesDir, string $root): string
{
    return "define('_JEXEC', 1);\n"
        . "define('JPATH_ROOT', " . var_export($root, true) . ");\n"
        . "define('JPATH_SITE', " . var_export($root, true) . ");\n"
        . "define('JPATH_ADMINISTRATOR', " . var_export($root . '/administrator', true) . ");\n"
        . "define('JPATH_PLUGINS', " . var_export($root . '/plugins', true) . ");\n"
        . "define('JPATH_BASE', " . var_export($root, true) . ");\n"
        . "define('JPATH_LIBRARIES', " . var_export($librariesDir, true) . ");\n"
        . "define('JPATH_PLATFORM', 1);\n"
        . 'require ' . var_export($autoloadPath, true) . ";\n";
}

/** 给子进程用的引子：本包 `ErikWang2013\Xhprof\*` → 仓库 `src/` 的自注册（环的 autoloader 不管 src）。 */
function joomla_repo_autoload_script(string $repoRoot): string
{
    return '$repoRoot = ' . var_export($repoRoot, true) . ";\n"
        . <<<'PHP'
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $prefix = 'ErikWang2013\\Xhprof\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $repoRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
PHP
        . "\n";
}

/**
 * 型别包含：`$inner ⊆ $outer`（联合型别要拆开看，`?T` 归一成 `T|null`，`mixed` 是全集）。
 * 桩与真实包之间的型别差异只允许**这个方向**成立。
 */
function joomla_type_subset(string $inner, string $outer): bool
{
    $norm = static function (string $type): array {
        $type = trim($type);
        $nullable = str_starts_with($type, '?');
        if ($nullable) {
            $type = substr($type, 1);
        }
        $atoms = array_map('trim', explode('|', $type));
        if ($nullable) {
            $atoms[] = 'null';
        }
        sort($atoms);
        return $atoms;
    };

    $a = $norm($inner);
    $b = $norm($outer);
    if (in_array('mixed', $b, true)) {
        return true;
    }
    if (in_array('mixed', $a, true)) {
        return false;
    }
    return array_diff($a, $b) === [];
}

/**
 * 方向比较：**桩可以更窄，不许更宽**（Drupal 卡立的规矩，这里同样适用）。
 *
 * - 桩有、真实没有（常量/方法/接口）→ 差异（桩更宽 = 单测会依赖上生产里不存在的能力）
 * - 真实有、桩没有 → 备注（桩更窄，方向安全）
 * - 参数型别：桩 ⊆ 真实；返回型别：真实 ⊆ 桩（桩的返回必须是真实返回的超集）
 * - 常量值、可见性、static、按引用、必填/默认值：精确相等
 * - JOOMLA_CMS_REAL_MISSING 里那条（合成项）在这里跳过 —— 由「缺失集精确相等」单独钉
 *
 * 差异串形如 `FQN::member.p0.type`、`FQN::CONST.constant`、`FQN.extends.\Foo`，
 * 与 JOOMLA_CMS_EXPECTED_DIFFS 里冻结的写法一一对应。
 *
 * @param array<mixed>  $stub
 * @param array<mixed>  $real
 * @param list<string>  $diffs
 * @param list<string>  $notes
 */
function joomla_cms_compare(array $stub, array $real, string $fqn, array $realMissing, array &$diffs, array &$notes): void
{
    if ($stub['kind'] !== $real['kind']) {
        $diffs[] = "{$fqn}.kind";
    }
    if ($stub['kind'] === 'class' && $stub['abstract'] !== $real['abstract'] && !$stub['abstract']) {
        // 桩非抽象而真实抽象：单测能 new 出生产里不该能 new 的东西 = 桩更宽
        $diffs[] = "{$fqn}.abstract";
    } elseif ($stub['kind'] === 'class' && $stub['abstract'] !== $real['abstract']) {
        $notes[] = "{$fqn}：抽象性 桩=abstract 真实=concrete（桩更窄，只能少 new 不能多 new）";
    }

    foreach (array_diff($stub['extends'], $real['extends']) as $iface) {
        $diffs[] = "{$fqn}.extends.{$iface}";
    }
    foreach (array_diff($real['extends'], $stub['extends']) as $iface) {
        $notes[] = "{$fqn} 真实多出 {$iface}（桩更窄）";
    }

    foreach ($stub['constants'] as $name => $value) {
        if (!array_key_exists($name, $real['constants']) || $real['constants'][$name] !== $value) {
            $diffs[] = "{$fqn}::{$name}.constant";
        }
    }
    foreach (array_diff_key($real['constants'], $stub['constants']) as $name => $_value) {
        $notes[] = "{$fqn}::{$name} 真实有、桩未声明（桩更窄）";
    }

    foreach ($stub['methods'] as $name => $sm) {
        if (in_array("{$fqn}::{$name}", $realMissing, true)) {
            $notes[] = "{$fqn}::{$name}：合成项，由「缺失集精确相等」单独钉";
            continue;
        }
        if (!isset($real['methods'][$name])) {
            $diffs[] = "{$fqn}::{$name}.missing";
            continue;
        }
        $rm = $real['methods'][$name];

        foreach (['visibility', 'static', 'returnsRef'] as $attr) {
            if ($sm[$attr] !== $rm[$attr]) {
                $diffs[] = "{$fqn}::{$name}.{$attr}";
            }
        }
        if (count($sm['params']) > count($rm['params'])) {
            $diffs[] = "{$fqn}::{$name}.params(count)";
        } else {
            foreach ($sm['params'] as $i => $sp) {
                $rp = $rm['params'][$i];
                foreach (['variadic', 'byRef'] as $attr) {
                    if ($sp[$attr] !== $rp[$attr]) {
                        $diffs[] = "{$fqn}::{$name}.p{$i}.{$attr}";
                    }
                }
                if ($sp['type'] !== $rp['type']) {
                    if ($rp['type'] === null) {
                        // 真实侧无型别 = 最宽：桩写了型别只是更窄（调用方传什么真实都收），记备注不报差异
                        $notes[] = "{$fqn}::{$name}.p{$i}：型别 桩=" . var_export($sp['type'], true)
                            . ' 真实无型别（最宽，桩更窄）';
                    } elseif ($sp['type'] === null || !joomla_type_subset($sp['type'], $rp['type'])) {
                        // 桩无型别（更宽：真实会拒绝的参数桩照收）或桩的型别不是真实的子集
                        $diffs[] = "{$fqn}::{$name}.p{$i}.type";
                    }
                }
                if ($sp['hasDefault'] && !$rp['hasDefault']) {
                    $diffs[] = "{$fqn}::{$name}.p{$i}.required";
                }
                if ($sp['hasDefault'] && $rp['hasDefault'] && $sp['default'] !== $rp['default']) {
                    $diffs[] = "{$fqn}::{$name}.p{$i}.default";
                }
                if ($sp['name'] !== $rp['name']) {
                    $notes[] = "{$fqn}::{$name}.p{$i}：参数名 桩={$sp['name']} 真实={$rp['name']}（只按位置传参则无影响）";
                }
            }
            if (count($rm['params']) > count($sm['params'])) {
                $notes[] = "{$fqn}::{$name}：真实多出 " . (count($rm['params']) - count($sm['params'])) . ' 个可选参数（桩更窄）';
            }
        }
        if ($sm['return'] !== $rm['return']
            && !($rm['return'] === null)
            && !($sm['return'] !== null && joomla_type_subset($rm['return'], $sm['return']))) {
            $diffs[] = "{$fqn}::{$name}.return";
        } elseif ($sm['return'] !== $rm['return']) {
            $notes[] = "{$fqn}::{$name}：返回 桩=" . var_export($sm['return'], true)
                . ' 真实=' . var_export($rm['return'], true) . '（真实无型别 = 最宽，桩只能更窄）';
        }
    }
    foreach (array_diff_key($real['methods'], $stub['methods']) as $name => $_rm) {
        $notes[] = "{$fqn}::{$name} 真实有、桩未声明（桩更窄）";
    }
}

/**
 * 从子进程 stderr 里取观测行（`OBS {json}`）。stderr 里可能有别的噪声（PHP 警告），逐行找。
 *
 * @return array<string, mixed>|null
 */
function joomla_e2e_observe(string $stderr): ?array
{
    foreach (explode("\n", $stderr) as $line) {
        if (str_starts_with($line, 'OBS ')) {
            $decoded = json_decode(substr($line, 4), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    return null;
}

/**
 * §9 端到端探针：真 SiteApplication + 真 CMSPlugin + 真事件类 + 真 Dispatcher + 本包入口类，
 * 按请求类型跑四个模式，观测响应正文/响应头/落库/监听器/事件对象。
 *
 * 观测走 stderr 的 `OBS {json}` 行：正文（stdout）必须是**插件发出去的东西**本身，
 * 观测不能混进去。观测器最后注册，PHP 按注册顺序跑 shutdown，所以它一定在插件的
 * onAfterRespond（停采样 + 落库）之后跑。
 *
 * argv: [1]=CMS 包目录 [2]=仓库根 [3]=站点根 [4]=模式（report/deny/assets/sample）
 */
function joomla_e2e_script(): string
{
    return <<<'PHP'
$cmsDir = $argv[1];
$repoRoot = $argv[2];
$siteRoot = $argv[3];
$mode = $argv[4] ?? 'report';

define('_JEXEC', 1);
define('JPATH_PLATFORM', 1);
define('JPATH_ROOT', $siteRoot);
define('JPATH_SITE', $siteRoot);
define('JPATH_ADMINISTRATOR', $siteRoot . '/administrator');
define('JPATH_PLUGINS', $siteRoot . '/plugins');
define('JPATH_BASE', $siteRoot);
define('JPATH_LIBRARIES', $cmsDir . '/libraries');

require $cmsDir . '/libraries/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $prefix = 'ErikWang2013\\Xhprof\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $repoRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
require $repoRoot . '/tests/Fixtures/Fakes.php';

/** 采样标记：证明落库的数据覆盖到本次请求里真的跑过的代码（与卡里那个同名同形） */
function xhprof_joomla_marker(): int
{
    return 42;
}

$obs = [
    'mode' => $mode,
    'dispatched' => [],
    'cache_runs' => [],
    'run_has_main' => null,
    'run_covers_marker' => null,
];

$container = new Joomla\DI\Container();
$dispatcher = new Joomla\Event\Dispatcher();
$container->set(Joomla\Event\DispatcherInterface::class, $dispatcher);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_REQUEST = [];

if ($mode === 'report') {
    file_put_contents($siteRoot . '/xhprof.php', "<?php\n\nreturn ['enable' => false, 'auth_token' => 'tok', 'locale' => 'zh_CN'];\n");
    $_SERVER['REQUEST_URI'] = '/xhprof?token=tok';
    $_REQUEST = ['token' => 'tok'];
} elseif ($mode === 'deny') {
    file_put_contents($siteRoot . '/xhprof.php', "<?php\n\nreturn ['enable' => false, 'auth_token' => 'tok', 'locale' => 'zh_CN'];\n");
    $_SERVER['REQUEST_URI'] = '/xhprof?token=wrong';
    $_REQUEST = ['token' => 'wrong'];
} elseif ($mode === 'assets') {
    file_put_contents($siteRoot . '/xhprof.php', "<?php\n\nreturn ['enable' => false];\n");
    $_SERVER['REQUEST_URI'] = '/xhprof-assets/css/xhprof.css';
} elseif ($mode === 'sample') {
    file_put_contents($siteRoot . '/xhprof.php', "<?php\n\nreturn ['enable' => true];\n");
    $_SERVER['REQUEST_URI'] = '/index.php';
} else {
    file_put_contents($siteRoot . '/xhprof.php', "<?php\n\nreturn ['enable' => false];\n");
    $_SERVER['REQUEST_URI'] = '/index.php';
}

$config = new Joomla\Registry\Registry(['session' => false, 'debug' => false, 'sitename' => 'contracts', 'MetaDesc' => '']);
$app = new Joomla\CMS\Application\SiteApplication(null, $config, null, $container);
$app->setDispatcher($dispatcher);
$obs['input_class'] = get_class($app->getInput());

// 观测器：事件真的派发了几次、派发的对象是什么类。先于插件注册，所以计数涵盖插件的监听器在内
$dispatcher->addListener('onAfterInitialise', static function ($e = null) use (&$obs): void {
    $obs['dispatched'][] = 'onAfterInitialise:' . (is_object($e) ? get_class($e) : gettype($e));
});

$cache = new ErikWang2013\Xhprof\Tests\Fixtures\FakeCache();
$plugin = new ErikWang2013\Xhprof\Joomla\Extension\Xhprof($dispatcher, [], $cache, null);
$obs['plugin_parent'] = get_parent_class($plugin);
$obs['plugin_is_cms_plugin'] = $plugin instanceof Joomla\CMS\Plugin\CMSPlugin;
$obs['dispatcher_identity'] = $plugin->getDispatcher() === $dispatcher;
// getApplication() 是 protected（真实 CMSPlugin 如此，桩亦如此）—— 观测只能走反射
$getApp = new ReflectionMethod($plugin, 'getApplication');
$getApp->setAccessible(true);
$obs['get_application_visibility'] = $getApp->isProtected() ? 'protected' : ($getApp->isPublic() ? 'public' : 'private');
$obs['application_before_set'] = $getApp->invoke($plugin) === null;
$plugin->setApplication($app);
$obs['application_after_set_is_app'] = $getApp->invoke($plugin) === $app;

$dispatcher->addListener('onAfterRespond', static function ($e = null) use (&$obs): void {
    $obs['dispatched'][] = 'onAfterRespond:' . (is_object($e) ? get_class($e) : gettype($e));
});
$obs['listener_count_before'] = count($dispatcher->getListeners('onAfterInitialise'));
$plugin->registerListeners();
$obs['listener_count_initialise'] = count($dispatcher->getListeners('onAfterInitialise'));
$obs['listener_count_respond'] = count($dispatcher->getListeners('onAfterRespond'));

register_shutdown_function(static function () use (&$obs, $app, $cache): void {
    $obs['headers'] = [];
    foreach ($app->getHeaders() as $h) {
        $obs['headers'][] = $h['name'] . ': ' . $h['value'];
    }
    $obs['cache_runs'] = $cache->lRange('xhprof:run_id', 0, -1);
    if ($obs['cache_runs'] !== []) {
        $data = (array) unserialize((string) $cache->get('xhprof:xhprof_log:' . $obs['cache_runs'][0]));
        $obs['run_has_main'] = array_key_exists('main()', $data);
        $obs['run_covers_marker'] = false;
        foreach (array_keys($data) as $key) {
            if (str_contains((string) $key, 'xhprof_joomla_marker')) {
                $obs['run_covers_marker'] = true;
                break;
            }
        }
    }
    $obs['on_after_respond_count'] = count(array_filter(
        $obs['dispatched'],
        static fn (string $d): bool => str_starts_with($d, 'onAfterRespond:')
    ));
    $obs['has_method'] = array_values(array_filter(
        ['setHeader', 'sendHeaders', 'close', 'getInput'],
        static fn (string $m): bool => method_exists($app, $m)
    ));
    fwrite(STDERR, "OBS " . json_encode($obs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
});

// 真实分发。4.4：CMSApplication.php:745 是 `$this->triggerEvent('onAfterInitialise')`，事件对象
// 是通用的 Joomla\Event\Event；5.x：CMSApplication.php:766-769 是
// `$this->dispatchEvent('onAfterInitialise', new AfterInitialiseEvent('onAfterInitialise', ['subject' => $this]))`。
// 5.x 的 triggerEvent($name) 不带事件对象会在 ApplicationEvent::__construct 里因缺 subject 抛
// BadMethodCallException（实测），所以这里按版本各走各的真形状。
$event5 = class_exists('Joomla\CMS\Event\Application\AfterInitialiseEvent');
if ($event5) {
    $app->triggerEvent('onAfterInitialise', new Joomla\CMS\Event\Application\AfterInitialiseEvent('onAfterInitialise', ['subject' => $app]));
} else {
    $app->triggerEvent('onAfterInitialise');
}
// report/deny/assets 三个模式在这里已经被插件的 close() 掐断（真 exit），下面一行到不了
echo "AFTER-INITIALISE-DISPATCH\n";

// 采样窗口里跑过这段代码（采样模式下才可能到这儿）
if ($mode === 'sample') {
    xhprof_joomla_marker();
}

// 4.4 里 onAfterRespond 有两个分发点（CMSApplication.php:332 与 WebApplication.php:188），
// 同一请求里可能各来一次 —— 幂等守卫就是为它准备的。两个版本都连发两次。
for ($i = 0; $i < 2; $i++) {
    if ($event5) {
        $app->triggerEvent('onAfterRespond', new Joomla\CMS\Event\Application\AfterRespondEvent('onAfterRespond', ['subject' => $app]));
    } else {
        $app->triggerEvent('onAfterRespond');
    }
}
echo "AFTER-RESPOND-DISPATCH\n";
echo "END-OF-SCRIPT\n";
PHP;
}

/**
 * §10 真 Log 通路：用真实 Joomla\CMS\Log\Log 的**回调 logger** 收本包 LogAdapter 发出去的条目。
 * 桩里的 Log 只记数组，证明不了「位掩码级别 / 分类 / context 真的走到了真实 Log」。
 *
 * 引子（boot + 本包 autoload）由调用方拼在前面。
 */
function joomla_log_script(): string
{
    return <<<'PHP'
$received = [];
$argCount = 0;
Joomla\CMS\Log\Log::addLogger(
    [
        'logger' => 'callback',
        'callback' => static function (...$args) use (&$received, &$argCount): void {
            $argCount = count($args);
            $entry = $args[0] ?? null;
            $received = [
                'class' => is_object($entry) ? get_class($entry) : gettype($entry),
                'message' => is_object($entry) ? $entry->message : null,
                'priority' => is_object($entry) ? $entry->priority : null,
                'category' => is_object($entry) ? $entry->category : null,
                'context' => is_object($entry) ? $entry->context : null,
            ];
        },
    ],
    Joomla\CMS\Log\Log::ALL,
    ['xhprof']
);

$adapter = new ErikWang2013\Xhprof\Joomla\Adapter\LogAdapter();
$adapter->error('xhprof 契约：真 Log 通路', ['k' => 'v']);

echo json_encode(['arg_count' => $argCount, 'entry' => $received], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
PHP;
}

/**
 * §11c 探针：SKIP A 的「SQLite 近路」有界试探。真 sqlite 驱动建真 `#__extensions` 表 + 真 params 行，
 * 再看 `PluginHelper::getPlugin()` 能不能读出来 —— 各步抛点原样上报，不粉饰。
 * 最后一步把插件目录按**安装后的样子**摆一遍（manifest + services/provider.php）再 bootPlugin：
 * 拿到的是 `provider.php` 里那句「类不存在」，即「插件类只有装过之后才存在」的实测（SKIP B）。
 *
 * 它**不能**复用 joomla_cms_boot_script()：本探针要 require libraries/bootstrap.php，而那份文件
 * 会拿常量拼 loader.php 的真路径 —— 4.4 拼的是 `JPATH_PLATFORM`、5.x 拼的是 `JPATH_LIBRARIES`
 * （§3B 钉的世代差异），两个都得给真路径，给标记值就直接致命。
 *
 * argv: [1]=CMS 包目录 [2]=站点根 [3]=仓库根
 */
function joomla_sqlite_probe_script(): string
{
    return <<<'PHP'
$cmsDir = $argv[1];
$siteRoot = $argv[2];
$repoRoot = $argv[3];

define('_JEXEC', 1);
define('JPATH_ROOT', $siteRoot);
define('JPATH_SITE', $siteRoot);
define('JPATH_ADMINISTRATOR', $siteRoot . '/administrator');
define('JPATH_PLUGINS', $siteRoot . '/plugins');
define('JPATH_BASE', $siteRoot);
define('JPATH_CONFIGURATION', $siteRoot);
// 两个都给真路径：4.4 的 bootstrap 拼 JPATH_PLATFORM、5.x 的拼 JPATH_LIBRARIES
define('JPATH_PLATFORM', $cmsDir . '/libraries');
define('JPATH_LIBRARIES', $cmsDir . '/libraries');
define('JDEBUG', false);

require $cmsDir . '/libraries/vendor/autoload.php';
require_once $cmsDir . '/libraries/bootstrap.php';

$steps = [];
$step = static function (string $label, callable $fn) use (&$steps): void {
    $steps[$label] = ['ok' => false, 'error' => null, 'data' => null];
    try {
        $steps[$label]['data'] = $fn();
        $steps[$label]['ok'] = true;
    } catch (\Throwable $e) {
        $steps[$label]['error'] = get_class($e) . ': ' . $e->getMessage();
    }
};

$dbFile = $siteRoot . '/site.sqlite';
@unlink($dbFile);
$db = null;
$step('db', function () use (&$db, $dbFile) {
    $db = Joomla\Database\DatabaseDriver::getInstance(['driver' => 'sqlite', 'database' => $dbFile, 'prefix' => 'jos_']);
    $db->connect();
    $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__extensions') . ' (
        extension_id INTEGER PRIMARY KEY, name TEXT, type TEXT, element TEXT, folder TEXT,
        client_id INTEGER, enabled INTEGER, access INTEGER, protected INTEGER, state INTEGER,
        ordering INTEGER, params TEXT)')->execute();
    $params = json_encode(['enable' => true, 'auth_token' => 'db-token-from-extensions-params']);
    $db->setQuery('INSERT OR REPLACE INTO ' . $db->quoteName('#__extensions') . ' VALUES (1,' . $db->quote('xhprof') . ','
        . $db->quote('plugin') . ',' . $db->quote('xhprof') . ',' . $db->quote('system') . ',0,1,1,0,0,0,' . $db->quote($params) . ')')->execute();
    return count($db->setQuery('SELECT * FROM ' . $db->quoteName('#__extensions'))->loadObjectList());
});

$container = null;
$app = null;
$step('container', function () use (&$container, $db) {
    $container = Joomla\CMS\Factory::getContainer();
    $container->set(Joomla\Database\DatabaseInterface::class, $db);
    return true;
});
$step('app', function () use (&$app, $container, $siteRoot) {
    $config = new Joomla\Registry\Registry([
        'dbtype' => 'sqlite', 'db' => $siteRoot . '/site.sqlite', 'dbprefix' => 'jos_', 'host' => '', 'user' => '', 'password' => '',
        'session' => false, 'debug' => false, 'sitename' => 'contracts', 'MetaDesc' => '', 'caching' => 0, 'cache_handler' => 'file',
        'tmp_path' => $siteRoot, 'log_path' => $siteRoot, 'offset' => 'UTC', 'lifetime' => 15, 'secret' => 'x', 'live_site' => '',
        'gzip' => false, 'error_reporting' => 'none', 'sendmail' => false, 'mailfrom' => 'a@b.c', 'fromname' => 'c',
        'cookie_domain' => '', 'cookie_path' => '', 'force_ssl' => 0, 'shared_session' => false, 'session_handler' => 'none',
    ]);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'example.com';
    $_SERVER['REQUEST_URI'] = '/index.php';
    $app = new Joomla\CMS\Application\SiteApplication(null, $config, null, $container);
    Joomla\CMS\Factory::$application = $app;
    Joomla\CMS\Factory::$config = $config;
    Joomla\CMS\Factory::$container = $container;
    return get_class($app);
});
$step('user', static fn (): int => Joomla\CMS\Factory::getUser()->id);
$step('cache', static fn (): string => get_class(Joomla\CMS\Factory::getCache('com_plugins', 'callback')));
$step('pluginhelper', static function (): string {
    $plugin = Joomla\CMS\Plugin\PluginHelper::getPlugin('system', 'xhprof');
    return is_object($plugin) ? (string) json_encode($plugin->params) : var_export($plugin, true);
});
// 按**安装器摆好的样子**摆一遍（manifest + services/provider.php）再 bootPlugin。
// 只钉这一级：它抛在 provider 里（`require_once` 之后类名解析），不依赖任何缓存状态。
// （另一形态——只有 manifest、没有 services/——实测会静默退化成 Joomla 的 DummyPlugin 空替身；
//  那一级本探针不断言，因为同一进程里第二次 boot 会走 require_once/已加载缓存的旁路。）
$pluginDir = $siteRoot . '/plugins/system/xhprof';
@mkdir($pluginDir, 0777, true);
@symlink($repoRoot . '/joomla/xhprof.xml', $pluginDir . '/xhprof.xml');
@symlink($repoRoot . '/joomla/services', $pluginDir . '/services');
$step('bootInstalled', static function () use ($app, $repoRoot): string {
    try {
        return 'booted:' . get_class($app->bootPlugin('xhprof', 'system'));
    } catch (\Throwable $e) {
        // 抛点原样上报。文件按仓库根归一（provider.php 是别人维护的，只钉到文件、不钉行号；
        // 实测抛在 `$plugin = new Xhprof(` 那行：PHP 先解析 new 的类名再算实参，
        // 所以「类不存在」比后面 PluginHelper 的 session 抛点更早露面）。
        return get_class($e) . ': ' . $e->getMessage() . ' @ ' . str_replace($repoRoot . '/', '', $e->getFile());
    }
});

// 收尾：临时站点根里本探针造的东西全撤掉
@unlink($siteRoot . '/plugins/system/xhprof/xhprof.xml');
@unlink($siteRoot . '/plugins/system/xhprof/services');
@rmdir($siteRoot . '/plugins/system/xhprof');
@rmdir($siteRoot . '/plugins/system');
@rmdir($siteRoot . '/plugins');
@unlink($dbFile);

echo json_encode(['steps' => $steps], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
PHP;
}

/**
 * §11 provider 协议：在**真容器**上 register()，然后试着取服务 —— 取不出来就是 SKIP A/B 的理由。
 *
 * 量的是**两级**阻塞，两级都是实测、逐字断言：
 *   (1) 没装（没有安装器写的 namespacemap）：容器找不到 `Joomla\Plugin\System\Xhprof\Extension\Xhprof`
 *       —— 「插件类只有在装过之后才存在」，这是 SKIP B 的机器可核实理由；
 *   (2) 手工补上 namespacemap（把 `Joomla\Plugin\System\Xhprof\` 映到 joomla/src，等价于安装器
 *       生成的那份 map）：类找得到、就是我们入口类的别名，但服务仍取不出来 —— 三条路径
 *       （容器取 PluginInterface / Factory::getApplication / PluginHelper::getPlugin）
 *       逐字都是 `Exception: Failed to start application`（Factory.php:158），这是 SKIP A 的理由。
 *
 * argv: [1]=joomla/services/provider.php [2]=仓库根
 */
function joomla_provider_script(): string
{
    return <<<'PHP'
$providerPath = $argv[1];
$repoRoot = $argv[2];

// 本包 src/ 先自注册（别名文件里那句 class_exists 依赖它；也免得真去 require 站点根的 autoloader）
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $prefix = 'ErikWang2013\\Xhprof\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $repoRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$provider = require $providerPath;
$out = [
    'instanceof' => $provider instanceof Joomla\DI\ServiceProviderInterface,
    'registered' => false,
    'register_error' => null,
    'unmapped_error' => null,
    'mapped_is_ours' => null,
    'container_error' => null,
    'factory_error' => null,
    'pluginhelper_error' => null,
];

$container = new Joomla\DI\Container();
$container->set(Joomla\Event\DispatcherInterface::class, new Joomla\Event\Dispatcher());

try {
    $provider->register($container);
    $out['registered'] = true;
} catch (\Throwable $e) {
    $out['register_error'] = get_class($e) . ': ' . $e->getMessage();
}

// (1) 没装：插件类不存在
try {
    $container->get(Joomla\CMS\Extension\PluginInterface::class);
    $out['unmapped_error'] = false;
} catch (\Throwable $e) {
    $out['unmapped_error'] = get_class($e) . ': ' . $e->getMessage();
}

// (2) 等价于安装器写过的 namespacemap：Joomla\Plugin\System\Xhprof\ → plugins/system/xhprof/src
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $prefix = 'Joomla\\Plugin\\System\\Xhprof\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $repoRoot . '/joomla/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// 别名（class_alias）与真身是同一个类，反射报的是**真身**的类名 —— 这一条同时证明
// 「Joomla 惯例的类名能加载」与「加载到的是我们包里那个实现」，而不是某个同名的别的东西
$aliasName = 'Joomla\Plugin\System\Xhprof\Extension\Xhprof';
$out['mapped_is_ours'] = class_exists($aliasName)
    && (new ReflectionClass($aliasName))->getName() === ErikWang2013\Xhprof\Joomla\Extension\Xhprof::class;

try {
    $container->get(Joomla\CMS\Extension\PluginInterface::class);
    $out['container_error'] = false;
} catch (\Throwable $e) {
    $out['container_error'] = get_class($e) . ': ' . $e->getMessage();
}

try {
    Joomla\CMS\Factory::getApplication();
    $out['factory_error'] = false;
} catch (\Throwable $e) {
    $out['factory_error'] = get_class($e) . ': ' . $e->getMessage();
}

try {
    Joomla\CMS\Plugin\PluginHelper::getPlugin('system', 'xhprof');
    $out['pluginhelper_error'] = false;
} catch (\Throwable $e) {
    $out['pluginhelper_error'] = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
PHP;
}
