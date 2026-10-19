<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Cardinality braces belong to a relationship; quoted attribute comments own their braces. */
final readonly class ErLexer
{
    public const string NAME = '"[^"\x00]*+"|[\p{L}\p{N}_*.-]+';
    public const string CARD = 'one or zero|zero or one|one or more|one or many|zero or more|zero or many|only one|many\(0\)|many\(1\)|many|one|1\+|0\+|1|\|o|o\||\}o|o\{|\}\||\|\{|\|\||u';
    public const string RELATION = 'optionally to|to|--|\.\.|\.-|-\.';

    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        $name = '(?:'.self::NAME.')';
        $card = '(?:'.self::CARD.')';
        $classes = '(?:\s*:::\s*'.MermaidNodeStyles::IDS.')?';
        $entity = $name.'(?:\s*\[\s*'.$name.'\s*\])?'.$classes;
        $endpoint = $name.$classes;
        yield from new MermaidPatternLexer(
            $this->source,
            [
                '~\GerDiagram\b~i',
                '~\G(?:title\b[^\r\n]*?(?=%%|\r?\n|$)|accTitle[\t ]*:[^\r\n]*?(?=%%|\r?\n|$)|accDescr[\t ]*:[^\r\n]*?(?=%%|\r?\n|$)|accDescr\s*\{[^}]*+\})~i',
                '~\Gdirection\s+(?:TB|BT|LR|RL)\b~i',
                '~\G(?:classDef|class|style|subgraph|end)\b[^\r\n]*~i',
                '~\G'.$endpoint.'\s*'.$card.'\s*(?:'.self::RELATION.')\s*'.$card.'\s*'.$endpoint.'\s*:\s*'.$name.'~iu',
                '~\G'.$entity.'\s*\{(?:[^{}"`]|"[^"]*+"|`[^`]*+`)*+\}~u',
                '~\G'.$entity.'~u',
            ],
            error: 'Invalid entity relationship declaration or unclosed attribute block',
        )->statements();
    }
}
