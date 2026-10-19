<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\Theme\JsNumber;
use Porta\GraphViewer\Mermaid\Theme\Khroma;
use Porta\GraphViewer\Mermaid\Theme\KhromaChannels;

final class KhromaTest extends TestCase
{
    private const string REFERENCE = __DIR__.'/../fixtures/mermaid/themes/khroma-reference.json';

    #[DataProvider('operations')]
    public function testOperationsMatchKhroma(string $color, string $operation, mixed $expected, ?string $error): void
    {
        if (null !== $error) {
            $this->expectException(\InvalidArgumentException::class);
        }
        $actual = match ($operation) {
            'parse' => Khroma::toRgba($color),
            'darken10' => Khroma::darken($color, 10),
            'lighten25' => Khroma::lighten($color, 25),
            'lighten0' => Khroma::lighten($color, 0),
            'invert' => Khroma::invert($color),
            'isDark' => Khroma::isDark($color),
            'adjustHue' => Khroma::adjust($color, (object) ['h' => -120]),
            'adjustAll' => Khroma::adjust($color, (object) ['h' => 180, 's' => -15, 'l' => 5]),
            'adjustZero' => Khroma::adjust($color, (object) ['h' => 0, 's' => 0]),
            'mkBorderLight' => Khroma::adjust($color, (object) ['s' => -40, 'l' => -10]),
            'mkBorderDark' => Khroma::adjust($color, (object) ['s' => -40, 'l' => 10]),
            'adjustRgb' => Khroma::adjust($color, (object) ['r' => 10, 'g' => -300, 'b' => 0.5]),
            'alpha' => Khroma::change($color, ['a' => 0.5]),
            'alphaOver' => Khroma::change($color, ['a' => KhromaChannels::clamp('a', 3)]),
        };
        $this->assertSame($expected, $actual);
    }

    public static function operations(): iterable
    {
        foreach (self::reference()['cases'] as $index => $case) {
            if ('rgba' === $case['op']) {
                continue;
            }
            yield $index.' '.$case['op'].' '.$case['color'] => [$case['color'], $case['op'], $case['value'] ?? null, $case['error'] ?? null];
        }
    }

    /** @param list<float> $arguments */
    #[DataProvider('channels')]
    public function testRgbaChannelsMatchKhroma(array $arguments, string $expected): void
    {
        $this->assertSame($expected, Khroma::rgba(...$arguments));
    }

    public static function channels(): iterable
    {
        foreach (self::reference()['cases'] as $case) {
            if ('rgba' !== $case['op']) {
                continue;
            }
            yield \implode(',', $case['args']) => [\array_map(\floatval(...), $case['args']), $case['value']];
        }
    }

    #[DataProvider('numbers')]
    public function testNumbersPrintLikeJavaScript(float $number, string $text): void
    {
        $this->assertSame($text, JsNumber::format($number));
    }

    public static function numbers(): iterable
    {
        foreach (self::reference()['numbers'] as $index => $case) {
            yield $index.' '.$case['text'] => [(float) $case['number'], $case['text']];
        }
        yield 'negative zero' => [-0.0, '0'];
        yield 'NaN' => [\NAN, 'NaN'];
        yield 'Infinity' => [\INF, 'Infinity'];
        yield '-Infinity' => [-\INF, '-Infinity'];
    }

    public function testDrawingChannelsKeepFractionsAndOpacity(): void
    {
        $this->assertSame([170.0, 187.0, 204.0, 0x88 / 255], Khroma::rgbaOf('#abc8'));
        $this->assertSame([0.0, 0.0, 0.0, 0.0], Khroma::rgbaOf('transparent'));
        $this->assertEqualsWithDelta([153.0, 153.0, 153.0, 1.0], Khroma::rgbaOf('hsl(0, 0%, 60%)'), delta: 1e-9);
    }

    public function testJavaScriptRoundingRoundsHalvesUp(): void
    {
        $this->assertSame(3.0, Khroma::round(2.5));
        $this->assertSame(-2.0, Khroma::round(-2.5));
        $this->assertSame(0.0, Khroma::round(0.499_999_999_999_999_94));
    }

    public function testPrimitivesFailWhereJavaScriptWritesTheirChannels(): void
    {
        $this->assertTrue(Khroma::isDark(0));
        $this->assertTrue(Khroma::isDark(false));
        foreach ([static fn (): string => Khroma::lighten(0, 10), static fn (): string => Khroma::invert(false), static fn (): bool => Khroma::isDark(null)] as $call) {
            try {
                $call();
                $this->fail('A primitive color was accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testChannelSpacesCannotBeMixed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Khroma::adjust('#abcdef', (object) ['h' => 10, 'r' => 10]);
    }

    /** @return array{cases: list<array{color?: string, op: string, value?: mixed, error?: string, args?: list<float|int>}>, numbers: list<array{number: float|int, text: string}>} */
    private static function reference(): array
    {
        /** @var array{cases: list<array{color?: string, op: string, value?: mixed, error?: string, args?: list<float|int>}>, numbers: list<array{number: float|int, text: string}>} */
        return \json_decode((string) \file_get_contents(self::REFERENCE), associative: true, flags: \JSON_THROW_ON_ERROR);
    }
}
