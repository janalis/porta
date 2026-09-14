<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\EditorScroll;
use Porta\Editor\MultiCaret;
use Porta\Overlay\State\OverlayHost;

/**
 * Typed reader of the editor widget's private view state — the hot concepts
 * the suites (and the round-11 E2e await gates) observe, kept one-call so
 * src carries no test-only getters. Takes `object` because half the sites
 * pass `$tui->getById('editor')`.
 */
final class EditorState
{
    /** The centered list picker (branches, recent files, quick fixes…) is open. */
    public static function pickerOpen(object $editor): bool
    {
        return null !== Probe::get(self::host($editor), 'overlay');
    }

    /** The mini prompt (rename, branch name…) is open. */
    public static function promptOpen(object $editor): bool
    {
        return null !== Probe::get(self::host($editor), 'prompt');
    }

    /** The viewport scroll state. */
    public static function scroll(object $editor): EditorScroll
    {
        $scroll = Probe::object($editor, 'scroll');
        \assert($scroll instanceof EditorScroll, description: 'the editor holds its scroll state');

        return $scroll;
    }

    /** The multi-cursor model. */
    public static function multiCaret(object $editor): MultiCaret
    {
        $multiCaret = Probe::object($editor, 'multiCaret');
        \assert($multiCaret instanceof MultiCaret, description: 'the editor holds its multi-caret state');

        return $multiCaret;
    }

    private static function host(object $editor): OverlayHost
    {
        $host = Probe::object($editor, 'overlayHost');
        \assert($host instanceof OverlayHost, description: 'the editor holds its overlay host');

        return $host;
    }
}
