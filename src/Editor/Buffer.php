<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Highlight\CodeHighlighter;

/**
 * One open file: its document (cursor, undo history), diagnostics and its
 * own syntax-highlight cache, so switching buffers never re-highlights.
 * A viewer buffer carries the id of the PANE that shows it and that pane's own
 * payload, beside a permanently-empty document (never dirty, cursor pinned at
 * 0:0) so text-path consumers stay safe. Core never looks inside the payload —
 * it is decoded pixels, DOT source or markdown, and only its pane knows which.
 */
final class Buffer
{
    /**
     * Every source's findings merged, 0-based line => diagnostics — what the
     * stripe, the gutter, the inline messages and the Problems panel read.
     *
     * Derived: {@see BufferService::applyDiagnostics()} rebuilds it from
     * {@see $diagnosticsByOwner} on every publish. Nothing else may write it,
     * or one source's silence would erase another's findings.
     *
     * @var array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>, data?: mixed}>>
     */
    public array $diagnostics = [];

    /**
     * The same findings kept apart by who published them, so that a language
     * server and an analyser plugin can both have something to say about this
     * file without either erasing the other.
     *
     * @var array<string, array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>, data?: mixed}>>> owner => line => diagnostics
     */
    public array $diagnosticsByOwner = [];

    /** The file's resolved `.editorconfig`, applied on open and reused on save. */
    public ?ResolvedEditorConfig $editorConfig = null;

    /** @var array<int, ChangeMarkKind> 0-based line => kind vs git HEAD */
    public array $vcsMarks = [];

    /** @var array<int, CoverageMark> 0-based line => whether the last coverage run executed it; empty while none is shown */
    public array $coverage = [];

    /**
     * The lines a conversation hangs on, by who published them — a review
     * plugin's threads — so they survive a tab switch like {@see $vcsMarks}.
     *
     * @var array<string, array<int, string>> owner => 0-based line => one-line summary
     */
    private array $commentMarksByOwner = [];

    /** Monotonic focus stamp maintained by the BufferManager. */
    public int $lastFocused = 0;

    /** On-disk fingerprint ("<mtime>:<size>") last seen by the IDE, for change detection. */
    public ?string $diskStamp = null;

    /** Untranslated tab-title message id, overriding the basename (the architecture tab). */
    public ?string $title = null;

    /**
     * Pinned tabs survive the bulk closes — Close Others, Close All, Close to
     * the Left/Right — and sort to the front of the strip.
     *
     * The point of pinning is that the tabs you keep are the ones a careless
     * "close the rest" would take first, so the flag lives on the buffer and is
     * persisted with the session.
     */
    public bool $pinned = false;

    /**
     * The pane that previews this TEXT buffer beside the editor (the markdown,
     * image or graph pane), or null when nothing does. Unlike {@see $paneId}
     * this does not make the buffer a viewer: the document is the real file,
     * and the pane is fed its text as it changes.
     */
    public ?string $previewId = null;

    /** How a previewed buffer is arranged; a new tab opens beside its preview. */
    public PreviewMode $previewMode = PreviewMode::Split;

    public readonly CodeHighlighter $highlighter;

    public function __construct(
        public readonly string $path,
        public readonly Document $document,
        ?CodeHighlighter $highlighter = null,
        /** The registered pane that shows this buffer; null means the text editor. */
        public ?string $paneId = null,
        /** Whatever that pane's loader produced for this path — opaque to core. */
        public mixed $panePayload = null,
    ) {
        $this->highlighter = $highlighter ?? new CodeHighlighter();
        $this->highlighter->setFile($path);
    }

    /**
     * Any non-text tab — the single spelling of the "not code" guard, and the
     * one kind question core still asks. Nine callers gate on it: the save path
     * (never write the empty backing document over a real file), git marks, LSP
     * didOpen, debug decorations and the modal-hosting fallbacks.
     */
    public function isViewer(): bool
    {
        return null !== $this->paneId;
    }

    /**
     * The arrangement this buffer shows in, or null when it has no preview —
     * the question the toggles, the commands and the slot all ask. A viewer
     * tab never has one: its pane IS the tab.
     */
    public function previewArrangement(): ?PreviewMode
    {
        return null === $this->previewId || $this->isViewer() ? null : $this->previewMode;
    }

    /** Whether the document has unsaved changes. */
    public function isDirty(): bool
    {
        return $this->document->isDirty();
    }

    /**
     * The file changed on disk while this buffer held unsaved edits.
     *
     * The reconciler records the new stamp when it notices, so by save time the
     * stamp matches disk again and the save has no way to tell. This is what
     * carries "someone else wrote here" forward to the save that would
     * otherwise overwrite them without a word.
     */
    public bool $diskChangedUnderEdit = false;

    /**
     * The file's on-disk fingerprint right now, or null when it is not a file.
     *
     * The cache is cleared first, for this path only. PHP caches stat results
     * for the life of the process and invalidates them only for writes IT
     * performs — so without this the stamp answers with whatever it saw the
     * first time and an edit made by ANOTHER process is never noticed at all.
     * That is the case the stamp exists for: another editor, a formatter, a
     * branch switch.
     *
     * It hid behind the tests for as long as it existed, because they all write
     * through `TempProject::write()`, which is in-process and clears the cache
     * as a side effect. Measured with a separate writer: three seconds of
     * polling, no detection.
     *
     * The three calls below stay three calls: with the entry warm, PHP answers
     * the second and third from cache, and replacing them with one `stat()`
     * measured 3x SLOWER (its array construction costs more than the cache
     * hits it saves).
     */
    public function diskStampNow(): ?string
    {
        \clearstatcache(clear_realpath_cache: true, filename: $this->path);

        return \is_file($this->path) ? (int) \filemtime($this->path).':'.(int) \filesize($this->path) : null;
    }

    /** Record the current on-disk fingerprint (after open and after save). */
    public function stampFromDisk(): void
    {
        $this->diskStamp = $this->diskStampNow();
    }

    /**
     * Replace one owner's comment marks; an empty map drops that owner.
     *
     * @param array<int, string> $marks 0-based line => one-line summary
     */
    public function publishCommentMarks(string $owner, array $marks): void
    {
        $this->commentMarksByOwner[$owner] = $marks;
        if ([] === $marks) {
            unset($this->commentMarksByOwner[$owner]);
        }
    }

    /**
     * Every owner's marks merged: a line two owners mark reads both summaries.
     *
     * @return array<int, string>
     */
    public function commentMarks(): array
    {
        $merged = [];
        foreach ($this->commentMarksByOwner as $marks) {
            foreach ($marks as $line => $summary) {
                $merged[$line] = \array_key_exists($line, $merged) ? $merged[$line].' · '.$summary : $summary;
            }
        }

        return $merged;
    }
}
