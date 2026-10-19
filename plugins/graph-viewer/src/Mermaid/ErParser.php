<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/**
 * Relationships create entities at first mention; aliases never replace entity identity.
 * Subgraphs frame the entities declared or related inside them, as flowchart's do.
 */
final class ErParser
{
    private readonly ErDiagram $diagram;
    private readonly ErAttributes $attributes;
    private readonly FlowchartScopes $scopes;
    private bool $header = false;
    private int $declarations = 0;

    public function __construct()
    {
        $this->diagram = new ErDiagram();
        $this->attributes = new ErAttributes();
        $this->scopes = new FlowchartScopes($this->diagram->graph);
    }

    public function accept(MermaidStatement $statement): void
    {
        if (!$this->header) {
            if ('erdiagram' !== \strtolower($statement->text)) {
                throw $statement->error('Expected erDiagram');
            }
            $this->header = true;

            return;
        }
        $match = [];
        if ([] === $this->scopes->groups() && 1 === \preg_match('/^direction\s+(TB|BT|LR|RL)$/i', $statement->text, $match)) {
            $this->diagram->graph->direction = \strtoupper($match[1]);

            return;
        }
        $this->content($statement);
    }

    private function content(MermaidStatement $statement): void
    {
        $match = [];
        if (1 === \preg_match('/^(title(?:[\t ]|$)|accTitle\s*:|accDescr\s*[:{])(.*)$/is', $statement->text, $match)) {
            $text = \str_ends_with($match[1], '{') ? \substr($match[2], offset: 0, length: -1) : $match[2];
            $this->diagram->metadata[] = ['text' => MermaidText::decode(\trim($text)), 'origin' => $statement->line];

            return;
        }
        if (1 === \preg_match('/^(?:classDef|class|style)\b/i', $statement->text)) {
            $this->diagram->styles->accept($statement);

            return;
        }
        match (true) {
            1 === \preg_match('/^subgraph\b/', $statement->text) => $this->scopes->open($statement),
            'end' === $statement->text => $this->scopes->close($statement),
            1 === \preg_match('/^direction\s/', $statement->text) => $this->scopes->direction($statement),
            default => $this->entityOrRelation($statement),
        };
    }

    private function entityOrRelation(MermaidStatement $statement): void
    {
        $from = '(?<from>'.ErLexer::NAME.')(?:\s*:::\s*(?<fromClass>'.MermaidNodeStyles::IDS.'))?';
        $to = '(?<to>'.ErLexer::NAME.')(?:\s*:::\s*(?<toClass>'.MermaidNodeStyles::IDS.'))?';
        $pattern = '~^'.$from.'\s*(?<left>'.ErLexer::CARD.')\s*(?<ident>'.ErLexer::RELATION.')\s*(?<right>'.ErLexer::CARD.')\s*'.$to.'\s*:\s*(?<role>'.ErLexer::NAME.')$~isu';
        $match = [];
        if (1 === \preg_match($pattern, $statement->text, $match)) {
            $fromId = self::text($match['from']);
            $toId = self::text($match['to']);
            $this->named($fromId, $match['fromClass'] ?? '', $statement);
            $this->named($toId, $match['toClass'] ?? '', $statement);
            $left = self::card($match['left']);
            $right = self::card($match['right']);
            $this->diagram->cardinalities[] = ['left' => $left, 'right' => $right];
            $label = $left.' : '.$right.' · '.self::text($match['role']);
            $this->diagram->graph->edge($fromId, $toId, $label, \in_array(\strtolower($match['ident']), ['--', 'to'], strict: true) ? '--' : '-.-', $statement);

            return;
        }
        $this->entity($statement);
    }

    private function entity(MermaidStatement $statement): void
    {
        $name = '(?<id>'.ErLexer::NAME.')';
        $alias = '(?:\s*\[\s*(?<alias>'.ErLexer::NAME.')\s*\])?';
        $classes = '(?:\s*:::\s*(?<classes>'.MermaidNodeStyles::IDS.'))?';
        $match = [];
        if (1 !== \preg_match('~^'.$name.$alias.$classes.'\s*(?:\{(?<body>.*)\})?$~su', $statement->text, $match, flags: \PREG_OFFSET_CAPTURE)) {
            throw $statement->error('Expected an ER entity or relationship');
        }
        if (++$this->declarations > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the ER declaration limit');
        }
        $id = self::text($match['id'][0]);
        $this->named($id, $match['classes'][0] ?? '', $statement);
        if ('' !== ($match['alias'][0] ?? '')) {
            $alias = self::text($match['alias'][0]);
            if ('' !== $alias) {
                $this->diagram->aliases[$id] ??= $alias;
            }
        }
        if (\array_key_exists('body', $match)) {
            $attributes = $this->attributes->parse($match['body'][0], $statement, (int) $match['body'][1]);
            $this->diagram->attributes[$id] = [...($this->diagram->attributes[$id] ?? []), ...$attributes];
        }
    }

    private function named(string $id, string $classes, MermaidStatement $statement): void
    {
        $this->diagram->graph->node($id, null, $statement, $this->scopes->groups());
        if ('' !== $classes) {
            $this->diagram->styles->assign($id, $classes, $statement);
        }
    }

    private static function text(string $text): string
    {
        return MermaidText::decode(\str_starts_with($text, '"') ? \substr($text, offset: 1, length: -1) : $text);
    }

    private static function card(string $card): string
    {
        return match (\strtolower($card)) {
            'one', 'only one', '1', '||' => '1',
            'one or zero', 'zero or one', '|o', 'o|' => '0..1',
            'one or more', 'one or many', '1+', 'many(1)', '}|', '|{' => '1..*',
            'u' => 'MD parent',
            default => '0..*',
        };
    }

    public function finish(): ErDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected erDiagram', offset: 0, sourceLine: 0, column: 0);
        }
        $this->scopes->finish();

        return $this->diagram;
    }
}
