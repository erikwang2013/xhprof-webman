<?php

declare(strict_types=1);

/**
 * Joomla 契约卡（Joomla 4.4 / 5.x 系统插件）。
 *
 * 本卡的可验证面被环的依赖集**切成两半**，两半的结论必须分开放，不许混着说：
 *
 *  可安装（真 L0 + 真 L1 + 真 L2，用 composer 里的真实包）：
 *    joomla/input 3.0.x、joomla/registry 3.0.x、joomla/uri 3.0.x、joomla/event 3.0.x
 *    → Joomla\Input\Input、Joomla\Registry\Registry、Joomla\Uri\UriHelper、
 *      Joomla\Event\{Priority, Dispatcher, DispatcherInterface, EventInterface, SubscriberInterface}
 *
 *  不可安装（诚实 SKIP，理由在 §3 用干净子进程钉成可复核的事实）：
 *    `Joomla\CMS\*` 整个命名空间只存在于 CMS 仓库，没有任何 composer 包提供它。
 *
 * 覆盖手法（沿用 WordPress 卡确立的**扫描方向**）：
 *   1) 语法：src/Joomla/**、joomla/** 每个文件都要编译通过；
 *   2) 冻结清单 ↔ 源码扫描**双向相等**：源码里用到的每个框架成员都必须在清单里，
 *      清单里每条也必须在源码里真的用到。多一个 = 有人加了没核对过的调用；
 *      少一个 = 调用点被删了（或扫描器坏了，那也是坏消息）；
 *   3) L0 桩保真：tests/Stubs/Framework/Joomla.php 对**可安装**那几个类的声明
 *      （我们用到的那部分）与真实包逐字段一致 —— 单测全靠这份桩，桩漂了单测证明不了任何事。
 *      两个子进程各 dump 一次再比，dump 逻辑同一段代码（不是两套）；
 *   4) L2：用**真实** Input / Registry / UriHelper 跑 src/Joomla/Adapter/**，并用
 *      **真实** Dispatcher 真的 addSubscriber() + dispatch() 一次报告页请求。
 *
 * 采样类断言需要 ext-xhprof：本机与 ci.yml 有、contracts.yml 没有。缺扩展时它们
 * **计入 skips**（不伪装成通过），run.php 会因 skips ≠ 冻结期望而红 —— 这是故意的：
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
 * 冻结：JOOMLA_MEMBERS 里**能在环里真核对**的那部分。
 *
 * §4 会把它们逐个反射比对（真实包侧 vs 桩侧）；不在这个子集里的（`app:*`、`this:*`、
 * `parent:*`、`Log:*`、`PluginHelper:*`、`Factory:*`）都是 CMS 侧，理由见 §3 与 SKIP 1/2/4。
 */
const JOOMLA_MEMBERS_VERIFIABLE = [
    'input:get', 'input:getArray', 'input:getMethod', 'input:server',
    'server:exists', 'server:get',
    'registry:get', 'Registry:__construct',
    'UriHelper:parse_url',
    'Priority:MIN', 'Priority:NORMAL',
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
 * 冻结：CMS 侧的触碰点。环里这些类**必须装不上**——§3 会起一个只加载真实包的子进程
 * 逐个 `class_exists()` 复核。哪天它们中的任何一个能被装了（比如有人把 joomla/cms
 * 加进 composer.json），本 case 立刻 FAIL 要求把对应 SKIP 升级成真核对，
 * 而不是让一句可能已经过期的「装不上」永久躺在 SKIP 理由里。
 */
const JOOMLA_CMS_TOUCHPOINTS = [
    'Joomla\CMS\Plugin\CMSPlugin',
    'Joomla\CMS\Application\CMSApplicationInterface',
    'Joomla\CMS\Log\Log',
    'Joomla\CMS\Plugin\PluginHelper',
    'Joomla\CMS\Factory',
    // 这个类**不存在**是 getSubscribedEvents() 用字面量事件名的唯一原因（见入口类注释）
    'Joomla\CMS\Event\Application\ApplicationEvents',
];

/** 冻结：CMS 侧不可验证的子项数（每个都在 §6 的 detail 里逐条写明理由） */
const JOOMLA_SKIPS_DECLARED = 5;

/** 冻结：需要 ext-xhprof 的断言数（缺扩展时按这个数计入 skips，末尾自检漂移） */
const JOOMLA_EXT_CHECKS_DECLARED = 4;

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

    // ================= §3 CMS 侧「装不上」的前提必须在环里成立 =================
    // 这是 SKIP 1/2/4 的理由本身。理由若不成立（真能装上了），SKIP 就该升级成真核对。

    // 探针要问的 FQN **直接由 JOOMLA_CMS_TOUCHPOINTS 生成**，不在这儿手抄第二份：
    // 手抄副本会在清单改人名时静默漂移。回退验证 C5 就是这么抓出来的 —— 清单里把一个
    // 触碰点换成真装得上的类，探针却仍去问原来那些名字，于是照样 PASS，SKIP 理由成了摆设。
    // 'control' 是正对照：探针必须看得见一个**确实装得上**的类（真实包里的 Priority）。
    // 否则「CMS 全部 false」分不清是「CMS 装不上」还是「autoload 压根没加载成功」——
    // 后者会让整条 SKIP 理由变成假象（C10 就是把这个控制点改坏来验它真会红）。
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
            'detail' => 'CMS 前提探针没有给出预期 JSON（exit ' . $absent['code'] . '）：'
                . trim($absent['stderr'] !== '' ? $absent['stderr'] : $absent['stdout']),
            'skips' => 0,
        ];
    }
    if (($absentDecoded['control'] ?? false) !== true) {
        return [
            'status' => 'FAIL',
            'detail' => 'CMS 前提探针的**正对照**失败：连真实包里的 Joomla\Event\Priority 都看不见，'
                . '说明探针没真加载到 vendor/autoload.php —— 这种状态下「CMS 全部 class_exists=false」'
                . '是假象而不是事实，SKIP 1/2/4 的理由不成立，先修探针',
            'skips' => 0,
        ];
    }
    $installable = [];
    foreach (JOOMLA_CMS_TOUCHPOINTS as $i => $fqn) {
        if ($absentDecoded['cms'][$i] === true) {
            $installable[] = $fqn;
        }
    }
    if ($installable !== []) {
        return [
            'status' => 'FAIL',
            'detail' => '这些 CMS 类现在**装得上**了：' . implode('、', $installable)
                . ' —— 「环里没有 CMS、只能标 SKIP」的前提失效，请把 SKIP 1/2/4 升级成对真实 CMS 的核对'
                . '（或显式说明为何仍然不可验），不要留着过期的理由继续 SKIP',
            'skips' => 0,
        ];
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

    if ($ext && $extChecks !== JOOMLA_EXT_CHECKS_DECLARED) {
        $failures[] = '扩展相关检查数漂移：实际跑了 ' . $extChecks . ' 条，声明 ' . JOOMLA_EXT_CHECKS_DECLARED
            . ' 条（声明值决定缺扩展时记多少 skip）';
        $checks++;
    }
    $checks += $extChecks;
    foreach ($extFailures as $f) {
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

    return [
        'status' => 'PASS',
        'detail' => $note . '；' . $phpFiles . ' 个源文件语法通过；'
            . '源码用到的 ' . count(JOOMLA_MEMBERS) . ' 个框架成员与冻结清单双向相等，'
            . '其中 ' . $verifiable . ' 个可安装侧的成员在真实包里反射比对了签名与常量（桩逐字段一致）；'
            . '真实 Input/Registry/UriHelper/Dispatcher 驱动的 ' . $checks . ' 项 L2 断言全部通过。'
            . ' [SKIP 1] CMS 侧类（CMSPlugin / CMSApplicationInterface / Log / PluginHelper / Factory /'
            . ' ApplicationEvents 全部）：Joomla\CMS\* 只在 CMS 仓库里，没有任何 composer 包提供'
            . '（§3 用干净子进程实测 class_exists 全为 false），故 CMSPlugin 的构造器/'
            . 'getApplication/setApplication、setHeader/sendHeaders/close 的真实语义、Log 常量真值'
            . '只能用 tests/Stubs/Framework/Joomla.php 代跑，桩的忠实性无法在本环自证'
            . '（环里 close() 是被桩记下来的，真实 CMS 里它是 exit()）。'
            . ' [SKIP 2] CMS 的分发点本身：CMSApplication 用 \'onAfterInitialise\'/\'onAfterRespond\''
            . ' 这两个裸名分发（5.4-dev 源码 :813 / :347）无法在环里重放；本环只验到'
            . '「真实 Joomla\Event\Dispatcher 认这两个名字」，验不到「CMS 真的这么发」。'
            . ' [SKIP 3] 插件参数来源：#__extensions.params + PluginHelper::getPlugin() 要数据库与 CMS；'
            . '本卡因此改从包内 config/xhprof.php + 站点根 xhprof.php 读配置（README 里写明的取舍），'
            . '该取舍的代价（管理员无法在后台改配置）在环里量不出来。'
            . ' [SKIP 4] 插件安装形态：joomla/xhprof.xml 的 folder plugin="xhprof" 约定、'
            . 'services/provider.php 的 DI 注册与 Joomla 的自动加载，都要安装器/容器才能真跑；'
            . '本环只做了语法与结构核对。'
            . ' [SKIP 5] 4.4 与 5.x 的 CMSPlugin::__construct 差异（4.4：必填且按引用；'
            . '5.x：可省且 PluginHelper::import() 自注入）：两个版本都装不上，"一个签名两边都成立"'
            . '只能靠源码证据，跑不了。',
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
