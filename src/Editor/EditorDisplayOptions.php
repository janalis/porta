<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The editor's six display toggles as one piece of state: inline diagnostics,
 * the line-number mode, the git gutter, sticky scroll, indent guides and
 * auto-pairs.
 *
 * Held by {@see \Porta\Widget\CodeEditorWidget}, which keeps the public verbs
 * ({@see EditorViewOptions}) as delegators — this owns only the values. It takes
 * the widget's invalidate closure, the same idiom as {@see DecorationStore} and
 * the overlay state objects, so a toggle repaints without the caller
 * remembering to.
 *
 * Every setter answers whether the value actually changed, and only repaints
 * then: re-selecting the setting you already have must not flash the pane. The
 * answer also lets the widget hang its own side effect off a real change (it
 * drops stale git marks when the gutter goes away) without repeating the guard.
 */
final class EditorDisplayOptions
{
    private bool $inlineDiagnostics = true;
    private LineNumberMode $lineNumbers = LineNumberMode::Absolute;
    private bool $gitGutter = true;
    private bool $stickyScroll = true;
    private bool $indentGuides = true;
    private bool $autoPairs = true;

    /** @param \Closure(): void $invalidate drops the widget's memoised render lines */
    public function __construct(
        private readonly \Closure $invalidate,
    ) {}

    public function inlineDiagnostics(): bool
    {
        return $this->inlineDiagnostics;
    }

    public function setInlineDiagnostics(bool $on): bool
    {
        return $this->apply($this->inlineDiagnostics, $on);
    }

    public function lineNumbers(): LineNumberMode
    {
        return $this->lineNumbers;
    }

    public function setLineNumbers(LineNumberMode $mode): bool
    {
        if ($mode === $this->lineNumbers) {
            return false;
        }
        $this->lineNumbers = $mode;
        ($this->invalidate)();

        return true;
    }

    public function gitGutter(): bool
    {
        return $this->gitGutter;
    }

    public function setGitGutter(bool $on): bool
    {
        return $this->apply($this->gitGutter, $on);
    }

    public function stickyScroll(): bool
    {
        return $this->stickyScroll;
    }

    public function setStickyScroll(bool $on): bool
    {
        return $this->apply($this->stickyScroll, $on);
    }

    public function indentGuides(): bool
    {
        return $this->indentGuides;
    }

    public function setIndentGuides(bool $on): bool
    {
        return $this->apply($this->indentGuides, $on);
    }

    public function autoPairs(): bool
    {
        return $this->autoPairs;
    }

    public function setAutoPairs(bool $on): bool
    {
        return $this->apply($this->autoPairs, $on);
    }

    /** Set $flag to $on, repainting only on a real change; answers whether it changed. */
    // @mago-expect lint:no-boolean-flag-parameter -- a set-this-flag-to-this-value helper; both bools ARE the operation
    private function apply(bool &$flag, bool $on): bool
    {
        if ($flag === $on) {
            return false;
        }
        $flag = $on;
        ($this->invalidate)();

        return true;
    }
}
