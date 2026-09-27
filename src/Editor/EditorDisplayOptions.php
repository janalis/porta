<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The editor's display options as one piece of state: where diagnostic
 * messages and inlay hints show, the line-number mode, the git gutter, sticky
 * scroll, indent guides, auto-pairs and the rest below.
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
    public private(set) DiagnosticMessageMode $diagnosticMessages = DiagnosticMessageMode::Inline;
    public private(set) InlayHintMode $inlayHints = InlayHintMode::Inline;
    public private(set) LineNumberMode $lineNumbers = LineNumberMode::Absolute;
    public private(set) bool $vcsGutter = true;
    public private(set) bool $stickyScroll = true;

    /**
     * PhpStorm's method separators, off by default: a rule between members is
     * a matter of taste, and a terminal already has fewer pixels to spend on
     * chrome than a graphical editor.
     */
    public private(set) bool $methodSeparators = false;

    /**
     * PhpStorm's Show Whitespaces, off by default: it answers a question you
     * only sometimes have, and a terminal has one intensity to say it with.
     */
    public private(set) bool $showWhitespace = false;
    public private(set) bool $indentGuides = true;

    /** Off by default: horizontal scroll is the long-line behaviour this editor has always had. */
    public private(set) bool $softWrap = false;

    /** Off by default: a lens costs a round trip per save and a row per declaration. */
    public private(set) bool $codeLenses = false;
    public private(set) bool $autoPairs = true;

    /** @param \Closure(): void $invalidate drops the widget's memoised render lines */
    public function __construct(
        private readonly \Closure $invalidate,
    ) {}

    public function setDiagnosticMessages(DiagnosticMessageMode $mode): bool
    {
        return $this->apply($this->diagnosticMessages, $mode);
    }

    public function setInlayHints(InlayHintMode $mode): bool
    {
        return $this->apply($this->inlayHints, $mode);
    }

    public function setLineNumbers(LineNumberMode $mode): bool
    {
        return $this->apply($this->lineNumbers, $mode);
    }

    public function setVcsGutter(bool $on): bool
    {
        return $this->apply($this->vcsGutter, $on);
    }

    public function setStickyScroll(bool $on): bool
    {
        return $this->apply($this->stickyScroll, $on);
    }

    public function setMethodSeparators(bool $on): bool
    {
        return $this->apply($this->methodSeparators, $on);
    }

    public function setShowWhitespace(bool $on): bool
    {
        return $this->apply($this->showWhitespace, $on);
    }

    public function setIndentGuides(bool $on): bool
    {
        return $this->apply($this->indentGuides, $on);
    }

    public function setCodeLenses(bool $on): bool
    {
        return $this->apply($this->codeLenses, $on);
    }

    public function setSoftWrap(bool $on): bool
    {
        return $this->apply($this->softWrap, $on);
    }

    public function setAutoPairs(bool $on): bool
    {
        return $this->apply($this->autoPairs, $on);
    }

    /**
     * Set $slot to $value, repainting only on a real change; answers whether it
     * changed. The slot is a typed property passed by reference, so PHP still
     * refuses a value of the wrong enum.
     *
     * @template T of bool|\BackedEnum
     *
     * @param T $slot
     * @param T $value
     *
     * @param-out T $slot
     */
    private function apply(bool|\BackedEnum &$slot, bool|\BackedEnum $value): bool
    {
        if ($slot === $value) {
            return false;
        }
        $slot = $value;
        ($this->invalidate)();

        return true;
    }
}
