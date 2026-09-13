<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * What the large-file pane is handed: a BOUNDED prefix of a file too big to
 * open as editable text, and the size of the whole thing.
 *
 * The prefix is the point. A file is refused for editing at
 * {@see \Porta\Support\Files::MAX_TEXT_BYTES} because holding it as a document
 * — lines, undo history, highlighting — is what does not scale; reading all of
 * it just to look at the top would reintroduce the same problem one layer down,
 * and a genuinely large file (a database dump, a bundled asset, a log) would
 * take the session with it. So the loader reads one cap's worth and the pane
 * says plainly that is what you are looking at.
 *
 * `$bytes` is the file's REAL size rather than the prefix's, because it is the
 * number the user needs: it is what says the file was cut, and by how much.
 */
final readonly class TextPreview
{
    public function __construct(
        /** The prefix actually read, always valid UTF-8 and never longer than the cap. */
        public string $text,
        /** The whole file's size on disk, in bytes. */
        public int $bytes,
        /** Whether {@see $text} is a prefix rather than the whole file. */
        public bool $truncated,
    ) {}
}
