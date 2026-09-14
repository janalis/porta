<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * The one thing every paging list owes its user: Page Down may not step further
 * than the screen showed.
 *
 * Each of these widgets tells {@see \Porta\Ui\ListSelection::follow()} how many
 * ENTRY rows it drew, and tells {@see \Porta\Ui\ListNavigation::handleKey()}
 * how far a page moves. When the second number is the pane height and the first
 * is the pane height minus a title, the gap opens one row per press — and
 * because `follow()` only scrolls far enough to bring the selection back into
 * view, the rows in between are never drawn at all. Nothing looks wrong on any
 * single frame, which is why four widgets carried this at once.
 *
 * Asserted the way a user would notice it: page to the bottom and check that
 * every row was on SOME screen along the way. No arithmetic to get wrong, and
 * it holds whatever chrome the widget grows later.
 */
trait PagesThroughRows
{
    /** Satisfied by TestCase, as {@see RendersExactWidth} declares its own. */
    abstract public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void;

    /**
     * @param callable(): string $screen   the ANSI-stripped screen, re-rendered
     * @param callable(): void   $pageDown one Page Down keypress
     * @param list<string>       $labels   a unique marker per entry, top to bottom
     */
    private static function assertPagingShowsEveryRow(callable $screen, callable $pageDown, array $labels): void
    {
        $seen = [];
        // One press per entry is far more than reaching the end can take; the
        // loop leaves as soon as every label has been accounted for.
        for ($press = 0; $press <= \count($labels); ++$press) {
            $text = $screen();
            foreach ($labels as $label) {
                if (!str_contains($text, $label)) {
                    continue;
                }
                $seen[$label] = true;
            }
            if (\count($seen) === \count($labels)) {
                break;
            }
            $pageDown();
        }

        self::assertSame(
            [],
            array_values(array_diff($labels, array_keys($seen))),
            'paging to the bottom scrolled past rows that were never drawn',
        );
    }
}
