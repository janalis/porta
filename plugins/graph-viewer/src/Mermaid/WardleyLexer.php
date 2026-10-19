<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Physical lines own punctuation; quoted names may contain comment lookalikes. */
final readonly class WardleyLexer
{
    public const string NUMBER = '\d+\.\d+';
    public const string NAME = '(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[A-Za-z_][A-Za-z0-9_()&-]*(?:[\t ]+[A-Za-z(][A-Za-z0-9_()&-]*)*)';

    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        yield from new MermaidPatternLexer(
            $this->source,
            [
                '~\GaccDescr[\t ]*\{[^}]*\}~',
                '~\G(?:(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|(?!(?:%%))[^\r\n\'"])+~s',
            ],
            error: 'Invalid Wardley declaration',
        )->statements();
    }
}
