<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;

/**
 * A file's line as text, from the open buffer when there is one and from disk
 * otherwise.
 *
 * Three list surfaces need it, all of which can name a file that is not open:
 * {@see BookmarkRows}, {@see RecentLocationRows} and the LSP location rows /
 * peek preview. A mark, a jump origin and a definition all outlive the buffer,
 * and a row of bare line numbers would be no help at all.
 *
 * `forPath()` binds the reader to one file, so a caller listing several of its
 * lines — the peek preview walks a declaration's worth — pays for at most one
 * read. The line comes back RAW: the location rows trim it, the peek preview
 * only rtrims, because indentation is what makes the preview readable.
 *
 * A missing line and a blank one both answer `''`. Callers reading past a
 * file's end therefore cannot tell the two apart, and the peek preview drops
 * its TRAILING run of blanks rather than trying to.
 */
#[AsIdeService]
final readonly class LineTextReader
{
    public function __construct(
        private BufferManager $buffers,
    ) {}

    /** @return \Closure(int): string raw line text; '' when there is none */
    public function forPath(string $path): \Closure
    {
        // Both spellings, because buffers are keyed by the path they were
        // opened with and a caller may hold the other side of a symlink — the
        // project root itself is one on macOS temp dirs.
        $document = ($this->buffers->get($path) ?? $this->buffers->get(($resolved = realpath($path)) ? $resolved : $path))?->document;
        if (null !== $document) {
            return $document->getLine(...);
        }
        $lines = is_file($path) ? file($path, \FILE_IGNORE_NEW_LINES) : false;
        $fileLines = \is_array($lines) ? $lines : [];

        return static fn (int $line): string => $fileLines[$line] ?? '';
    }
}
