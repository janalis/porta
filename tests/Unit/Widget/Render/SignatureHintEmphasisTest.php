<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\EditorRenderer;
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
        EditorRenderer::signatureHint($lines, $columns, gutterWidth: 4, cursorScreenRow: 5, hint: self::HINT, styles: Styles::dark(), activeRange: $activeRange);

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
        $at = \strpos($row, needle: "\x1b[1m");
        self::assertNotFalse($at, 'the row should carry a bold run');
        $after = \substr($row, $at + 4);
        $end = \strpos($after, needle: "\x1b[");
        self::assertNotFalse($end, 'the bold run should be closed');

        return AnsiUtils::stripAnsiCodes(\substr($after, offset: 0, length: $end));
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

    public function testARangeTruncationCutOffLeavesTheRowUnemphasised(): void
    {
        // A narrow pane clips the tail; there is then nothing left to bold, and
        // the offsets must not be applied to text that is no longer there.
        $narrow = self::render([11, 20], columns: 18);

        $this->assertStringNotContainsString("\x1b[1m", $narrow);
    }

    public function testNoRangeRendersExactlyAsBefore(): void
    {
        // The no-capability and no-parameters paths, which must not regress.
        $this->assertStringNotContainsString("\x1b[1m", self::render(null));
    }
}
