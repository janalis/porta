<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Lsp\SemanticTokensController;

/**
 * The delta decode, which is where a semantic token goes wrong silently.
 *
 * Every assertion here names the exact expected token rather than a count: a
 * decoder that drifts by one column, or restarts a line it should have
 * continued, still produces the right NUMBER of tokens while colouring the
 * wrong characters.
 */
final class SemanticTokensDecodeTest extends TestCase
{
    private const array TYPES = ['method', 'property', 'variable'];
    private const array MODS = ['static', 'deprecated'];

    public function testDeltasAccumulateAcrossLinesAndRestartTheColumn(): void
    {
        // line 1 char 4; then same line +7; then two lines on at char 2.
        $data = [1, 4, 5, 1, 0, 0, 7, 3, 0, 0, 2, 2, 4, 2, 0];

        self::assertSame(
            [
                1 => [
                    ['col' => 4, 'endCol' => 9, 'type' => 'property', 'modifiers' => []],
                    ['col' => 11, 'endCol' => 14, 'type' => 'method', 'modifiers' => []],
                ],
                3 => [
                    ['col' => 2, 'endCol' => 6, 'type' => 'variable', 'modifiers' => []],
                ],
            ],
            SemanticTokensController::decode($data, self::TYPES, self::MODS),
        );
    }

    public function testColumnRestartsRatherThanAccumulatingOnANewLine(): void
    {
        // Second token is one line on at char 1. If the decoder kept adding to
        // the running column it would land at 21, not 1.
        $decoded = SemanticTokensController::decode([0, 20, 2, 0, 0, 1, 1, 2, 0, 0], self::TYPES, self::MODS);

        self::assertSame(1, $decoded[1][0]['col']);
    }

    public function testModifierBitsIndexIntoTheServersOwnLegend(): void
    {
        // bit 0 = static, bit 1 = deprecated in THIS legend; 3 = both.
        $both = SemanticTokensController::decode([0, 0, 3, 0, 3], self::TYPES, self::MODS);
        $deprecatedOnly = SemanticTokensController::decode([0, 0, 3, 0, 2], self::TYPES, self::MODS);

        self::assertSame(['static', 'deprecated'], $both[0][0]['modifiers']);
        self::assertSame(['deprecated'], $deprecatedOnly[0][0]['modifiers']);
    }

    public function testATypeIndexOutsideTheLegendIsSkippedButTheWalkContinues(): void
    {
        // First token names type 9, which this legend does not have. The second
        // must still land on the right line and column, which it only does if
        // the skip happened AFTER the running position was advanced.
        $decoded = SemanticTokensController::decode([0, 0, 3, 9, 0, 1, 2, 4, 2, 0], self::TYPES, self::MODS);

        self::assertArrayNotHasKey(0, $decoded);
        self::assertSame([['col' => 2, 'endCol' => 6, 'type' => 'variable', 'modifiers' => []]], $decoded[1]);
    }

    public function testAZeroLengthTokenIsDropped(): void
    {
        self::assertSame([], SemanticTokensController::decode([0, 0, 0, 1, 0], self::TYPES, self::MODS));
    }

    public function testATrailingPartialTupleIsIgnoredRatherThanReadPast(): void
    {
        // Six entries: one whole tuple and one stray value.
        $decoded = SemanticTokensController::decode([0, 0, 3, 1, 0, 7], self::TYPES, self::MODS);

        self::assertSame([['col' => 0, 'endCol' => 3, 'type' => 'property', 'modifiers' => []]], $decoded[0]);
    }

    public function testALongPartialTailIsNotWalkedIntoAtAll(): void
    {
        // NINE entries: one whole tuple and a four-value tail. The tail is
        // longer than the stride, which is what separates a correct
        // round-down-to-a-multiple-of-five from one that merely looks right on
        // a single stray value — the latter walks off the end of the array.
        $decoded = SemanticTokensController::decode([0, 0, 3, 1, 0, 1, 1, 2, 0], self::TYPES, self::MODS);

        self::assertSame([0], array_keys($decoded));
        self::assertCount(1, $decoded[0]);
    }

    public function testANegativeDeltaThatWalksAboveTheFirstLineIsDropped(): void
    {
        // Servers should not send this; the guard exists because a negative
        // line would key the map with a line that cannot be painted.
        $decoded = SemanticTokensController::decode([-1, 0, 3, 1, 0], self::TYPES, self::MODS);

        self::assertSame([], $decoded);
    }

    public function testANegativeColumnIsDroppedButLaterTokensSurvive(): void
    {
        // First token walks the column negative; the second is on a new line,
        // so its column restarts and it must still be decoded.
        $decoded = SemanticTokensController::decode([0, -4, 3, 1, 0, 1, 2, 4, 2, 0], self::TYPES, self::MODS);

        self::assertSame([1], array_keys($decoded));
        self::assertSame([['col' => 2, 'endCol' => 6, 'type' => 'variable', 'modifiers' => []]], $decoded[1]);
    }

    public function testAnEmptyLegendYieldsNothingRatherThanUntypedTokens(): void
    {
        self::assertSame([], SemanticTokensController::decode([0, 0, 3, 0, 0], [], self::MODS));
    }
}
