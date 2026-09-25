<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\I18n;

use ErikWang2013\Xhprof\Core\Contract\ConfigInterface;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;
use ErikWang2013\Xhprof\Core\Xhprof;

/**
 * 报告页文案的语言层。词表在 src/Core/I18n/lang/<locale>.php，键集必须与
 * zh_CN（源语言）完全一致——tests/Unit/Core/I18nTest.php 逐键比对，缺一个就红。
 *
 * 语言优先级（高 → 低）：
 *   1. URL 覆盖        ?lang=ko
 *   2. 配置项          xhprof.locale（管理员定的默认）
 *   3. 浏览器协商      Accept-Language
 *   4. 兜底            zh_CN
 *
 * 四级任何一级拿到不认识的语言码都**不是错误**：跳过它、继续往下问，最终一定有
 * 一个中文结果。报告页宁可显示中文，也绝不因为一个语言码抛异常或 500。
 */
class I18n
{
    /** 兜底语言：源语言，任何一档都解析不出来时用它 */
    public const FALLBACK = 'zh_CN';

    /**
     * 两处转义共用的标志。**`ENT_SUBSTITUTE` 必须显式带上**：PHP 8.1+ 的默认标志里有它，
     * 但一旦显式传 `ENT_QUOTES` 就会把它顶掉 —— 那时非法 UTF-8 的输入返回**空串**
     * （而不是替换成 U+FFFD），整条文案白掉，恰好违反本类的「绝不渲染空白」承诺。
     * 词表是手写的 13 份文件，谁存成 GBK/Latin-1 就可能撞上。
     */
    private const HTML_FLAGS = ENT_QUOTES | ENT_SUBSTITUTE;

    /** 12 份词表：zh_CN 是源，其余为译文。顺序即 README 语言切换器的顺序。 */
    public const AVAILABLE = [
        'zh_CN',
        'en',
        'ko',
        'ru',
        'de',
        'fr',
        'es',
        'pt',
        'ar',
        'hi',
        'bn',
        'id',
        'ja',
    ];

    /**
     * 当前语言。**Hyperf 协程下存进 \Hyperf\Context\Context**（由 Hyperf 中间件
     * markHyperfContext() 置位），其余框架存这个静态属性——那些框架的进程模型本来就是
     * 一请求一进程或已有自己的隔离。
     *
     * 为什么语言非要隔离：`Xhprof::index()` 在**页首**读一次 htmlLang()/dir() 拼
     * `<html>`，而正文的 t() 要在 Redis I/O **之后**才读；Hyperf 常驻 worker 里中间
     * 一让出协程，另一个请求就把语言改掉了 —— 同一页能出 `<html lang="en">` 配阿拉伯语
     * 正文、`bodyDir=ltr`。文案错位不影响数据，但它是最刺眼的一种错，而且可复现。
     *
     * **这是部分隔离，不是「协程问题解决了」**：XhprofDisplay 的
     * `$stats`/`$pc_stats`/`$totals`/`$sort_col` 那批渲染静态量仍在共享区，两个协程并发
     * 渲染同一进程时照样互相覆盖。隔离的只有语言这一份状态。
     */
    private static string $locale = self::FALLBACK;

    /** 协程 Context 里语言占的键（与 Xhprof 的 'xhprof.request' 等同一个命名空间）。 */
    private const LOCALE_KEY = 'xhprof.locale';

    /**
     * 语言码 => 词表，每份只载入一次。
     *
     * 按语言键的**不可变缓存**：载进来就不再改，所以协程之间共享是安全的（原先记的是
     * 「当前语言的词表」，那份状态天生属于某一个请求，setLocale() 得把它清掉重载）。
     */
    private static array $catalogs = [];

    /**
     * 按四级优先级定出本次请求的语言。任何一级的取值不认识就跳过，绝不抛异常。
     *
     * @param RequestInterface|null $req 取 ?lang= 与 Accept-Language
     * @param ConfigInterface|null $cfg 取 xhprof.locale
     */
    public static function resolve(?RequestInterface $req = null, ?ConfigInterface $cfg = null): string
    {
        if ($req !== null) {
            $fromUrl = self::normalize(self::stringOrNull($req->get('lang')));
            if ($fromUrl !== null) {
                return $fromUrl;
            }
        }

        if ($cfg !== null) {
            $fromConfig = self::normalize(self::stringOrNull($cfg->get('xhprof.locale', null)));
            if ($fromConfig !== null) {
                return $fromConfig;
            }
        }

        if ($req !== null) {
            $fromBrowser = self::fromAcceptLanguage($req->header('accept-language'));
            if ($fromBrowser !== null) {
                return $fromBrowser;
            }
        }

        return self::FALLBACK;
    }

    /**
     * 把各种写法归一到一个词表码：`zh-CN`/`zh_cn`/`zh-Hans-CN`/`zh` → `zh_CN`，
     * `en-US` → `en`，`pt_BR` → `pt`。不认识（或 null/空）返回 null——
     * 调用方据此「跳过这一级」，而不是报错。
     */
    public static function normalize(mixed $tag): ?string
    {
        $tag = self::stringOrNull($tag);
        if ($tag === null) {
            return null;
        }

        // Accept-Language 的元素可能带权重："en-US;q=0.9" → "en-US"；
        // 下划线与连字符两种分隔写法都接受（配置文件里手写 zh_cn 是常事）。
        $tag = str_replace('_', '-', strtolower(trim(explode(';', $tag, 2)[0])));
        // 空串与 `*` 不再单独判：canonical() 就是「是不是我们有的语言」这个谓词，
        // 两者都答 null，这里多写一遍只会让两处都能被删而没人发现。
        $exact = self::canonical($tag);
        if ($exact !== null) {
            return $exact;
        }

        return self::canonical(explode('-', $tag, 2)[0]);
    }

    /** 设定当前语言。未知码回落到 zh_CN，不抛异常。 */
    public static function setLocale(mixed $locale): void
    {
        $code = self::normalize($locale) ?? self::FALLBACK;
        if (self::inCoroutineContext()) {
            \Hyperf\Context\Context::set(self::LOCALE_KEY, $code);
            return;
        }
        self::$locale = $code;
    }

    public static function locale(): string
    {
        if (self::inCoroutineContext()) {
            // 再归一化一次：这个键只有 setLocale() 会写，但读到什么就返回什么的话，
            // 一旦有别人往同名键里塞了字符串，`<html lang="…">` 与切换器就会印出它。
            return self::normalize(\Hyperf\Context\Context::get(self::LOCALE_KEY)) ?? self::FALLBACK;
        }
        return self::$locale;
    }

    /** Hyperf 协程环境且 Context 类真的在（与 Xhprof::getRequest() 同一套判定）。 */
    private static function inCoroutineContext(): bool
    {
        return Xhprof::isHyperfContext() && class_exists(\Hyperf\Context\Context::class);
    }

    /** `<html lang="…">` 用的 BCP-47 标签：`zh_CN` → `zh-CN`。 */
    public static function htmlLang(): string
    {
        $lang = self::meta('lang');
        return is_string($lang) && $lang !== '' ? $lang : str_replace('_', '-', self::$locale);
    }

    /** 书写方向：阿拉伯语等从右往左的语言返回 `rtl`。 */
    public static function dir(): string
    {
        $dir = self::meta('dir');
        return $dir === 'rtl' ? 'rtl' : 'ltr';
    }

    /**
     * 取一条文案（**原始**，含词表里写的 `<br>`）。缺 key、或译文值为空串时
     * 回落到中文源，源里也没有（代码引用了一个谁都没定义的 key）就返回 key
     * 本身——报告页显示一个 key 名总好过白屏或 500。
     */
    public static function t(string $key): string
    {
        $value = self::catalog()[$key] ?? '';
        if (!is_string($value) || $value === '') {
            $value = self::source()[$key] ?? $key;
        }
        return is_string($value) ? $value : $key;
    }

    /** 放进 HTML 上下文取文案：除 `<br>` 外全部转义（`<script>` 会变成实体）。 */
    public static function html(string $key): string
    {
        return self::escapeHtml(self::t($key));
    }

    /** 放进纯文本/属性（`<title>`、`placeholder="…"`、`<td>`）取文案：`<br>` 折成空格。 */
    public static function plain(string $key): string
    {
        return self::escapePlain(self::t($key));
    }

    /**
     * 转义策略本体：整串转义，**只**把 `<br>`（三种写法）还原成真标记。
     *
     * 值里能出现的标记只有折行这一个——列头很窄，中文两字一行、字母语言未必，
     * 断行位置是译者的事，不该由代码猜。别的标签一律变实体，译者（或词表被人
     * 改坏）也塞不进 `<script>`、`onerror=`、属性逃逸。
     *
     * public 是为了让测试拿危险串直接喂它：html()/plain() 只是 `t()` + 这一层。
     */
    public static function escapeHtml(string $raw): string
    {
        return str_replace(
            ['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'],
            '<br>',
            htmlspecialchars($raw, self::HTML_FLAGS, 'UTF-8')
        );
    }

    /** 同 escapeHtml()，但把换行标记折成空格——纯文本/属性上下文不能有 `<br>`。 */
    public static function escapePlain(string $raw): string
    {
        return str_replace(
            ['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'],
            ' ',
            htmlspecialchars($raw, self::HTML_FLAGS, 'UTF-8')
        );
    }

    /** 词表里有没有这个 key（含值为空串的）。给调用方做兜底判断用。 */
    public static function has(string $key): bool
    {
        return array_key_exists($key, self::catalog()) || array_key_exists($key, self::source());
    }

    /** 某个词表码对应的词表；给自检与 parity 测试读全量用。 */
    public static function catalogOf(string $locale): array
    {
        $code = self::normalize($locale) ?? self::FALLBACK;
        return self::$catalogs[$code] ??= self::load($code);
    }

    /**
     * Accept-Language 协商：按 q 值从高到低找第一个我们有的语言；`*`、q=0 与
     * 不认识的标签一样被跳过。全都不认识返回 null（由调用方兜底中文）。
     */
    private static function fromAcceptLanguage(mixed $header): ?string
    {
        // 参数是 mixed 不是 ?string：契约写的是 ?string，但一个返回数组的适配器
        // （或配置写错的中间层）不该让报告页 500。类型不对就当没这个头。
        if (!is_string($header) || trim($header) === '') {
            return null;
        }

        $weighted = [];
        foreach (explode(',', $header) as $i => $part) {
            $segments = explode(';', $part, 2);
            // 空标签和 `*` 不在这里特判：normalize() 对两者都返回 null，
            // 下面那轮遍历自会跳过（重复判一次就等于两处都可以被删而没人发现）
            $tag = trim($segments[0]);
            $q = 1.0;
            if (isset($segments[1]) && preg_match('/q\s*=\s*([0-9.]+)/i', $segments[1], $m) === 1) {
                $q = (float) $m[1];
            }
            if ($q <= 0.0) {
                continue;   // q=0 是「明确不要这个语言」
            }
            $weighted[] = [$q, $i, $tag];
        }

        // q 相同的保持请求头里的先后顺序（RFC 9110 允许，也符合直觉）
        usort($weighted, static function (array $a, array $b): int {
            return ($b[0] <=> $a[0]) ?: ($a[1] <=> $b[1]);
        });

        foreach ($weighted as $entry) {
            $code = self::normalize($entry[2]);
            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    /** 归一化的最后一步：这个标签是不是我们有的语言。 */
    private static function canonical(string $tag): ?string
    {
        foreach (self::AVAILABLE as $code) {
            if (strtolower(str_replace('_', '-', $code)) === $tag) {
                return $code;
            }
        }
        // 只提供简体中文这一种中文：zh、zh-TW、zh-Hans-CN 都归到 zh_CN
        if ($tag === 'zh' || str_starts_with($tag, 'zh-')) {
            return 'zh_CN';
        }
        return null;
    }

    /** 当前语言的词表——**每次现读** locale()，不再有「换语言要清缓存」这一步。 @return array<string, mixed> */
    private static function catalog(): array
    {
        return self::catalogOf(self::locale());
    }

    /** @return array<string, mixed> 源语言（zh_CN）词表 —— t()/has() 的最后一级兜底 */
    private static function source(): array
    {
        return self::catalogOf(self::FALLBACK);
    }

    /** 读某语言的词表；文件缺失或内容不合法一律当空词表，由 t() 回落到中文源。 */
    private static function load(string $locale): array
    {
        $file = __DIR__ . '/lang/' . $locale . '.php';
        if (!is_file($file)) {
            return [];
        }
        $catalog = require $file;
        return is_array($catalog) ? $catalog : [];
    }

    /** 当前词表的 _meta.<field>；取不到返回 null。 */
    private static function meta(string $field): mixed
    {
        $meta = self::catalog()['_meta'] ?? null;
        return is_array($meta) ? ($meta[$field] ?? null) : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
