<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/** What inline parsing needs from the whole document: the link references and the footnotes defined. */
final readonly class InlineContext
{
    /**
     * @param array<string, LinkReference> $references keyed by normalised label
     * @param array<string, true>          $footnotes  the normalised labels of the footnotes defined
     */
    public function __construct(
        public array $references = [],
        public array $footnotes = [],
    ) {}
}
