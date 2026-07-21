<?php

declare(strict_types=1);

namespace Porta\Highlight;

/**
 * One "colour this document" request handed to a {@see SyntaxHighlightProvider}.
 *
 * `$documentId` and `$version` exist purely so a late answer can be matched to
 * the question: an asynchronous backend echoes both back through
 * {@see HighlightSink}, and anything that no longer matches the request core is
 * waiting on is dropped. A backend never has to reason about whether its result
 * is still wanted.
 */
final readonly class HighlightRequest
{
    public function __construct(
        public string $documentId,
        public string $language,
        public string $text,
        public int $version,
    ) {}
}
