<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Tests\Unit\Core;

use ErikWang2013\Xhprof\Core\Mascot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MascotTest extends TestCase
{
    /** 仓库根：tests/Unit/Core → 上三级 */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    #[Test]
    public function formsListsDefaultAndTwelveExpressions(): void
    {
        $forms = Mascot::forms();

        self::assertCount(13, $forms);
        self::assertSame('默认', $forms['default']['cn']);
        self::assertNull($forms['default']['shortcode']);
        self::assertSame('笑', $forms['smile']['cn']);
        self::assertSame('Smile', $forms['smile']['en']);
        self::assertSame(':emoji-smile:', $forms['smile']['shortcode']);
    }

    /**
     * 代码里的形态名必须与随包发布的 SVG 文件集合一一对应：加表情只改文件、
     * 或只改 FORMS，两条路都会在这里红。
     */
    #[Test]
    public function expressionNamesMatchTheShippedSvgFiles(): void
    {
        $expected = [];
        foreach (glob(self::root() . '/docs/pet-expressions/*.svg') ?: [] as $file) {
            $expected[] = basename($file, '.svg');
        }
        sort($expected);

        $actual = array_keys(Mascot::forms());
        $actual = array_values(array_filter(
            $actual,
            static fn(string $name): bool => $name !== 'default'
        ));
        sort($actual);

        self::assertSame($expected, $actual, 'forms() 与 docs/pet-expressions/ 的文件集合不一致');
    }

    /**
     * 非默认形态的短码约定：:emoji-<形态名>:（README 里展示的就是这一列）。
     */
    #[Test]
    public function everyExpressionCarriesItsEmojiShortcode(): void
    {
        foreach (Mascot::forms() as $name => $meta) {
            if ($name === 'default') {
                continue;
            }
            self::assertSame(':emoji-' . $name . ':', $meta['shortcode'], "form {$name}");
        }
    }

    #[Test]
    public function defaultFormIsThePackagedPetSvg(): void
    {
        $packaged = (string) file_get_contents(self::root() . '/src/html/pet.svg');

        self::assertNotSame('', $packaged);
        self::assertSame($packaged, Mascot::form('default'));
    }

    #[Test]
    public function everyFormIsWellFormedAndInert(): void
    {
        foreach (array_keys(Mascot::forms()) as $name) {
            $svg = Mascot::form($name);

            self::assertNotSame('', $svg, "form {$name}");
            self::assertStringNotContainsString('<script', $svg, "form {$name}");

            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($svg);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            self::assertNotFalse($xml, "form {$name} must be valid XML");
            self::assertSame('svg', $xml->getName(), "form {$name} root");
        }
    }

    #[Test]
    public function svgDefaultsToDefaultFormAndAcceptsNamedForm(): void
    {
        self::assertSame(Mascot::form('default'), Mascot::svg());
        self::assertSame(Mascot::form('smile'), Mascot::svg('smile'));
    }

    #[Test]
    public function formNameIsNormalized(): void
    {
        self::assertSame(Mascot::form('smile'), Mascot::form(' SMILE '));
    }

    #[Test]
    public function unknownFormThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Mascot::form('unknown');
    }

    #[Test]
    public function pathTraversalFormNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Mascot::form('../pet');
    }
}
