<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeResponseFactory;
use ErikWang2013\Xhprof\Yii3\Adapter\ConfigAdapter as Yii3ConfigAdapter;
use ErikWang2013\Xhprof\Yii3\XhprofMiddleware as Yii3XhprofMiddleware;

/**
 * 十份配置的**键集/默认值**对照。
 *
 * 每条框架接入路径都自带一份 config/xhprof.php，没有共享层（见计划：刻意去中心化）。
 * 代价是键名/默认值可能悄悄走岔——用户在 A 框架里写 `'log_num' => 500` 生效，换到 B
 * 框架不生效，而两边都不报错。这里钉住：
 *   - 键集完全相同（含顺序）；
 *   - 每个键的默认值逐项相同（Drupal 是 YAML，形态不同、键与值相同）；
 *   - src/*\/config 下不能再冒出没被列进来的 xhprof 配置文件。
 *
 * 比对的是「解析后的数组」，不是文件字节：注释、空行、`86400 * 7` vs `604800`
 * 这类写法差异都不该让测试红。
 */
class ConfigParityTest extends TestCase
{
    /** @var list<string> 九个键，顺序即配置文件的书写顺序 */
    private const EXPECTED_KEYS = [
        'enable',
        'time_limit',
        'log_num',
        'view_wtred',
        'ignore_url_arr',
        'assets_url',
        'auth_token',
        'key_prefix',
        'log_ttl',
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
     * 把 YAML 里 9 个已知键的标量值取出来，转成与 PHP 数组同形的值。
     *
     * 解析失败**不会**静默通过：解析结果要与 PHP 侧逐项相同，猜错就会红。
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
        if ($raw === 'true' || $raw === 'false') {
            return $raw === 'true';
        }
        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
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
    public function theSharedKeySetIsExactlyTheNineDocumentedKeys(): void
    {
        // 与上一条互补：上一条管「十份彼此一致」，这条管「一致的确实是这九个」——
        // 十个文件被同一次改动一起加键时，只有这条会红。
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
        // 包内默认配置文件里没有它（键集仍是那 nine 个）。两者并不矛盾，这里钉住这个区别。
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
}
