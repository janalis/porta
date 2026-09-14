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
final readonly class EditorFindTarget implements FindTarget
{
    public function __construct(
        private Document $document,
        private EditorDecorationSink $editor,
    ) {}

    #[\Override]
    public function findLines(): array
    {
        return \array_values($this->document->getLines());
    }

    #[\Override]
    public function findVersion(): int
    {
        return $this->document->getVersion();
    }

    #[\Override]
    public function findCaretLine(): int
    {
        return $this->document->getCursorLine();
    }

    #[\Override]
    public function findCaretCol(): int
    {
        return $this->document->getCursorCol();
    }

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
    public function replaceDocument(): ?Document
    {
        return $this->document;
    }
}
