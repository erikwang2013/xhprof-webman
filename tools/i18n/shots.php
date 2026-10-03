<?php

declare(strict_types=1);

use ErikWang2013\Xhprof\Core\I18n\I18n;
use ErikWang2013\Xhprof\Tests\Unit\Docs\LocaleScreenshotGateTest;

/**
 * tools/i18n/shots.php — 重拍 26 张交付截图，并按同一份渲染刷新 HTML 指纹清单。
 *
 *   php tools/i18n/shots.php                          拍 26 张 → docs/…，成功后刷新清单
 *   php tools/i18n/shots.php --out=DIR                拍到 DIR（镜像 docs/ 结构），不动清单
 *   php tools/i18n/shots.php --dry-run                不拍：26 页渲染两遍（进程内 + 走 HTTP），逐页比对
 *   php tools/i18n/shots.php --regenerate-manifest    只按当前渲染刷新清单（不拍、不碰 PNG）
 *
 * 依赖：google-chrome 与 python3 + Pillow。裁剪口径与已发布 26 张逐字节一致：Chrome 拍
 * 1280x3000 的底图 → 按像素找内容底 → 裁到「内容底 + 23px」（optimize=True 与上一批
 * 同一个 PNG 编码器；页面没变时输出逐字节不变，可当回归证据）。
 *
 * 渲染路径 = LocaleScreenshotGateTest::page()，页表（URL 参数、输出路径）取自
 * ScreenshotGateTest::screenshotPages()：工具与闸之间不存在「两份夹具各自演进」的
 * 缝隙——2026-10-03 的 ar 事故（截图 23:29:57、词表补齐 23:30:06）差的 9 秒就是手工
 * 对齐的形状。清单钉 HTML 与它引用到的静态资源的 sha1，**不钉 PNG 字节**（Chrome/
 * 字体一漂字节就变，正确性可以分毫未动）。
 *
 * 本文件同时是给 Chrome 用的 `php -S` 的 router（PHP_SAPI=cli-server 分支）：
 * /xhprof 真渲染、/xhprof-assets/* 映射 src/html/*。
 */

$SHOTS_ROOT = dirname(__DIR__, 2);

const SHOTS_CHROME = 'google-chrome';
/** 内容底 + 23px：与已发布 26 张的标定口径（/tmp/implshots/crop.py）逐字节对齐 */
const SHOTS_PAD = 23;
/** 高只须高过任何语种的内容底（实测最高 1647）；裁剪高度按像素量，不写死 */
const SHOTS_WINDOW = '1280,3000';
const SHOTS_MANIFEST = 'tests/Unit/Docs/screenshot-html-manifest.json';

/** 与 /tmp/implshots/crop.py 同一口径。`optimize=True` 是载重的：换掉它 PNG 字节就不再与已发布产物一致。 */
const SHOTS_CROP_PY = <<<'PY'
import sys
from PIL import Image

raw, out, pad = sys.argv[1], sys.argv[2], int(sys.argv[3])
im = Image.open(raw).convert("RGB")
w, h = im.size
px = im.load()
bg = px[w - 2, h - 2]          # 内容远不满 3000px，右下角必是背景

last = 0
for y in range(h - 1, -1, -1):
    differs = False
    for x in range(w):
        if px[x, y] != bg:
            differs = True
            break
    if differs:
        last = y
        break

crop_h = min(h, last + pad)
im.crop((0, 0, w, crop_h)).save(out, optimize=True)
print(f"{raw}: bg={bg} content-bottom={last} -> {w}x{crop_h}")
PY;

/** vendor + Fakes + 闸类。cli-server 每个请求都重跑本文件，require_once 足够。 */
function shots_bootstrap(string $root): void
{
    require_once $root . '/vendor/autoload.php';
    // Fakes.php 类名 ≠ 文件名，PSR-4 找不到（tests/bootstrap.php 也是手工 require 的）
    require_once $root . '/tests/Fixtures/Fakes.php';
    if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
        fwrite(STDERR, "shots.php: 缺 composer dev 依赖（phpunit）——先 composer install\n");
        exit(2);
    }
    if (!class_exists(LocaleScreenshotGateTest::class)) {
        fwrite(STDERR, 'shots.php: 找不到 ' . LocaleScreenshotGateTest::class . "——tests/ 不在？\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------------
// cli-server：给 Chrome 喂页面（与闸同一渲染路径）与静态资源
// ---------------------------------------------------------------------------

if (PHP_SAPI === 'cli-server') {
    shots_bootstrap($SHOTS_ROOT);
    shots_serve($SHOTS_ROOT);
    return;
}

function shots_serve(string $root): void
{
    $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

    if ($uri === '/xhprof' || $uri === '/index.php') {
        $params = $_GET;
        // ?lang= 是四级协商的第一级。没有它，page() 造的请求会按 Accept-Language
        // （Chrome 是 en-US）协商——zh_CN 的图就是这么变英文的，URL 必须显式带 lang。
        $lang = is_string($params['lang'] ?? null) && $params['lang'] !== '' ? $params['lang'] : I18n::FALLBACK;
        header('Content-Type: text/html; charset=utf-8');
        echo LocaleScreenshotGateTest::page($lang, $params);
        return;
    }

    if (str_starts_with($uri, '/xhprof-assets/')) {
        $base = realpath($root . '/src/html');
        $file = is_string($base) ? realpath($base . '/' . substr($uri, strlen('/xhprof-assets/'))) : false;
        if (is_string($base) && is_string($file) && str_starts_with($file, $base) && is_file($file)) {
            $types = [
                'css' => 'text/css; charset=utf-8',
                'js'  => 'application/javascript; charset=utf-8',
                'svg' => 'image/svg+xml',
                'png' => 'image/png',
                'gif' => 'image/gif',
            ];
            header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
            readfile($file);
            return;
        }
    }

    http_response_code(404);
    echo 'not found';
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

shots_bootstrap($SHOTS_ROOT);

$out = null;
$modes = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $out = rtrim(substr($arg, 6), '/');
    } elseif (in_array($arg, ['--dry-run', '--regenerate-manifest'], true)) {
        $modes[] = $arg;
    } else {
        fwrite(STDERR, "shots.php: 不认识的参数 {$arg}（--out 要写成 --out=DIR，空格会被拒）\n\n");
        shots_usage();
        exit(2);
    }
}
if ($out === '' || count($modes) > 1 || ($modes !== [] && $out !== null)) {
    shots_usage();
    exit(2);
}

$pages = LocaleScreenshotGateTest::screenshotPages();

if (in_array('--dry-run', $modes, true)) {
    exit(shots_dry_run($SHOTS_ROOT, $pages));
}
if (in_array('--regenerate-manifest', $modes, true)) {
    $manifest = shots_write_manifest($SHOTS_ROOT, shots_render_pages($pages));
    shots_report_manifest($manifest);
    exit(0);
}

exit(shots_shoot($SHOTS_ROOT, $pages, $out));

// ---------------------------------------------------------------------------
// 三个模式共用的件
// ---------------------------------------------------------------------------

function shots_usage(): void
{
    fwrite(STDERR, <<<'TXT'
    用法:
      php tools/i18n/shots.php                        重拍 26 张 → docs/，成功后刷新 HTML 指纹清单
      php tools/i18n/shots.php --out=DIR              拍到 DIR（镜像 docs/ 结构），不动清单
      php tools/i18n/shots.php --dry-run              只渲染 26 页（进程内 + HTTP 各一遍）逐页比对，不拍
      php tools/i18n/shots.php --regenerate-manifest  只按当前渲染刷新清单（不拍、不碰 PNG）

    TXT);
}

/** @param array<string, array{lang: string, params: array<string, string>, png: string}> $pages */
function shots_url(int $port, array $page): string
{
    return sprintf(
        'http://127.0.0.1:%d/xhprof?%s',
        $port,
        http_build_query($page['params'] + ['lang' => $page['lang']])
    );
}

/** @param array<string, array{lang: string, params: array<string, string>, png: string}> $pages @return array<string, string> key => HTML */
function shots_render_pages(array $pages): array
{
    $htmls = [];
    foreach ($pages as $key => $page) {
        $html = LocaleScreenshotGateTest::page($page['lang'], $page['params']);
        if (strlen($html) < 1000) {   // page() 已保证是 string；空页生成出的清单哈希照样「对得上」
            fwrite(STDERR, "shots.php: {$key} 渲染出的 HTML 不足 1KB——夹具或渲染路径坏了\n");
            exit(1);
        }
        $htmls[$key] = $html;
    }
    return $htmls;
}

/** @param array<string, string> $htmls key => HTML */
function shots_write_manifest(string $root, array $htmls): array
{
    $assets = LocaleScreenshotGateTest::assetDigests($htmls);
    if (count($assets) < 5) {   // 夹具坏掉 / 正则哑掉时别把一份空资源表写进仓库（实测踩过一次）
        fwrite(STDERR, 'shots.php: 26 页只收出 ' . count($assets) . " 个静态资源——模板、夹具或资源扫描坏了，清单不写\n");
        exit(1);
    }

    $data = [
        '_how'  => '由 php tools/i18n/shots.php 在拍照成功后刷新（--regenerate-manifest 单独刷新）。'
            . '渲染路径 = LocaleScreenshotGateTest::page()；页表 = LocaleScreenshotGateTest::screenshotPages()。',
        '_what' => 'html = 每页渲染出的 HTML 的 sha1（26 页，含 zh_CN 两张根图）；assets = 页面引用到的 '
            . 'src/html 文件的 sha1。**不钉 PNG 字节**：Chrome/字体会漂，正确性可以分毫未动。'
            . '任一条不符 = 盘上的截图拍的不是这份 HTML，重拍后由本工具刷回。',
        'html'   => array_map('sha1', $htmls),
        'assets' => $assets,
    ];
    file_put_contents(
        $root . '/' . SHOTS_MANIFEST,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    return $data;
}

function shots_report_manifest(array $manifest): void
{
    printf(
        "清单已刷新：%s（%d 页 HTML + %d 个静态资源）\n",
        SHOTS_MANIFEST,
        count($manifest['html']),
        count($manifest['assets'])
    );
}

// ---------------------------------------------------------------------------
// --dry-run：不拍，只渲染 + 比对
// ---------------------------------------------------------------------------

/**
 * 26 页各渲染两遍——进程内（清单钉的就是它）与走 HTTP（Chrome 看到的是它）——逐字节比，
 * 再与清单对照。退出码 0 = 26 页非空且两种渲染一致；非 0 = 渲染/服务有页坏了。
 * 清单「已变」不改变退出码：那是闸的判据，这里只负责如实报告。
 *
 * @param array<string, array{lang: string, params: array<string, string>, png: string}> $pages
 */
function shots_dry_run(string $root, array $pages): int
{
    $manifest = is_file($root . '/' . SHOTS_MANIFEST)
        ? json_decode((string) file_get_contents($root . '/' . SHOTS_MANIFEST), true)
        : null;
    $pinned = is_array($manifest['html'] ?? null) ? $manifest['html'] : null;

    [$server, $port] = shots_start_server($root);
    $bad = 0;
    $drift = 0;
    $htmls = [];
    try {
        foreach ($pages as $key => $page) {
            $html = LocaleScreenshotGateTest::page($page['lang'], $page['params']);
            if (strlen($html) < 1000) {
                printf("%-22s 渲染出的 HTML 不足 1KB——夹具或渲染路径坏了\n", $key);
                $bad++;
                continue;
            }
            $htmls[$key] = $html;

            $served = @file_get_contents(shots_url($port, $page));
            if (!is_string($served) || strlen($served) < 1000) {
                printf("%-22s HTTP 拉不到页面（%s）\n", $key, shots_url($port, $page));
                $bad++;
                continue;
            }
            if ($served !== $html) {
                printf("%-22s HTTP 与进程内渲染不一致（%d vs %d bytes）——Chrome 拍的与清单钉的不是同一份\n",
                    $key, strlen($served), strlen($html));
                $bad++;
                continue;
            }

            $state = $pinned === null ? '清单不在' : (($pinned[$key] ?? null) === sha1($html) ? '一致' : '已变');
            if ($state === '已变') {
                $drift++;
            }
            printf("%-22s %7d bytes  %s  %s\n", $key, strlen($html), substr(sha1($html), 0, 12), $state);
        }
    } finally {
        shots_stop_server($server);
    }

    if (is_array($manifest['assets'] ?? null)) {
        $now = LocaleScreenshotGateTest::assetDigests($htmls);
        $changed = [];
        foreach ($now as $rel => $digest) {
            if (($manifest['assets'][$rel] ?? null) !== $digest) {
                $changed[] = $rel;
            }
        }
        printf("静态资源：%d 个，%s\n", count($now), $changed === [] ? '清单全部一致' : '已变/新引用：' . implode('、', $changed));
    }

    printf(
        "\n%d/%d 页非空且 HTTP 与进程内逐字节一致；清单：%s。\n",
        count($htmls),
        count($pages),
        $pinned === null ? '不在（--regenerate-manifest 出第一份）' : "{$drift} 页已变、其余一致"
    );
    return $bad === 0 ? 0 : 1;
}

// ---------------------------------------------------------------------------
// 拍照
// ---------------------------------------------------------------------------

/**
 * 拍 26 张。每页：进程内渲染（清单要钉的字节）→ HTTP 取回（Chrome 要拿的字节）→
 * 两者不一致就拒绝拍这一页 → Chrome 截 1280x3000 底图 → 裁到内容底 + 23px。
 * 全部成功才刷新清单：清单不能新于任何一张 PNG。
 *
 * @param array<string, array{lang: string, params: array<string, string>, png: string}> $pages
 */
function shots_shoot(string $root, array $pages, ?string $out): int
{
    shots_require_tools();
    // $page['png'] 自带 `docs/` 前缀（清单与语种表都按仓内相对路径写），所以默认根
    // 必须带上那一段；--out=DIR 是「拿 DIR 顶替 docs/」的镜像布局。
    $docsRoot = $out ?? $root . '/docs';
    $tmp = sys_get_temp_dir() . '/i18n-shots-' . getmypid();
    if (!is_dir($tmp . '/profile') && !mkdir($tmp . '/profile', 0777, true) && !is_dir($tmp . '/profile')) {
        fwrite(STDERR, "shots.php: 建不了临时目录 {$tmp}\n");
        return 2;
    }
    file_put_contents($tmp . '/crop.py', SHOTS_CROP_PY);

    [$server, $port] = shots_start_server($root);
    // 中途 fatal/exit 也别把 php -S 留在随机端口上（finally 管不到 fatal）
    register_shutdown_function(static function () use ($server): void {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    });

    $failed = [];
    $htmls = [];
    $started = microtime(true);
    try {
        $n = 0;
        foreach ($pages as $key => $page) {
            $n++;
            $url = shots_url($port, $page);
            $rel = substr($page['png'], strlen('docs/'));      // i18n/<lang>/images/x.png | images/x.png
            $raw = $tmp . '/raw-' . str_replace('/', '-', $key) . '.png';
            $dst = $docsRoot . '/' . $rel;
            $t = microtime(true);

            $html = LocaleScreenshotGateTest::page($page['lang'], $page['params']);
            $served = @file_get_contents($url);
            if (strlen($html) < 1000 || !is_string($served) || sha1($served) !== sha1($html)) {
                printf("[%2d/%d] %-22s 拒绝：HTTP 服务与进程内渲染不一致（拍出来会不是清单钉的那份）\n", $n, count($pages), $key);
                $failed[] = $key;
                continue;
            }
            $htmls[$key] = $html;

            $cmd = implode(' ', [
                SHOTS_CHROME,
                '--headless=new', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
                '--force-device-scale-factor=1',
                '--window-size=' . SHOTS_WINDOW,
                '--virtual-time-budget=4000',
                '--user-data-dir=' . escapeshellarg($tmp . '/profile'),
                '--screenshot=' . escapeshellarg($raw),
                escapeshellarg($url),
            ]) . ' 2>/dev/null';
            exec($cmd, $unused, $rc);
            if ($rc !== 0 || !is_file($raw) || filesize($raw) < 1024) {
                printf("[%2d/%d] %-22s 截图失败（chrome rc=%d）\n", $n, count($pages), $key, $rc);
                $failed[] = $key;
                continue;
            }

            if (!is_dir(dirname($dst)) && !mkdir(dirname($dst), 0777, true) && !is_dir(dirname($dst))) {
                printf("[%2d/%d] %-22s 建不了输出目录 %s\n", $n, count($pages), $key, dirname($dst));
                $failed[] = $key;
                continue;
            }
            exec('python3 ' . escapeshellarg($tmp . '/crop.py') . ' ' . escapeshellarg($raw) . ' ' . escapeshellarg($dst)
                . ' ' . SHOTS_PAD . ' 2>&1', $cropOut, $crc);
            if ($crc !== 0 || !is_file($dst) || filesize($dst) < 1024) {
                printf("[%2d/%d] %-22s 裁剪失败：%s\n", $n, count($pages), $key, implode(' / ', $cropOut));
                $failed[] = $key;
                continue;
            }

            $size = getimagesize($dst) ?: [0, 0];
            printf("[%2d/%d] %-22s → %-42s %dx%d  %.1fs\n",
                $n, count($pages), $key, $rel, $size[0], $size[1], microtime(true) - $t);
        }
    } finally {
        shots_stop_server($server);
    }

    if ($failed !== []) {
        fwrite(STDERR, sprintf(
            "\n%d/%d 张失败：%s\n"
            . "已拍好的那些已写入（现在是新旧混合），清单未刷新（清单不能新于任何一张 PNG）。\n"
            . "修掉原因后重跑一遍，全绿再提交。原始底图留在 %s\n",
            count($failed), count($pages), implode('、', $failed), $tmp
        ));
        return 1;
    }

    printf(
        "\n%d/%d 张已更新（%.0fs）。原始底图（裁剪前的 1280x3000）留在 %s\n",
        count($pages), count($pages), microtime(true) - $started, $tmp
    );

    if ($out !== null) {
        printf("写到 --out=%s，未刷新清单（默认模式下清单与 docs/ 的图一起更新）。\n", $out);
        return 0;
    }

    shots_report_manifest(shots_write_manifest($root, $htmls));
    return 0;
}

/** 起一个 php -S（router 就是本文件）；返回 [进程, 端口]。 */
function shots_start_server(string $root): array
{
    $log = sys_get_temp_dir() . '/i18n-shots-server.log';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $port = random_int(18100, 18999);
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __FILE__],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $root
        );
        if (!is_resource($proc)) {
            continue;
        }
        for ($i = 0; $i < 60; $i++) {                 // 最多 ~6s
            usleep(100_000);
            $ping = @file_get_contents("http://127.0.0.1:{$port}/xhprof?lang=en");
            // 内容对得上才算自己的服务（随机端口上可能蹲着上一次留下的进程）
            if (is_string($ping) && str_contains($ping, '<html lang="en"')) {
                return [$proc, $port];
            }
            if (!proc_get_status($proc)['running']) {
                break;
            }
        }
        proc_terminate($proc);
        proc_close($proc);
    }
    fwrite(STDERR, "shots.php: php -S 起不来（端口试了 5 次），日志：{$log}\n");
    exit(2);
}

/** @param resource $server */
function shots_stop_server($server): void
{
    proc_terminate($server);
    proc_close($server);
}

function shots_require_tools(): void
{
    exec(SHOTS_CHROME . ' --version 2>/dev/null', $v, $rc);
    if ($rc !== 0 || $v === []) {
        fwrite(STDERR, 'shots.php: 找不到 ' . SHOTS_CHROME . "——截图需要 google-chrome\n");
        exit(2);
    }
    printf("Chrome: %s\n", $v[0]);

    exec('python3 -c ' . escapeshellarg('import PIL; print(PIL.__version__)') . ' 2>/dev/null', $p, $rc2);
    if ($rc2 !== 0 || $p === []) {
        fwrite(STDERR, "shots.php: 缺 python3 + Pillow（裁剪用）——apt install python3-pil\n");
        exit(2);
    }
    printf("Pillow: %s\n", $p[0]);
}
