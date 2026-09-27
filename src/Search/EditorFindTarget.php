<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\Caret;
use Porta\Editor\Document;
use Porta\Editor\EditorDecorationSink;
use Porta\Editor\MultiCaret;

/**
 * The code editor as a {@see FindTarget}: matches are revealed by moving the
 * document caret (the editor scrolls it into view) and mirrored to the editor's
 * inline highlight. Pinned to the document captured when the find bar opened,
 * and to that document's carets for Select All Occurrences.
 */
final class EditorFindTarget implements FindTarget
{
    #[\Override]
    public array $findLines {
        get => \array_values($this->document->lines);
    }

    #[\Override]
    public int $findVersion {
        get => $this->document->version;
    }

    #[\Override]
    public int $findCaretLine {
        get => $this->document->cursorLine;
    }

    #[\Override]
    public int $findCaretCol {
        get => $this->document->cursorCol;
    }

    #[\Override]
    public ?Document $replaceDocument {
        get => $this->document;
    }

    public function __construct(
        private readonly Document $document,
        private readonly EditorDecorationSink $editor,
        private readonly MultiCaret $carets,
    ) {}

    #[\Override]
    public function revealMatch(int $line, int $startByte, int $endByte): void
    {
        $this->document->setCursor($line, $startByte);
    }

    #[\Override]
    public function showSearchMatches(array $byLine): void
    {
        $this->editor->setSearchMatches($byLine);
    }

    #[\Override]
    public function selectMatches(array $matches, int $current): bool
    {
        // Anchored at the start, the caret at the end: a selection made the
        // way a forward drag makes one.
        $carets = \array_map(static fn (array $m): Caret => new Caret($m['line'], $m['end'], $m['line'], $m['start']), $matches);
        $this->carets->selectRanges($carets, $current);

        return true;
    }
}
