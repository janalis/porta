<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DecorationStore;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\DiagnosticStyle;

/**
 * What the right-margin stripe paints for one frame, flattened out of the
 * per-buffer {@see DecorationStore} into the line-keyed maps the stripe
 * actually needs — worst severity rather than whole diagnostic records, and a
 * bare line list rather than each match's columns. Passed as one value so
 * {@see ErrorStripe::cells()} does not take four more positional arguments,
 * which is the same reason {@see EditorDecorations} exists for the row
 * assembler.
 */
final readonly class StripeMarks
{
    /**
     * @param array<int, DiagnosticSeverity> $severityByLine 0-based line => worst severity present
     * @param array<int, ChangeMarkKind> $vcsByLine       0-based line => version-control change kind
     * @param list<int>                  $searchLines     0-based lines carrying at least one search match
     * @param list<int>                  $breakpointLines 0-based lines carrying a breakpoint
     */
    public function __construct(
        public array $severityByLine,
        public array $vcsByLine,
        public array $searchLines,
        public array $breakpointLines,
    ) {}

    /**
     * Read the store once per frame.
     *
     * VCS marks follow the change strip's own toggle: turning the gutter's
     * version-control column off takes them out of the stripe too, so the two
     * can never disagree about whether this buffer shows version control.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- $vcsGutter mirrors the gutter's own display toggle onto the stripe; it selects nothing else, and splitting it yields two constructors differing by one array.
    public static function from(DecorationStore $store, bool $vcsGutter): self
    {
        $severity = [];
        foreach ($store->diagnostics() as $line => $diagnostics) {
            if ([] === $diagnostics) {
                continue;
            }
            $severity[$line] = DiagnosticStyle::worst($diagnostics);
        }

        $search = [];
        foreach ($store->searchMatches() as $line => $matches) {
            if ([] === $matches) {
                continue;
            }
            $search[] = $line;
        }

        return new self(
            $severity,
            $vcsGutter ? $store->vcsMarks() : [],
            $search,
            $store->breakpointLines(),
        );
    }
}
