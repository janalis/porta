<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\Document;
use Porta\Editor\EditorDecorationSink;

/**
 * The code editor as a {@see FindTarget}: matches are revealed by moving the
 * document caret (the editor scrolls it into view) and mirrored to the editor's
 * inline highlight. Pinned to the document captured when the find bar opened.
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
}
