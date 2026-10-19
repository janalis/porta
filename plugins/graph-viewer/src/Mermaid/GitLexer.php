<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Commands end at a newline outside strings; semicolons belong only inside strings. */
final readonly class GitLexer
{
    public const string STRING = MermaidQuotedString::PATTERN;
    public const string REFERENCE = '\w(?:[-./\w]*[-\w])?';

    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        yield from new MermaidPatternLexer(
            $this->source,
            [
                '~\GgitGraph(?:[\t ]+(?:LR|TB|BT)[\t ]*:|[\t ]*:)?(?=\s|$)~',
                '~\G(?:title\b[^\r\n]*|accTitle[\t ]*:[^\r\n]*|accDescr[\t ]*(?::[^\r\n]*|\{[^}]*\}))~',
                '~\G(?:commit|branch|merge|checkout|switch|cherry-pick)\b(?:'.self::STRING.'|(?!(?:%%))[^\'"\r\n])*~s',
            ],
            error: 'Invalid Git graph declaration',
        )->statements();
    }
}
