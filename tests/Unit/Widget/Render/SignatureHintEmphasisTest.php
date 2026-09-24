<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\CaretPopupRenderer;
use Porta\Widget\Render\CaretSide;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The signature popup emphasises the argument you are typing.
 *
 * Every case asserts the row's VISIBLE width as well as its text: the emphasis
 * splices SGR into a row whose width was computed from the plain string, which
 * is exactly how this codebase produces an over-wide, garbled line.
 */
final class SignatureHintEmphasisTest extends TestCase
{
    private const string HINT = 'fn(int $a, string $b)';

    /** @param array{0: int, 1: int}|null $activeRange */
    private static function render(?array $activeRange, int $columns = 60): string
    {
        $lines = \array_fill(start_index: 0, count: 10, value: \str_repeat(' ', $columns));
        CaretPopupRenderer::signatureHint($lines, $columns, gutterWidth: 4, cursorScreenRow: 5, hint: self::HINT, styles: Styles::dark(), activeRange: $activeRange);

        foreach ($lines as $line) {
            if (\str_contains(AnsiUtils::stripAnsiCodes($line), 'fn(int')) {
                return $line;
            }
        }

        self::fail('the hint should be on one of the rows');
    }

    /**
     * The text between the bold-on and the next SGR reset — WHICH characters
     * got emphasised, not merely that some did. An off-by-one in the pad
     * offset still bolds something, and every other assertion here still
     * passes; a mutant proved exactly that.
     */
    private static function boldRun(string $row): string
    {
        // Bold and underlined: the emphasis must read in monochrome, where
        // weight alone is easy to miss.
        self::assertSame(1, \preg_match('/\x1b\[1m\x1b\[4m([^\x1b]*)\x1b\[/', $row, $match), 'the row should carry one bold, underlined run');

        return $match[1];
    }

    public function testTheACTIVEPARAMETERIsTheTextThatGoesBold(): void
    {
        // 'string $b' sits at bytes 11..20 of the hint.
        $this->assertSame('string $b', self::boldRun(self::render([11, 20])));
    }

    public function testTheFIRSTParameterEmphasisesFromTheRightOffsetToo(): void
    {
        // The leading pad space is added by the renderer, so a first parameter
        // starting at byte 3 is the case an off-by-one shows up in soonest.
        $this->assertSame('int $a', self::boldRun(self::render([3, 9])));
    }

    public function testTheActiveParameterIsBoldAndNothingElseChanges(): void
    {
        $plain = self::render(null);
        $emphasised = self::render([11, 20]);

        $this->assertNotSame($plain, $emphasised, 'the range must actually change the row');
        $this->assertSame(AnsiUtils::stripAnsiCodes($plain), AnsiUtils::stripAnsiCodes($emphasised));
    }

    public function testEmphasisDoesNotChangeTheROWSWIDTH(): void
    {
        $this->assertSame(
            AnsiUtils::visibleWidth(self::render(null)),
            AnsiUtils::visibleWidth(self::render([11, 20])),
        );
    }

    /**
     * A signature wider than the popup is cut AROUND the argument being
     * typed: an end-cut hid it from the third comma on, which is when a long
     * signature is worth reading. The row stays its width, the cuts marked.
     */
    public function testANarrowPopupKeepsTheActiveArgumentInView(): void
    {
        $lines = \array_fill(start_index: 0, count: 10, value: \str_repeat(' ', times: 22));
        CaretPopupRenderer::signatureHint($lines, columns: 22, gutterWidth: 4, cursorScreenRow: 5, hint: self::HINT, styles: Styles::dark(), activeRange: [11, 20]);
        $row = $lines[3];

        $this->assertSame('string $b', self::boldRun($row));
        $this->assertStringContainsString('│ …, string $b) │', AnsiUtils::stripAnsiCodes($row), 'the head cut, the argument whole');
        $this->assertSame(22, AnsiUtils::visibleWidth($row));

        // Room for the argument and nothing else: it stands alone.
        $lines = \array_fill(start_index: 0, count: 10, value: \str_repeat(' ', times: 18));
        CaretPopupRenderer::signatureHint($lines, columns: 18, gutterWidth: 4, cursorScreenRow: 5, hint: self::HINT, styles: Styles::dark(), activeRange: [11, 20]);
        $this->assertStringContainsString('│ string $b │', AnsiUtils::stripAnsiCodes($lines[3]));
    }

    /** The overload counter trails the signature, dim; the row keeps its width. */
    public function testTheOverloadCounterTrailsTheSignature(): void
    {
        $lines = \array_fill(start_index: 0, count: 10, value: \str_repeat(' ', times: 60));
        CaretPopupRenderer::signatureHint($lines, columns: 60, gutterWidth: 4, cursorScreenRow: 5, hint: self::HINT, styles: Styles::dark(), activeRange: [3, 9], counter: '2/3');

        $this->assertStringContainsString('│ fn(int $a, string $b)  2/3 │', AnsiUtils::stripAnsiCodes($lines[3]));
        $this->assertStringContainsString("\x1b[2m", $lines[3]);
        $this->assertSame(60, AnsiUtils::visibleWidth($lines[3]));
    }

    public function testNoRangeRendersExactlyAsBefore(): void
    {
        // The no-capability and no-parameters paths, which must not regress.
        $this->assertStringNotContainsString("\x1b[1m", self::render(null));
    }

    /**
     * The hint takes the side of the caret no other popup holds: below when
     * above is taken, above when below is, and not at all when it has only
     * the taken side left.
     */
    public function testTheHintTakesTheFreeSide(): void
    {
        $at = static function (int $row, ?CaretSide $taken, int $rows = 10): ?int {
            $lines = \array_fill(start_index: 0, count: $rows, value: \str_repeat(' ', times: 60));
            CaretPopupRenderer::signatureHint($lines, columns: 60, gutterWidth: 4, cursorScreenRow: $row, hint: self::HINT, styles: Styles::dark(), taken: $taken);

            return \array_key_first(\array_filter($lines, static fn (string $line): bool => \str_contains(AnsiUtils::stripAnsiCodes($line), 'fn(int')));
        };

        // The text row is the frame's middle one.
        $this->assertSame(3, $at(5, taken: null), 'above by preference');
        $this->assertSame(7, $at(5, CaretSide::Above), 'below when a list opened above');
        $this->assertSame(3, $at(5, CaretSide::Below));
        $this->assertSame(4, $at(2, taken: null), 'no framed room above: below');
        $this->assertSame(1, $at(2, CaretSide::Below, rows: 4), 'below taken: the bare row above');
        $this->assertNull($at(0, CaretSide::Below, rows: 2), 'nowhere left: the hint yields');
    }
}
