<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The two ways a test reads "what flashed" off the status bar, named so a
 * suite picks deliberately. {@see flashText()} reads the MODEL
 * (the status bar's private $flash, via Probe) — independent of truncation and wrap
 * points, the same argument {@see Toasts} makes for the toast stack.
 * {@see statusRow()} reads the RENDERED row — for assertions about what
 * actually paints (truncation, the flash winning the width fight over the
 * key legend).
 */
trait StatusReads
{
    /** The status bar's live flash message (model read via Probe — src carries no test-only getter), or '' when none. */
    private function flashText(StatusBarWidget $status): string
    {
        /** @var array{text: string}|null $flash */
        $flash = Probe::get($status, 'flash');

        return \is_array($flash) ? $flash['text'] : '';
    }

    /** The composed status row, ANSI-stripped (rendered read). */
    private function statusRow(StatusBarWidget $status, int $columns = 100): string
    {
        return AnsiUtils::stripAnsiCodes(\implode(' ', $status->render(new RenderContext($columns, 1))));
    }
}
