<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Token boundaries retain authored grid order independently of physical lines. */
final readonly class BlockLexer
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
                '~\G(?:classDef|class|style)\s+[^\r\n]+~',
                '~\Gcolumns\s+(?:auto|\d+)\b~',
                '~\Gblock-beta\b|\Gblock:|\Gblock\b|\Gend\b~',
                '~\Gspace(?::\d+)?\b~',
                '~\G(?:[xo<]?(?:--+[-xo>]|==+[=xo>]|-?\.+-[xo>]?|\~\~\~+))~',
                '~\G(?:--|==|-\.)\s*"[^"]*"\s*(?:--+[-xo>]|==+[=xo>]|\.+-[xo>]?)~',
                '~\G:\d+~',
                '~\G[^\s()\[\]{}<>:=\-]+(?:-(?![-.])[^\s()\[\]{}<>:=\-]+)*~u',
                '~\G<\["(?:`[^`]*`|[^"]*)"\]>\s*\(\s*(?:right|left|up|down|x|y)(?:\s*,?\s*(?:right|left|up|down|x|y))*\s*\)~',
                '~\G(?:\(\(\(|\[\[|\[\(|\(\[|\(\(|\{\{|\[|\(|\{|>)(?:"[^"]*"|[^"\r\n])*?(?:\)\)\)|\]\]|\)\]|\]\)|\)\)|\}\}|\]|\)|\})~',
            ],
            error: 'Invalid block declaration or unclosed shape',
        )->statements();
    }
}
