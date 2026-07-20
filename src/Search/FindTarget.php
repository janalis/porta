<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\Document;

/**
 * What the in-file find bar ({@see \Porta\Widget\FindBarWidget}) operates on,
 * so the one bar can search either the code editor's document or the read-only
 * docs viewer's rendered text. The editor target reveals a match by moving the
 * document caret; the help viewer scrolls the match into view — the bar itself
 * stays agnostic. Replace is editor-only: {@see replaceDocument()} returns null
 * for read-only targets and the bar's replace path bails.
 */
interface FindTarget
{
    /** @return list<string> the plain-text lines to search */
    public function findLines(): array;

    /** A version that changes whenever {@see findLines()} would — for staleness. */
    public function findVersion(): int;

    /** The line the "nearest match" jump anchors to (caret line / scroll top). */
    public function findCaretLine(): int;

    /** The byte column the "nearest match" jump anchors to on {@see findCaretLine()}. */
    public function findCaretCol(): int;

    /** Bring the match at these byte offsets into view (move caret / scroll). */
    public function revealMatch(int $line, int $startByte, int $endByte): void;

    /**
     * Mirror the current matches for highlighting; an empty map clears them.
     *
     * @param array<int, list<array{start: int, end: int, current: bool}>> $byLine
     */
    public function showSearchMatches(array $byLine): void;

    /** The editable document for the replace path, or null when read-only. */
    public function replaceDocument(): ?Document;
}
