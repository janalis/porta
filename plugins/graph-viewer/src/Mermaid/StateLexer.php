<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** State descriptions own colons and note bodies own their internal punctuation. */
final readonly class StateLexer
{
    public const string NAME = '[^\s:{};"\-]+';
    public const string ENDPOINT = '(?:\[\*\]|'.self::NAME.')(?:[\t ]*:::[\t ]*'.self::NAME.')?';

    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        yield from new MermaidPatternLexer(
            $this->source,
            [
                '~\GstateDiagram(?:-v2)?(?=\s|;|$)~i',
                '~\G\#[^\r\n]*~',
                '~\Gacc(?:Title\s*:[^\r\n]*|Descr\s*:[^\r\n]*|Descr\s*\{[^}]*\})~i',
                '~\Gdirection\s+(?:TB|BT|LR|RL)\b~i',
                '~\G(?:classDef|class|style|click)\s+[^\r\n;]+~i',
                '~\Gnote\s+(?:left|right)\s+of\s+'.self::NAME.'(?:[\t ]*:[^\r\n]+|[\t ]*\r?\n[\s\S]*?\r?\n[\t ]*end[\t ]+note\b)~i',
                '~\Gnote\s+"[^"]*"\s+as\s+'.self::NAME.'~i',
                '~\Gstate\s+(?:"[^"]*"\s+as\s+'.self::NAME.'|'.self::NAME.')(?:[\t ]*(?:<<|\[\[)(?:fork|join|choice)(?:>>|\]\]))?(?:[\t ]*:[^\r\n{]+)?(?:\s*\{)?~i',
                '~\G(?:hide\s+empty\s+description|scale\s+\d+\s+width)\b~i',
                '~\G'.self::ENDPOINT.'[\t ]*-->[\t ]*'.self::ENDPOINT.'(?:[\t ]*:[^\r\n]+)?~',
                '~\G--(?=\s|\}|$)~',
                '~\G(?!(?:state|note|direction|classDef|class|style|click|scale|hide)\s)'.self::ENDPOINT.'(?:[\t ]*:[^\r\n]+)?~i',
                '~\G[};]~',
            ],
            error: 'Invalid state diagram declaration or unclosed note',
        )->statements();
    }
}
