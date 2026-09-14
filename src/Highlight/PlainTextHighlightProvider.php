<?php

declare(strict_types=1);

namespace Porta\Highlight;

/**
 * The always-present fallback: returns the source unchanged.
 *
 * This is what keeps the editor usable with no highlighting backend at all —
 * every plugin disabled, every provider tripped by the fault barrier, or a
 * language nothing claims. It is constructed directly rather than resolved, so
 * it cannot be removed, disabled or shadowed.
 *
 * It satisfies the {@see SyntaxHighlightProvider} line-structure contract
 * trivially, since the output *is* the input.
 */
final class PlainTextHighlightProvider implements SyntaxHighlightProvider
{
    #[\Override]
    public string $id {
        get => 'plain';
    }

    #[\Override]
    public function supports(string $language): bool
    {
        return true;
    }

    #[\Override]
    public function highlight(HighlightRequest $request, HighlightSink $sink): void
    {
        $sink->deliver($request->documentId, $request->version, $request->text);
    }
}
