<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core;

/**
 * 项目宠物「焰焰」——取自 xhprof 招牌视图（火焰图）的小火苗。
 *
 * 默认形象见 src/html/pet.svg（与 docs/images/pet.svg 同源，改一处要同步另一处）；
 * 12 种表情形态见 docs/pet-expressions/。两者都是纯矢量、无脚本、无外部依赖的单文件，
 * 可通过 Mascot::form() / Mascot::forms() 在代码中取用。
 */
final class Mascot
{
    /** 中文名 */
    public const NAME = '焰焰';

    /** 项目主页 */
    public const HOME = 'https://github.com/erikwang2013/xhprof-webman';

    /**
     * 全部形态：default 为默认形象，其余为 12 种表情。
     *
     * @var array<string, array{cn:string, en:string, shortcode:?string}>
     */
    private const FORMS = [
        'default'    => ['cn' => '默认',   'en' => 'Default',     'shortcode' => null],
        'smile'      => ['cn' => '笑',     'en' => 'Smile',       'shortcode' => ':emoji-smile:'],
        'joy'        => ['cn' => '欢乐',   'en' => 'Joy',         'shortcode' => ':emoji-joy:'],
        'cry'        => ['cn' => '哭',     'en' => 'Cry',         'shortcode' => ':emoji-cry:'],
        'depressed'  => ['cn' => '沮丧',   'en' => 'Depressed',   'shortcode' => ':emoji-depressed:'],
        'terrified'  => ['cn' => '惊恐',   'en' => 'Terrified',   'shortcode' => ':emoji-terrified:'],
        'angry'      => ['cn' => '愤怒',   'en' => 'Angry',       'shortcode' => ':emoji-angry:'],
        'coquettish' => ['cn' => '撒娇',   'en' => 'Coquettish',  'shortcode' => ':emoji-coquettish:'],
        'cute'       => ['cn' => '卖萌',   'en' => 'Cute',        'shortcode' => ':emoji-cute:'],
        'pleasing'   => ['cn' => '讨好',   'en' => 'Pleasing',    'shortcode' => ':emoji-pleasing:'],
        'knocking'   => ['cn' => '敲打',   'en' => 'Knocking',    'shortcode' => ':emoji-knocking:'],
        'facepalm'   => ['cn' => '捂脸',   'en' => 'Facepalm',    'shortcode' => ':emoji-facepalm:'],
        'tongue'     => ['cn' => '吐舌头', 'en' => 'Tongue Out',  'shortcode' => ':emoji-tongue:'],
    ];

    /**
     * 全部宠物形态：默认形象 + 12 种表情。
     *
     * @return array<string, array{cn:string, en:string, shortcode:?string}>
     */
    public static function forms(): array
    {
        return self::FORMS;
    }

    /**
     * 取指定形态的 SVG 源码。
     *
     * @param string $name 形态名（见 forms()），大小写与首尾空白不敏感
     * @throws \InvalidArgumentException 形态名不存在
     */
    public static function form(string $name): string
    {
        $name = strtolower(trim($name));

        if (!isset(self::FORMS[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown mascot form "%s"; available: %s',
                $name,
                implode(', ', array_keys(self::FORMS))
            ));
        }

        $root = dirname(__DIR__, 2);
        $path = $name === 'default'
            ? $root . '/src/html/pet.svg'
            : $root . '/docs/pet-expressions/' . $name . '.svg';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * 宠物 SVG 源码，可直接输出到浏览器、写入文件或内联进 HTML。
     *
     * @param string|null $form 形态名；null 取默认形象
     */
    public static function svg(?string $form = null): string
    {
        return self::form($form ?? 'default');
    }
}
