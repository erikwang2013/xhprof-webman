<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeResponseFactory;
use ErikWang2013\Xhprof\Yii3\Adapter\ConfigAdapter as Yii3ConfigAdapter;
use ErikWang2013\Xhprof\Yii3\XhprofMiddleware as Yii3XhprofMiddleware;

/**
 * 十二份配置的**键集/默认值**对照（11 份 PHP + Drupal 的 yml）。
 *
 * 每条框架接入路径都自带一份 config/xhprof.php，没有共享层（见计划：刻意去中心化）。
 * 代价是键名/默认值可能悄悄走岔——用户在 A 框架里写 `'log_num' => 500` 生效，换到 B
 * 框架不生效，而两边都不报错。这里钉住：
 *   - 键集完全相同（含顺序）；
 *   - 每个键的默认值逐项相同（Drupal 是 YAML，形态不同、键与值相同）；
 *   - 13 份 README（zh 源 + 12 译文）配置表第一列的键名与代码键集**双向**逐字一致；
 *   - src/*\/config 下不能再冒出没被列进来的 xhprof 配置文件。
 *
 * 比对的是「解析后的数组」，不是文件字节：注释、空行、`86400 * 7` vs `604800`
 * 这类写法差异都不该让测试红。
 */
class ConfigParityTest extends TestCase
{
    /** @var list<string> 十九个键，顺序即配置文件的书写顺序 */
    private const EXPECTED_KEYS = [
        'enable',
        'sample_rate',
        'trigger_token',
        'auth_basic',
        'ip_allowlist',
        'trusted_proxies',
        'webhook_url',
        'sample_cli',
        'symbol_lookup_url',
        'max_runs_per_minute',
        'time_limit',
        'log_num',
        'view_wtred',
        'ignore_url_arr',
        'assets_url',
        'auth_token',
        'key_prefix',
        'log_ttl',
        'locale',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> 框架 => 相对路径（PHP 形态，可直接 require） */
    private function phpConfigFiles(): array
    {
        return [
            'Webman' => 'src/Webman/config/plugin/aaron-dev/xhprof/xhprof.php',
            'Laravel' => 'src/Laravel/config/xhprof.php',
            'Thinkphp' => 'src/Thinkphp/config/xhprof.php',
            'Hyperf' => 'src/Hyperf/config/xhprof.php',
            'Yii3' => 'src/Yii3/config/xhprof.php',
            'Symfony' => 'src/Symfony/config/xhprof.php',
            'Slim' => 'src/Slim/config/xhprof.php',
            'Wordpress' => 'src/Wordpress/config/xhprof.php',
            'Joomla' => 'src/Joomla/config/xhprof.php',
            'Native' => 'src/Native/config/xhprof.php',
            'Yii2' => 'src/Yii2/config/xhprof.php',
        ];
    }

    private const DRUPAL_YAML = 'drupal/xhprof/config/install/xhprof.settings.yml';

    /**
     * 只认「配置值文件」。src/Thinkphp/config/middleware.php 与
     * src/Webman/config/plugin/aaron-dev/xhprof/app.php 是注册文件（中间件/插件声明），
     * 不含配置项，故不参与键集比对。
     */
    private function discoverConfigFiles(): array
    {
        $root = $this->root();
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (preg_match('#^' . preg_quote($root, '#') . '/src/[^/]+/config/.*xhprof\.(php|yml|yaml)$#', $file->getPathname()) === 1) {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($found);

        return $found;
    }

    /** @return array<string, mixed> */
    private function loadPhp(string $relativePath): array
    {
        $config = require $this->root() . '/' . $relativePath;
        $this->assertIsArray($config, "{$relativePath} 必须 return 一个数组");

        return $config;
    }

    /**
     * Drupal 是 typed config（简化 YAML），形态与 PHP 数组不同：只取顶层 `key:` 行。
     * 嵌套项（ignore_url_arr 的列表元素）有缩进，不会命中这个正则。
     *
     * @return list<string>
     */
    private function drupalTopLevelKeys(string $relativePath): array
    {
        $keys = [];
        foreach (file($this->root() . '/' . $relativePath, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^([a-z_][a-z0-9_]*):/', $line, $m) === 1) {
                $keys[] = $m[1];
            }
        }

        return $keys;
    }

    /**
     * 把 YAML 里已知键的标量值取出来，转成与 PHP 数组同形的值。
     *
     * 解析失败**不会**静默通过：解析结果要与 PHP 侧逐项相同，猜错就会红。
     * 浮点（sample_rate）单独一条：PHP 侧是 float，`1` 或裸字符串对不上 assertSame。
     *
     * @return array<string, mixed>
     */
    private function drupalValues(string $relativePath): array
    {
        $values = [];
        $lines = file($this->root() . '/' . $relativePath, FILE_IGNORE_NEW_LINES);
        $current = null;
        foreach ($lines as $line) {
            if (preg_match('/^([a-z_][a-z0-9_]*):(.*)$/', $line, $m) === 1) {
                $current = $m[1];
                $raw = trim(preg_split('/\s+#/', $m[2])[0]);
                $values[$current] = $this->drupalScalar($raw);
                continue;
            }
            // 顶层的块序列：`  - 'x'`（ignore_url_arr 的值）
            if ($current !== null && preg_match('/^\s+-\s*(.+)$/', $line, $m) === 1) {
                if (!is_array($values[$current] ?? null)) {
                    $values[$current] = [];
                }
                $values[$current][] = $this->drupalScalar(trim(preg_split('/\s+#/', $m[1])[0]));
            }
        }

        return $values;
    }

    private function drupalScalar(string $raw): mixed
    {
        if ($raw === '' || $raw === 'null' || $raw === '~') {
            return null;
        }
        // 空序列的 YAML 流式写法（`ip_allowlist: []` / `trusted_proxies: []`）。
        // 空的块序列没法用 `key:` 加缩进项表达（那会解析成 null），Drupal 的 typed
        // config 也就这一种写法；漏掉它这两键会以字符串 '[]' 参与比对而假红。
        if ($raw === '[]') {
            return [];
        }
        if ($raw === 'true' || $raw === 'false') {
            return $raw === 'true';
        }
        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }
        if (preg_match('/^-?\d+\.\d+$/', $raw) === 1) {
            return (float) $raw;
        }
        if (preg_match("#^'(.*)'$#", $raw, $m) === 1 || preg_match('/^"(.*)"$/', $raw, $m) === 1) {
            return $m[1];
        }

        return $raw;
    }

    // ---------- 断言 ----------

    #[Test]
    public function everyListedConfigFileExistsAndLoads(): void
    {
        foreach ($this->phpConfigFiles() as $fw => $rel) {
            $this->assertFileExists($this->root() . '/' . $rel, "{$fw} 的配置文件不见了");
            $this->loadPhp($rel);
        }
        $this->assertFileExists($this->root() . '/' . self::DRUPAL_YAML);
    }

    #[Test]
    public function everyFrameworkConfigHasTheSameKeysInTheSameOrder(): void
    {
        $reference = null;
        foreach ($this->phpConfigFiles() as $fw => $rel) {
            $keys = array_keys($this->loadPhp($rel));
            $reference ??= $keys;
            $this->assertSame($reference, $keys, "{$fw} 的键集/顺序与其余框架不一致（比的是解析后的键，不是文件字节）");
        }

        $this->assertSame(
            $reference,
            $this->drupalTopLevelKeys(self::DRUPAL_YAML),
            'Drupal 的 xhprof.settings.yml 顶层键集/顺序与 PHP 侧不一致'
        );
    }

    #[Test]
    public function theSharedKeySetIsExactlyTheNineteenDocumentedKeys(): void
    {
        // 与上一条互补：上一条管「十二份彼此一致」，这条管「一致的确实是这十九个」——
        // 十二个配置文件被同一次改动一起加键时，只有这条会红。
        $this->assertSame(self::EXPECTED_KEYS, array_keys($this->loadPhp('src/Slim/config/xhprof.php')));
    }

    #[Test]
    public function everyFrameworkConfigHasTheSameDefaultsForEveryKey(): void
    {
        $reference = $this->loadPhp('src/Slim/config/xhprof.php');
        foreach ($this->phpConfigFiles() as $fw => $rel) {
            $this->assertSame($reference, $this->loadPhp($rel), "{$fw} 的默认值与其他框架不一致（值不一致，用户换了框架行为就变了）");
        }

        // Drupal 侧按解析结果比对：`604800` 与 `86400 * 7` 是同一个值，不该红。
        $this->assertSame($reference, $this->drupalValues(self::DRUPAL_YAML), 'Drupal 的默认值与其他框架不一致');
    }

    #[Test]
    public function noUndiscoveredXhprofConfigFileExistsUnderAnyFramework(): void
    {
        $listed = array_values($this->phpConfigFiles());
        sort($listed);
        $this->assertSame(
            $listed,
            $this->discoverConfigFiles(),
            'src/*/config 下出现了未列入本测试的 xhprof 配置文件：新框架的配置没有参与键集比对'
            . '（新框架请加进 phpConfigFiles()）。'
        );
    }

    #[Test]
    public function yii3RedisIsRuntimeInjectedNotAKeyOfTheShippedConfigFile(): void
    {
        // README「Yii3」一节的 DI 片段把 redis 写在**用户传入**的 config 里；
        // 包内默认配置文件里没有它（键集仍是那十九个）。两者并不矛盾，这里钉住这个区别。
        $shipped = $this->loadPhp('src/Yii3/config/xhprof.php');
        $this->assertArrayNotHasKey('redis', $shipped, 'Yii3 的默认配置文件不该出现 redis（README 说的是运行时注入）');
        $this->assertSame(self::EXPECTED_KEYS, array_keys($shipped));

        // 用户按 README 注入后：array_replace 整段合并 → 合并结果里多出 redis，
        // 且中间件的取值路径 `get('xhprof.redis', [])`（src/Yii3/XhprofMiddleware.php:71）能取到它。
        $adapter = new Yii3ConfigAdapter(['redis' => ['host' => '127.0.0.1', 'port' => 6379]]);
        $this->assertSame(['host' => '127.0.0.1', 'port' => 6379], $adapter->get('xhprof.redis'), '注入的 redis 子数组必须能被取到');
        $block = $adapter->get('xhprof');
        $this->assertIsArray($block);
        $this->assertArrayHasKey('redis', $block, '合并后的 xhprof 整块里应当含 redis');
        $this->assertCount(count(self::EXPECTED_KEYS) + 1, $block, '注入 redis 后整块应比默认多且仅多一个键');

        // 中间件构造函数确实走这条路径（不注入 CacheInterface 时才直连 phpredis，
        // 且 RedisAdapter 构造函数刻意不碰 ext-redis，所以这里不会真去连）。
        $middleware = new Yii3XhprofMiddleware(new FakeResponseFactory(), ['enable' => false, 'redis' => ['host' => '127.0.0.1']]);
        $this->assertInstanceOf(Yii3XhprofMiddleware::class, $middleware);
    }

    // ---------- 13 份 README 的配置表 ↔ 代码键集 ----------

    /**
     * 13 份 README 源：zh 根文件 + `tools/i18n/readme/*.md`（12 种语言）。
     *
     * 语言文件用 glob 收、不写死 12 个路径：新增一种语言时自动进这条闸，
     * 不用记得回来改列表（写死的列表只会静默漏检新语言）。份数守卫在调用方。
     *
     * @return list<string> 相对路径，zh 在前
     */
    private function readmeSources(): array
    {
        $sources = ['README.md'];
        foreach (glob($this->root() . '/tools/i18n/readme/*.md') ?: [] as $abs) {
            $sources[] = 'tools/i18n/readme/' . basename($abs);
        }

        return $sources;
    }

    /**
     * 解析一份 README 的配置表键列（第一列反引号包起来的键名）。
     *
     * 定位规则 = **全文档反引号键名行最多的那张表**。标题文本定不了位：译文的小节
     * 标题是翻译过的（`## Requirements` / `## 動作要件`），而键名列是唯一跨语言同形的
     * 形状。实测余量：13 份文档里配置表都有 19 个键名行，第二大的是 5 个（清理一节
     * 那张），差 14。解析不出（< 10 个）由调用方报红，不静默通过。
     *
     * 解析前剥掉 bidi 控制符：ar.md 的部分行在键名反引号后跟着 U+200E（LRM），
     * 「行首 `|` + 反引号 + 键名 + 反引号 + 空白 + `|`」的收尾就断在它上面——实测
     * 不剥时 ar 只认出 9/19 个键名行（键名本身没错，是行尾多了一个不可见字符），
     * 剥掉后 19/19，且 13 份键列逐字相等。剥的是 LRM/RLM/ALM 与 LRE..PDI 这一组
     * 不可见控制符，不碰任何可见字符。
     *
     * @return list<string>
     */
    private function documentedConfigKeys(string $markdown): array
    {
        $best = [];
        $current = [];
        // 不用 `\R`：没有 /u 时它连字节 0x85（NEL）也当换行，而 0x85 是很多汉字
        // UTF-8 编码的续字节——实测 672 行的 README 被拆成 1070 段、汉字从中间裂开，
        // 表格行自然一条也匹配不上。只认真正的行尾序列。
        foreach (preg_split('/\r\n|\n|\r/', $markdown) as $line) {
            $line = (string) preg_replace('/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $line);
            if (!str_starts_with($line, '|')) {
                if (count($current) > count($best)) {
                    $best = $current;
                }
                $current = [];
                continue;
            }
            // 行首 `|` + 可选空白 + 反引号——表头（`| Option |`）与分隔行（`|------|`）
            // 都不以反引号开头，不会命中。
            if (preg_match('/^\|\s*`([^`]+)`\s*\|/', $line, $m) === 1) {
                $current[] = $m[1];
            }
        }
        if (count($current) > count($best)) {
            $best = $current;
        }

        return $best;
    }

    /**
     * 每份 README 的配置表键列 ↔ 代码键集，**双向、逐字**。
     *
     * 补的是「新键无文档」这条全绿路径：本类其余用例钉住 12 份配置互相齐步走、
     * i18n 只保 13 份 README 的**表格总行数**一致——所有配置（含 EXPECTED_KEYS）一起
     * 加一个新键时全绿，13 份 README 却没人更新；而译文的键名是标识符（不随语言
     * 翻译），改了名就是文档错，此前没有任何检查看得见。
     *
     * 只比键名集合，不比默认值文本（`86400 * 7` vs `604800` 这类书写差异会假红）。
     * 代表配置取 Slim 一份，与本类其余用例同一个参照：另外 11 份由
     * everyFrameworkConfigHasTheSameKeysInTheSameOrder 钉成同一键集，多读 11 份
     * 只是把同一条链条数三遍。也不比顺序：文档表格的行序是排版，不是契约。
     *
     * @param array<string, string> $docs 相对路径 => Markdown
     * @param list<string> $coded 代码键集（代表配置的 array_keys）
     * @return list<string> 问题清单（空 = 全过）
     */
    private function configTableProblems(array $docs, array $coded): array
    {
        $problems = [];
        foreach ($docs as $rel => $markdown) {
            $keys = $this->documentedConfigKeys($markdown);
            // 解析失效与「文档真的缺键」必须分开报：前者指向解析/表格形态，后者指向内容
            if (count($keys) < 10) {
                $problems[] = sprintf('%s: 配置表键列只解析出 %d 个键（表格形态或定位规则变了，先修解析）', $rel, count($keys));
                continue;
            }
            foreach (array_diff($keys, $coded) as $extra) {
                $problems[] = sprintf('%s: 表里写了代码里没有的键 `%s`（写错名，或配置键被删了没同步文档）', $rel, $extra);
            }
            foreach (array_diff($coded, $keys) as $missing) {
                $problems[] = sprintf('%s: 代码键 `%s` 没写进这份配置表', $rel, $missing);
            }
        }

        return $problems;
    }

    #[Test]
    public function theThirteenReadmeConfigTablesDocumentExactlyTheCodeKeySet(): void
    {
        $sources = $this->readmeSources();
        // 份数守卫：glob 坏掉（目录挪了 / 后缀变了）时不能只剩 zh 一份还悄悄全绿
        $this->assertGreaterThanOrEqual(13, count($sources), '13 份 README 源没凑齐：工具目录结构变了，先修 readmeSources()');

        $docs = [];
        foreach ($sources as $rel) {
            $this->assertFileExists($this->root() . '/' . $rel, "$rel 不见了");
            $docs[$rel] = (string) file_get_contents($this->root() . '/' . $rel);
        }

        $problems = $this->configTableProblems($docs, array_keys($this->loadPhp('src/Slim/config/xhprof.php')));
        $this->assertSame(
            [],
            $problems,
            "13 份 README 的配置表键列必须逐字等于代码键集（键名是标识符，不随语言翻译）：\n" . implode("\n", $problems)
        );
    }

    /**
     * 检查有效性的证明：13 份真 README（读进内存），每次只改一处，必须只报出改掉的
     * 那一份。不落盘改任何 README——它们正被 i18n / docs 的写者拿着（真文件的就地
     * 变异用一次性 /tmp 备份 + md5 守卫还原另做，常驻证明走内存副本）。
     */
    #[Test]
    public function theThirteenReadmeConfigTableGateGoesRedInAnySingleLanguage(): void
    {
        $docs = [];
        foreach ($this->readmeSources() as $rel) {
            $docs[$rel] = (string) file_get_contents($this->root() . '/' . $rel);
        }
        $coded = array_keys($this->loadPhp('src/Slim/config/xhprof.php'));

        // 夹具自检：13 份真文件先全干净，否则下面的红说明不了任何事
        $this->assertSame([], $this->configTableProblems($docs, $coded), '真实 README 与配置已经对不上，先修这个再看下面的证明');

        // (1) 某译文缺键：ja 的 `locale` 行删掉 → 只报 ja 缺这一个键
        $ja = preg_replace('/^\| `locale` \|.*$/m', '', $docs['tools/i18n/readme/ja.md'], 1, $n);
        $this->assertSame(1, $n, 'ja.md 的配置表里找不到 `locale` 行，夹具已失效');
        $docs['tools/i18n/readme/ja.md'] = (string) $ja;
        $this->assertSame(
            ['tools/i18n/readme/ja.md: 代码键 `locale` 没写进这份配置表'],
            $this->configTableProblems($docs, $coded),
            '译文少了 `locale`，检查必须报出来（且只报这一份、这一个键）'
        );

        // (2) 某译文多键：en 的表里塞一个不存在的 `no_such_key` → 只报 en 多这一个键
        $docs['tools/i18n/readme/en.md'] = str_replace(
            '| `enable` |',
            "| `no_such_key` | bool | `true` | 假行 |\n| `enable` |",
            $docs['tools/i18n/readme/en.md'],
            $n
        );
        $this->assertSame(1, $n, 'en.md 的配置表里找不到 `enable` 行，夹具已失效');
        // 顺序 = 遍历顺序（glob 按字母序：en 在 ja 之前）
        $this->assertSame(
            [
                'tools/i18n/readme/en.md: 表里写了代码里没有的键 `no_such_key`（写错名，或配置键被删了没同步文档）',
                'tools/i18n/readme/ja.md: 代码键 `locale` 没写进这份配置表',
            ],
            $this->configTableProblems($docs, $coded),
            '两份各有各的问题时，两份都要被点名'
        );

        // (3) 表格形态坏掉：ru 的表格行全抹掉 → 走「解析不出键」这条，而不是静默弃权
        $docs['tools/i18n/readme/ru.md'] = (string) preg_replace('/^\|.*$/m', '', $docs['tools/i18n/readme/ru.md']);
        $this->assertStringContainsString(
            'tools/i18n/readme/ru.md: 配置表键列只解析出 0 个键',
            implode("\n", $this->configTableProblems($docs, $coded)),
            '表格没了必须走解析守卫报出来，不能悄悄当成「没有缺键」'
        );
    }
}
