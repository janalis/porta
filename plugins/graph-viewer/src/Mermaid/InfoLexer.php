<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** The pinned info grammar has two keywords and the common title/accessibility declarations. */
final readonly class InfoLexer
{
    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        yield from new MermaidPatternLexer(
            $this->source,
            [
                '~\Ginfo\b~',
                '~\GshowInfo\b~',
                '~\G(?:title(?:[\t ][^\r\n%]*+(?:%(?!%)[^\r\n%]*+)*+)?|accTitle[\t ]*:[^\r\n%]*+(?:%(?!%)[^\r\n%]*+)*+|accDescr[\t ]*:[^\r\n%]*+(?:%(?!%)[^\r\n%]*+)*+|accDescr\s*\{[^}]*+\})(?=\r?\n|%%|$)~',
            ],
            error: 'Invalid info declaration',
        )->statements();
    }
}
