<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Bodies are streamed field by field; quotes own punctuation, braces and multiline text. */
final readonly class RequirementLexer
{
    public const string NAME = '"[^"\x00]*+"|\w[^:,\r\n{}<>\-=]*';

    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        $name = '(?:'.self::NAME.')';
        $type = '(?:'.\implode('|', RequirementDiagram::TYPES).')';
        $relation = '(?:contains|copies|derives|satisfies|verifies|refines|traces)';
        $patterns = [
            '~\G#[^\r\n]*~',
            '~\GrequirementDiagram(?=\s|$)~i',
            '~\G(?:title\b[^\r\n]*|accTitle[\t ]*:[^\r\n]*|accDescr[\t ]*:[^\r\n]*|accDescr\s*\{[^}]*\})~i',
            '~\Gdirection[\t ]+(?:TB|BT|LR|RL)\b~i',
            '~\G'.$type.'[\t ]+'.$name.'(?:[\t ]*:::[\t ]*'.MermaidNodeStyles::IDS.')?[\t ]*\{~iu',
            '~\G(?:id|text|risk|verifyMethod|type|docRef)[\t ]*:[\t ]*(?:"[^"]*+"|[^"\s}][^\r\n}]*+)~i',
            '~\G}~',
            '~\G(?:style|classDef|class)[\t ]+[^\r\n]+~i',
            '~\G'.$name.'[\t ]*(?:-[\t ]*'.$relation.'[\t ]*->|<-[\t ]*'.$relation.'[\t ]*-)[\t ]*'.$name.'~iu',
            '~\G'.$name.'[\t ]*:::[\t ]*'.MermaidNodeStyles::IDS.'~u',
        ];
        yield from new MermaidPatternLexer($this->source, $patterns, error: 'Invalid requirement declaration or unclosed quoted value')->statements();
    }
}
