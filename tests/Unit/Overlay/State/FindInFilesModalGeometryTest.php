<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Overlay\State\FindInFilesModal;
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
     * The box has to seat its own toolbar row.
     *
     * The width is a max over the title, the query and the visible result rows
     * — everything that VARIES. The options row does not vary: five fixed tags,
     * a translated caption, the scope label and the key hint. Left out of that
     * max it was the one row guaranteed to overflow, and the row-wide truncate
     * spends an overflow on the TAIL, so `(Alt+S)` rendered as `(Al…` — the
     * modal eating the only thing that says how to change the scope.
     */
    public function testTheBoxWidensToSeatTheScopeRowsKeyHint(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setScopes(['A directory whose name runs long'], index: 0);

        $screen = $this->screen($modal);

        $this->assertStringContainsString('Scope: A directory whose name runs long (Alt+S)', $screen, 'the whole row, key included');
    }

    /**
     * And when the terminal genuinely cannot fit it, the LABEL gives way —
     * never the key. The label is a value the results already show; the key is
     * the only affordance for changing it.
     */
    public function testInATerminalTooNarrowToWidenTheLabelElidesAndTheKeySurvives(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setScopes(['A directory whose name runs long'], index: 0);

        $screen = AnsiUtils::stripAnsiCodes(\implode("\n", $this->compose($modal, columns: 56)));

        $this->assertStringContainsString('(Alt+S)', $screen, 'the key is never what gets cut');
        $this->assertStringNotContainsString('A directory whose name runs long', $screen, 'the label is');
        $this->assertStringContainsString('Scope: A', $screen, 'and what is left of it still reads as the scope');
    }

    /**
     * The footer is counted by the width too, and it is the options row's
     * short-label case that needs it.
     *
     * With the default scope the options row is the wider of the two fixed
     * rows in every locale we ship, so it alone sets the floor. A SHORT scope
     * label shrinks it — and short is what a real project has: this repo's own
     * `search-scopes.json` holds `src`, `docs`, `config`. Pick one of those in
     * a locale whose labels are long and the footer becomes the widest row in
     * the modal, with `(Alt+Enter)` at the end of it, first against the wall.
     */
    public function testAShortScopeLabelLeavesTheFooterAsTheWidestRow(): void
    {
        $modal = new FindInFilesModal(static fn (): null => null, translator: new Translator('fr'));
        $modal->open('Find in Files');
        $modal->setScopes(['src'], index: 0);

        // Read the FOOTER row, not the screen: 'Portée' is also the options
        // row's scope caption, so a screen-wide assertion passes on the wrong
        // row while the footer is cut behind it.
        $rows = \array_values(\array_filter(\explode("\n", $this->screen($modal)), static fn (string $r): bool => \str_contains($r, '(Alt+Enter)')));

        $this->assertCount(1, $rows, 'the footer, and only the footer, names this key');
        $this->assertStringContainsString('Portée', $rows[0], 'the action after the key is still on the row');
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

        foreach (['optionsRowWidth' => 'optionsRowLine', 'footerRowWidth' => 'footerRow'] as $declared => $drawn) {
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
}
