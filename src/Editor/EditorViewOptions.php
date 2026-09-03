<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The one editor display toggle that is a ROLE rather than a call on
 * {@see EditorDisplayOptions}. Implemented by the editor widget, and reached by
 * the plugin API.
 *
 * It used to carry all eleven toggles and the widget forwarded every one of
 * them, which made each new view option a method on the largest class in the
 * codebase. Soft wrap was the one that would not fit; the rest are
 * `CodeEditorWidget::display()` now.
 */
interface EditorViewOptions
{
    /**
     * Show or hide the vcs change strip. The ONLY view option that is a role
     * rather than a `CodeEditorWidget::display()` call, because turning the
     * strip off also drops the marks behind it — the other ten moved to
     * {@see EditorDisplayOptions} when soft wrap made the widget's method
     * ceiling bite.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- $on is the desired STATE, as on the implementor
    public function setVcsGutter(bool $on): void;
}
