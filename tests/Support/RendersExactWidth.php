<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * A widget may not render a row wider than the pane it was given.
 *
 * The renderer enforces this by throwing `RenderException`, which escapes the
 * render loop — so a widget that miscounts does not garble a line, it takes the
 * IDE down. That is not hypothetical: `CodeEditorWidget` did exactly that
 * twenty-one times in this repo's own `.porta/error.log`, because its gutter
 * had no ceiling while its content had a floor.
 *
 * Two copies of the assertion were already living privately in widget tests
 * when the crash was found, and neither was ever pointed at a hostile width.
 * This is the shared one, plus the sweep that would have caught it.
 */
trait RendersExactWidth
{
    /** Satisfied by TestCase, the way {@see \Porta\Widget\Support\PanelRows} names AbstractWidget's. */
    abstract public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void;

    abstract public static function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void;

    abstract public static function assertLessThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void;

    /**
     * @param string[] $rows
     */
    private static function assertExactWidth(array $rows, int $width, string $context = ''): void
    {
        $where = '' === $context ? '' : " ({$context})";
        foreach ($rows as $i => $row) {
            self::assertSame($width, AnsiUtils::visibleWidth($row), "row {$i} must be exactly {$width} columns{$where}");
        }
    }

    /**
     * Render at every width from 1 to $upTo and assert no row EXCEEDS the pane.
     *
     * Not equality: the renderer throws only on a row that is too wide, and a
     * widget may legitimately be narrower than the pane it sits in — the
     * vertical tab strip asks for a fixed 24 columns and the layout pads the
     * rest. Equality would have failed it for behaving correctly.
     *
     * Every width, not a chosen small one: the widths that broke the editor
     * were 1 through 8, and which of them breaks depends on the gutter's own
     * inputs — the line count and whether the vcs strip is on.
     */
    private static function assertFitsEveryWidth(AbstractWidget $widget, int $upTo = 20, int $paneRows = 6): void
    {
        // A widget with nothing to draw renders no rows, and a loop over no
        // rows asserts nothing while reporting success. Refuse that: an empty
        // pane satisfies "every row fits" the way an empty pane satisfies
        // "the content stopped changing".
        self::assertNotSame(
            [],
            $widget->render(new RenderContext($upTo, $paneRows)),
            'the widget rendered nothing even at its widest, so this sweep would prove nothing',
        );

        foreach (\range(start: 1, end: $upTo) as $columns) {
            foreach ($widget->render(new RenderContext($columns, $paneRows)) as $i => $row) {
                self::assertLessThanOrEqual(
                    $columns,
                    AnsiUtils::visibleWidth($row),
                    "row {$i} may not exceed the {$columns}-column pane",
                );
            }
        }
    }
}
