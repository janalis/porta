<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\Document;

/**
 * What the in-file find bar ({@see \Porta\Widget\FindBarWidget}) operates on,
 * so the one bar can search either the code editor's document or the read-only
 * docs viewer's rendered text. The editor target reveals a match by moving the
 * document caret; the help viewer scrolls the match into view — the bar itself
 * stays agnostic. Replace is editor-only: {@see $replaceDocument} is null for
 * read-only targets and the bar's replace path bails.
 *
 * What the bar READS is declared as properties — most implementations answer
 * them with get hooks over their own state — and what it asks the target to DO
 * stays methods.
 */
interface FindTarget
{
    /** @var list<string> the plain-text lines to search */
    public array $findLines { get; }

    /** A version that changes whenever {@see $findLines} would — for staleness. */
    public int $findVersion { get; }

    /** The line the "nearest match" jump anchors to (caret line / scroll top). */
    public int $findCaretLine { get; }

    /** The byte column the "nearest match" jump anchors to on {@see $findCaretLine}. */
    public int $findCaretCol { get; }

    /** Bring the match at these byte offsets into view (move caret / scroll). */
    public function revealMatch(int $line, int $startByte, int $endByte): void;

    /**
     * Mirror the current matches for highlighting; an empty map clears them.
     *
     * @param array<int, list<array{start: int, end: int, current: bool}>> $byLine
     */
    public function showSearchMatches(array $byLine): void;

    /** The editable document for the replace path, or null when read-only. */
    public ?Document $replaceDocument { get; }

    /**
     * Select every match at once, a caret on each (the bar's Select All
     * Occurrences); $current is the one the bar is on. Returns whether the
     * target took them — a read-only one has no carets to put there.
     *
     * @param non-empty-list<array{line: int, start: int, end: int}> $matches byte offsets
     */
    public function selectMatches(array $matches, int $current): bool;
}
