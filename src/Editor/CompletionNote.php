<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * What an asked-for completion popup says when it has no list to show: an
 * answer still on its way, an answer of nothing, or nobody to ask. Only
 * Ctrl+Space earns one — the automatic trigger after `->` stays silent,
 * since a note on ordinary typing would flicker in every buffer.
 */
enum CompletionNote
{
    case Loading;
    case Empty;
    case NoServer;

    /** The English source string; the widget translates it. */
    public function text(): string
    {
        return match ($this) {
            self::Loading => 'Loading…',
            self::Empty => 'No suggestions',
            self::NoServer => 'No language server',
        };
    }
}
