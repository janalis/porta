<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Quoted items and transition labels may contain line breaks and literal comment markers. */
final readonly class CynefinLexer
{
    public const string STRING = '"(?:[^"\\\\]++|\\\\.)*"|\'(?:[^\'\\\\]++|\\\\.)*\'';
    private const string DOMAIN = '(?:complex|complicated|clear|chaotic|confusion)';

    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        $patterns = [
            '~\Gcynefin-beta:?(?=\s|$)~i',
            '~\G(?:title\b[^\r\n]*|accTitle[\t ]*:[^\r\n]*|accDescr[\t ]*:[^\r\n]*|accDescr\s*\{[^}]*\})~i',
            '~\G'.self::DOMAIN.'[\t ]*-->[\t ]*'.self::DOMAIN.'(?:[\t ]*:[\t ]*(?:'.self::STRING.'))?(?=[\t ]*(?:%%[^\r\n]*)?(?:\r?\n|$))~',
            '~\G'.self::DOMAIN.'(?![-\w])~u',
            '~\G(?:'.self::STRING.')~s',
        ];
        yield from new MermaidPatternLexer($this->source, $patterns, error: 'Invalid Cynefin declaration or unclosed quoted label')->statements();
    }
}
