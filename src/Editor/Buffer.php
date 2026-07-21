<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Highlight\CodeHighlighter;
use Porta\Image\ImageData;

/**
 * One open file: its document (cursor, undo history), diagnostics and its
 * own syntax-highlight cache, so switching buffers never re-highlights.
 * An image buffer carries decoded pixels beside a permanently-empty document
 * (never dirty, cursor pinned at 0:0), so text-path consumers stay safe.
 */
final class Buffer
{
    /** @var array<int, list<array{severity: int, message: string, col: int, endCol?: int}>> */
    public array $diagnostics = [];

    /** The file's resolved `.editorconfig`, applied on open and reused on save. */
    public ?ResolvedEditorConfig $editorConfig = null;

    /** @var array<int, string> 0-based line => 'added'|'modified'|'deleted' vs git HEAD */
    public array $gitMarks = [];

    /** Monotonic focus stamp maintained by the BufferManager. */
    public int $lastFocused = 0;

    /** On-disk fingerprint ("<mtime>:<size>") last seen by the IDE, for change detection. */
    public ?string $diskStamp = null;

    public readonly CodeHighlighter $highlighter;

    public function __construct(
        public readonly string $path,
        public readonly Document $document,
        ?CodeHighlighter $highlighter = null,
        public ?ImageData $image = null,
        public ?string $helpMarkdown = null,
    ) {
        $this->highlighter = $highlighter ?? new CodeHighlighter();
        $this->highlighter->setFile($path);
    }

    /** Whether this tab is an image shown by the viewer rather than the editor. */
    public function isImage(): bool
    {
        return null !== $this->image;
    }

    /** Whether this tab is a rendered documentation page (the F1 help browser). */
    public function isHelp(): bool
    {
        return null !== $this->helpMarkdown;
    }

    /** Whether the document has unsaved changes. */
    public function isDirty(): bool
    {
        return $this->document->isDirty();
    }

    /** The file's on-disk fingerprint right now, or null when it is not a file. */
    public function diskStampNow(): ?string
    {
        return is_file($this->path) ? (int) filemtime($this->path).':'.(int) filesize($this->path) : null;
    }

    /** Record the current on-disk fingerprint (after open and after save). */
    public function stampFromDisk(): void
    {
        $this->diskStamp = $this->diskStampNow();
    }
}
