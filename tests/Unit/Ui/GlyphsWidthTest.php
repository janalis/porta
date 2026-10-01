<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Every glyph in the vocabulary must measure exactly one display column.
 *
 * The whole render path is column arithmetic: a row is composed to an exact
 * width and a row whose `visibleWidth` disagrees with its pane's width throws
 * out of the render loop. A glyph that measures two therefore does not merely
 * look wrong — it makes the row over-wide, the terminal wraps it, and because
 * {@see \Symfony\Component\Tui\Render\ScreenWriter} positions differential
 * repaints with RELATIVE cursor moves from its own row bookkeeping, every
 * later frame then paints one row off. The damage is a cascade, not a cell.
 *
 * Measured rather than asserted in prose: the class docblock used to claim the
 * vocabulary was East Asian Width *Narrow*, and it never was — most of it is
 * *Ambiguous*, which is one column only because terminals conventionally
 * render it so. That is a real assumption, and this is where it is stated.
 */
final class GlyphsWidthTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function glyphs(): iterable
    {
        /** @var array<string, mixed> $constants */
        $constants = new \ReflectionClass(Glyphs::class)->getConstants();
        foreach ($constants as $name => $value) {
            if (!\is_string($value) || '' === $value) {
                continue;
            }

            yield $name => [$name, $value];
        }
    }

    #[DataProvider('glyphs')]
    public function testEveryGlyphIsOneColumnPerCharacter(string $name, string $glyph): void
    {
        $characters = \preg_split('//u', $glyph, flags: \PREG_SPLIT_NO_EMPTY);
        \assert(false !== $characters, description: 'the glyph splits into characters');
        $expected = \count($characters);

        $this->assertSame(
            $expected,
            AnsiUtils::visibleWidth($glyph),
            \sprintf('Glyphs::%s ("%s") must measure one column per character.', $name, $glyph),
        );
    }

    public function testTheVocabularyIsNotEmpty(): void
    {
        // The provider is reflective: a rename that emptied it would make every
        // case above vacuously pass.
        $this->assertGreaterThan(50, \iterator_count(self::glyphs()));
    }

    /**
     * A window's close button is one column in EVERY terminal: never East
     * Asian Ambiguous, which a terminal set for CJK draws two wide. The circled
     * letter ⓧ was, and pushed a title bar a column out on a real screen; the
     * frame's box glyphs are Ambiguous too, but every TUI draws those narrow.
     */
    public function testTheCloseButtonsAreNarrowInEveryTerminal(): void
    {
        if (!\class_exists(\IntlChar::class)) {
            $this->markTestSkipped('the intl extension reads the Unicode width property');
        }
        foreach ([Glyphs::WINDOW_CLOSE, Glyphs::WINDOW_CLOSE_MAC] as $glyph) {
            $this->assertContains(
                \IntlChar::getIntPropertyValue(\mb_ord($glyph), \IntlChar::PROPERTY_EAST_ASIAN_WIDTH),
                [\IntlChar::EA_NEUTRAL, \IntlChar::EA_NARROW, \IntlChar::EA_HALFWIDTH],
                $glyph.' is narrow whatever the terminal',
            );
        }
    }
}
