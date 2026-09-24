<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Overlay\State\FindInFilesModal;
use Porta\Overlay\State\FindModalCallbacks;
use Porta\Search\SearchOption;
use Porta\Tests\Support\ComposesFindModal;
use Porta\Tests\Support\Probe;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * How wide the find modal makes itself, and whether the rows it draws fit.
 *
 * Split from {@see FindInFilesModalTest}, which is about what the modal SAYS.
 * This is about the arithmetic underneath, and it earned its own file the day
 * a fixed row was a column too wide for the box: the row-wide truncate spends
 * an overflow on the tail, so the thing lost is whatever the row ends with —
 * which in both cases here was a key hint, the one part of the row a reader
 * cannot reconstruct.
 */
final class FindInFilesModalGeometryTest extends TestCase
{
    use ComposesFindModal;

    /**
     * The scope select is as wide as its widest scope, so the box widens to
     * seat it whole — a scope name is what tells one directory from another.
     */
    public function testTheBoxWidensToSeatTheWidestScopeInItsSelect(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setScopes(['Whole Project', 'A directory whose name runs long'], index: 1);

        $this->assertMatchesRegularExpression('/│ A directory whose name runs long ▾ │/u', $this->screen($modal));
    }

    /**
     * Where the terminal cannot widen the box, the scope's NAME gives way —
     * never the ▾ that says it opens, and never the footer's key for it.
     */
    public function testInATerminalTooNarrowTheScopeNameElidesAndItsAffordancesSurvive(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setScopes(['A directory whose name runs long'], index: 0);

        $screen = AnsiUtils::stripAnsiCodes(\implode("\n", $this->compose($modal, columns: 56)));

        $this->assertStringNotContainsString('A directory whose name runs long', $screen, 'the name is cut');
        $this->assertMatchesRegularExpression('/│ A dir[^│]*… ▾ │/u', $screen, 'from its end, the arrow kept');
    }

    // --- Mutation-sweep gap-closing tests below ------------------------
    /**
     * A row's declared width must equal the width it draws.
     *
     * `optionsRowWidth()` and `footerRowWidth()` exist so `boxWidth()` can max
     * over rows that cannot shrink — which means each row's geometry is summed
     * TWICE, once to size the box and once to build the string. Two independent
     * sums of the same row is precisely the shape that produced this modal's
     * `(Al…`, and nothing was checking they agree: a mutation sweep over the
     * arithmetic (each `+ 2` off by one, the separator dropped) escaped every
     * test here, because every one of them asks whether some text survived
     * rather than whether the two sums match.
     *
     * Both directions matter and they fail differently. Declaring MORE than the
     * row draws leaves a too-wide box — cosmetic, invisible to an assertion
     * about content. Declaring LESS truncates the row, which is the bug itself.
     *
     * @param non-empty-string $locale
     */
    #[DataProvider('rowWidthLocales')]
    public function testEachFixedRowDrawsExactlyTheWidthItDeclares(string $locale, string $scope): void
    {
        $modal = new FindInFilesModal(static fn (): null => null, translator: new Translator($locale));
        $modal->open('Find in Files');
        $modal->setScopes([$scope], index: 0);

        // A box far wider than either row, so the row-wide truncate cannot
        // mask a disagreement by clipping both to the same number.
        $wide = 200;

        // The options row is five fixed tags, measured from optionTags() by
        // both sides; the footer is the row whose sum can drift.
        foreach (['footerRowWidth' => 'footerRow'] as $declared => $drawn) {
            $line = Probe::invoke($modal, $drawn, $wide);
            $this->assertIsString($line);
            $this->assertSame(
                Probe::invoke($modal, $declared),
                AnsiUtils::visibleWidth(\rtrim(AnsiUtils::stripAnsiCodes($line))),
                $drawn.'() draws a different width from the one '.$declared.'() promises boxWidth()',
            );
        }
    }

    /**
     * Locales whose captions differ in length, and a short scope label beside
     * the default one: the two rows trade places for the widest depending on
     * both, so a single case would only ever exercise one of them.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function rowWidthLocales(): iterable
    {
        yield 'en, default scope' => ['en', 'Whole Project'];
        yield 'en, short scope' => ['en', 'src'];
        yield 'fr, short scope' => ['fr', 'src'];
        yield 'de, default scope' => ['de', 'Ganzes Projekt'];
        yield 'ja, short scope' => ['ja', 'src'];
    }

    /**
     * A terminal tall enough for three boxes gets them — legends in the
     * borders, the focused one heavy — and a click anywhere in a box's three
     * rows focuses that box. Below the threshold the fields are one row each
     * (the rest of this suite runs at 24 rows).
     */
    public function testATallTerminalBoxesTheFieldsAndEachBandTakesItsClicks(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview([['path' => 'src/A.php', 'line' => 4, 'text' => 'foo', 'ranges' => [['start' => 0, 'length' => 3]]]]);
        $screen = \array_map(AnsiUtils::stripAnsiCodes(...), $this->compose($modal, rows: 34));
        $box = $modal->lastBox();
        \assert(null !== $box, description: 'the modal laid out a box');

        $find = $box->startRow + 2; // below the title bar and the padding row
        $this->assertMatchesRegularExpression('/┏ Find ━/u', $screen[$find], 'the focused query box, its label a legend');
        $this->assertMatchesRegularExpression('/╭ Replace ─/u', $screen[$find + 3]);
        $this->assertSame($find + 6, $box->optionsRow, 'the options band follows the two boxes');
        $this->assertMatchesRegularExpression('/│ \.\* │/u', $screen[$box->optionsRow + 1], 'its toggles boxed like the fields, their captions on the middle row');
        $this->assertMatchesRegularExpression('/╭ Mask ─/u', $screen[$box->optionsRow + 3]);

        // Each band is three rows: its border rows click the box as well.
        foreach ([$find + 3 => 'Replace', $find + 5 => 'Replace', $box->optionsRow + 3 => 'Mask', $box->optionsRow + 5 => 'Mask', $find => 'Find', $find + 2 => 'Find'] as $row => $label) {
            $this->assertTrue($modal->clickAt($row, $box->col + 3));
            $this->assertMatchesRegularExpression('/┏ '.$label.' ━/u', \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->compose($modal, rows: 34))), "row {$row} focuses {$label}");
        }

        // A toggle answers on every row of its box: the top and bottom
        // borders of .* (inner columns 1-6, after the leading space).
        $toggled = [];
        $modal->setCallbacks(new FindModalCallbacks(onToggleOption: static function (SearchOption $option) use (&$toggled): void {
            $toggled[] = $option;
        }));
        $this->assertTrue($modal->clickAt($box->optionsRow, $box->col + 2 + 3));
        $this->assertTrue($modal->clickAt($box->optionsRow + 2, $box->col + 2 + 3));
        $this->assertSame([SearchOption::Regex, SearchOption::Regex], $toggled);
        $modal->clickAt($box->optionsRow + 3, $box->col + 2 + 3);
        $this->assertCount(2, $toggled, 'the row below the band is the mask, not the toggle');
    }

    /**
     * Compact, the three fields' rails stand in two columns, as a form's
     * inputs do: labels padded to one width on the left, hints padded to one
     * width on the right. Unpadded, each closing rail stopped where its own
     * hint began, so Find, Replace and Mask ended at three different columns.
     */
    public function testCompactFieldsLineTheirRailsUp(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $screen = \array_map(AnsiUtils::stripAnsiCodes(...), $this->compose($modal));

        $rails = [];
        foreach ($screen as $row) {
            if (1 !== \preg_match('/│ (Find|Replace|Mask) +([│┃])/u', $row, $m)) {
                continue;
            }
            $opening = (int) \mb_strpos($row, $m[0]) + \mb_strlen($m[0]) - 1; // the rail right after the label
            // The field's closing rail is the next of its own after the opening
            // one — the side column beyond carries rails of its own.
            $rails[$m[1]] = [$opening, \mb_strpos($row, $m[2], $opening + 1)];
        }

        $this->assertCount(3, $rails, 'all three fields are drawn compact at 24 rows');
        $this->assertCount(1, \array_unique(\array_map(\serialize(...), $rails)), 'and their opening and closing rails share two columns');
    }
}
