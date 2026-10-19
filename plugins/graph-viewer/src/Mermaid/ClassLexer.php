<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Class bodies own their punctuation; namespace braces remain separate statements. */
final readonly class ClassLexer
{
    public const string NAME = '(?:`[^`]+`|[\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)*)(?:~[^~\r\n]+~)?';
    public const string ENDPOINT = '(?:<\||\|>|\(\)|[<>*o])?';
    public const string RELATION = self::ENDPOINT.'(?:--|\.\.)'.self::ENDPOINT;

    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        $name = \str_replace('~', replace: '\\~', subject: self::NAME);
        yield from new MermaidPatternLexer(
            $this->source,
            [
                '~\GclassDiagram(?:-v2)?(?=\s|;|$)~',
                '~\Gacc(?:Title\s*:[^\r\n]*|Descr\s*:[^\r\n]*|Descr\s*\{[^}]*\})~',
                '~\Gdirection\s+(?:TB|BT|LR|RL)\b~',
                '~\G(?:style|classDef|cssClass|click|link|callback)\s+[^\r\n;]+~',
                '~\Gnote(?:\s+for\s+'.$name.')?\s+"[^"]*"~u',
                '~\Gnamespace\s+'.$name.'(?:\s*\[\s*"[^"]*"\s*\])?\s*\{~u',
                '~\Gclass\s+'.$name.'(?:\s*\[\s*"[^"]*"\s*\])?(?:\s*:::\s*[\w-]+)?(?:\s*<<[\w-]+>>)?(?:\s*\{[^{}]*\})?~u',
                '~\G(?:<<|&lt;&lt;)[\w-]+(?:>>|&gt;&gt;)\s*'.$name.'~u',
                '~\G'.$name.'(?:\s*"[^"]*")?\s*'.self::RELATION.'\s*(?:"[^"]*"\s*)?'.$name.'(?:\s*:[^\r\n;]*)?~u',
                '~\G'.$name.'\s*:[^\r\n;]+~u',
                '~\G'.$name.'~u',
                '~\G[};]~',
            ],
            error: 'Invalid class diagram declaration or unclosed body',
        )->statements();
    }
}
