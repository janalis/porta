<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\Theme\MermaidThemes;

final class MermaidThemesTest extends TestCase
{
    private const string REFERENCE = __DIR__.'/../fixtures/mermaid/themes/theme-reference.json';

    /** @param array<string, mixed>|null $expected */
    #[DataProvider('reference')]
    public function testEveryThemeAndOverrideSetMatchesThePinnedBuild(string $theme, ?\stdClass $overrides, ?array $expected): void
    {
        $themes = MermaidThemes::bundled();
        if (null === $expected) {
            $this->expectException(\InvalidArgumentException::class);
        }
        $actual = self::plain($themes->variables($theme, null === $overrides ? null : self::copy($overrides)));
        $this->assertSame(self::plain($expected), $actual);
    }

    public static function reference(): iterable
    {
        $reference = \json_decode((string) \file_get_contents(self::REFERENCE), associative: false, flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $reference);
        self::assertSame('12.1.0', $reference->mermaid);
        self::assertSame(MermaidThemes::NAMES, \array_values(\array_unique(\array_map(static fn (\stdClass $case): string => $case->theme, $reference->cases))));
        foreach ($reference->cases as $case) {
            $overrides = $reference->overrides->{$case->overrides};
            $expected = \property_exists($case, 'variables') ? \json_decode(\json_encode($case->variables, flags: \JSON_THROW_ON_ERROR), associative: true, flags: \JSON_THROW_ON_ERROR) : null;
            yield $case->theme.' '.$case->overrides => [$case->theme, $overrides, $expected];
        }
    }

    public function testCalculationDoesNotMutateTheAuthoredOverrides(): void
    {
        $overrides = (object) ['primaryColor' => '#ff0000', 'xyChart' => (object) ['titleColor' => '#123456']];
        $copy = self::copy($overrides);
        MermaidThemes::bundled()->variables('base', $overrides);
        $this->assertEquals($copy, $overrides);
    }

    public function testUnknownThemesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MermaidThemes::bundled()->variables('sepia');
    }

    private static function copy(\stdClass $value): \stdClass
    {
        $copy = \json_decode(\json_encode($value, flags: \JSON_THROW_ON_ERROR), associative: false, flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $copy);

        return $copy;
    }

    /** JSON.stringify drops undefined and prints NaN as null; numbers compare by value. */
    private static function plain(mixed $value): mixed
    {
        $value = $value instanceof \stdClass ? \get_object_vars($value) : $value;
        $plain = \is_array($value) ? \array_map(self::plain(...), $value) : $value;
        if (\is_array($plain) && !\array_is_list($plain)) {
            \ksort($plain);
        }

        $number = \is_float($plain) || \is_int($plain) ? (float) $plain : null;

        return match (true) {
            null === $number => $plain,
            \is_nan($number) => null,
            default => $number,
        };
    }
}
