<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** C4 call strings own commas, braces, parentheses and physical newlines. */
final readonly class C4Lexer
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
                '~\GC4(?:Context|Container|Component|Dynamic|Deployment)(?=\s|$)~',
                '~\G(?:title|accDescription)\s[^#\r\n;]+~',
                '~\Gacc(?:Title\s*:[^\r\n]*|Descr\s*:[^\r\n]*|Descr\s*\{[^}]*\})~',
                '~\G[A-Za-z_]++\s*+\((?:"[^"]*+"|[^)"]++)*+\)~',
                '~\G[{}]~',
            ],
            error: 'Invalid C4 declaration or unclosed call',
        )->statements();
    }
}
