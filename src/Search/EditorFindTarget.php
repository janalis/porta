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
    public function __construct(
        private readonly Document $document,
        private readonly EditorDecorationSink $editor,
    ) {}

    public function findLines(): array
    {
        return array_values($this->document->getLines());
    }

    public function findVersion(): int
    {
        return $this->document->getVersion();
    }

    public function findCaretLine(): int
    {
        return $this->document->getCursorLine();
    }

    public function findCaretCol(): int
    {
        return $this->document->getCursorCol();
    }

    public function revealMatch(int $line, int $startByte, int $endByte): void
    {
        $this->document->setCursor($line, $startByte);
    }

    public function showSearchMatches(array $byLine): void
    {
        $this->editor->setSearchMatches($byLine);
    }

    public function replaceDocument(): ?Document
    {
        return $this->document;
    }
}
