<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\Document;
use Porta\Search\FindTarget;

/**
 * A {@see FindTarget} over a plain {@see Document} for unit-testing the find
 * bar without an editor widget: reveals move the document caret (so the caret
 * assertions the bar tests make still hold) and mirrored matches are captured.
 * Constructed read-only to model a docs-style target whose replace path bails.
 */
final class FakeFindTarget implements FindTarget
{
    /** @var array<int, list<array{start: int, end: int, current: bool}>> the last mirror */
    public array $lastMatches = [];

    public function __construct(
        private readonly Document $document,
        private readonly bool $readOnly = false,
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
        $this->lastMatches = $byLine;
    }

    public function replaceDocument(): ?Document
    {
        return $this->readOnly ? null : $this->document;
    }
}
