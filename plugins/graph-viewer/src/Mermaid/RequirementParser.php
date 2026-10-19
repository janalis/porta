<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Requirement bodies are explicit; relationships and styles may refer forward. */
final class RequirementParser
{
    private readonly RequirementDiagram $diagram;
    private bool $header = false;
    private ?MermaidStatement $body = null;
    private string $name = '';
    private string $type = '';
    /** @var array<string, string> */
    private array $fields = [];
    private int $declarations = 0;

    public function __construct()
    {
        $this->diagram = new RequirementDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (\str_starts_with($statement->text, '#')) {
            return;
        }
        if (null !== $this->body) {
            $this->field($statement, $this->body);

            return;
        }
        $this->outside($statement);
    }

    private function outside(MermaidStatement $statement): void
    {
        $match = [];
        if (1 === \preg_match('/^(title(?:[\t ]|$)|accTitle\s*:|accDescr\s*[:{])(.*)$/is', $statement->text, $match)) {
            $name = \strtolower(\rtrim($match[1], characters: " \t:{"));
            $text = \str_ends_with($match[1], '{') ? \substr($match[2], offset: 0, length: -1) : $match[2];
            $this->diagram->metadata[$name] = ['label' => MermaidText::decode(\trim($text)), 'origin' => $statement->line];

            return;
        }
        if (!$this->header) {
            if ('requirementdiagram' !== \strtolower($statement->text)) {
                throw $statement->error('Expected requirementDiagram');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        $this->declaration($statement);
    }

    private function declaration(MermaidStatement $statement): void
    {
        $match = [];
        if (1 === \preg_match('/^direction\s+(TB|BT|LR|RL)$/i', $statement->text, $match)) {
            $this->diagram->direction = \strtoupper($match[1]);

            return;
        }
        $type = \implode('|', RequirementDiagram::TYPES);
        if (1 === \preg_match('~^('.$type.')\s+('.RequirementLexer::NAME.')\s*(?::::\s*('.MermaidNodeStyles::IDS.')\s*)?\{$~isu', $statement->text, $match)) {
            if (++$this->declarations > MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the requirement declaration limit');
            }
            $this->name = self::value($match[2]);
            $this->type = \strtolower($match[1]);
            $this->body = $statement;
            $this->fields = [];
            if ('' !== ($match[3] ?? '')) {
                $this->diagram->styles->assign($this->name, $match[3], $statement);
            }

            return;
        }
        $this->relationshipOrStyle($statement);
    }

    private function relationshipOrStyle(MermaidStatement $statement): void
    {
        if (\str_contains($statement->text, ':::') || \str_starts_with(\strtolower($statement->text), 'class') || \str_starts_with(\strtolower($statement->text), 'style')) {
            $this->diagram->styles->accept($statement);

            return;
        }
        $this->relation($statement);
    }

    private function field(MermaidStatement $statement, MermaidStatement $body): void
    {
        if ('}' === $statement->text) {
            $this->diagram->nodes[$this->name] ??= ['type' => $this->type, 'fields' => $this->fields, 'origin' => $body->line, 'offset' => $body->offset];
            $this->body = null;

            return;
        }
        $match = [];
        if (1 !== \preg_match('/^(id|text|risk|verifyMethod|type|docRef)\s*:\s*(.+)$/is', $statement->text, $match)) {
            throw $statement->error('Expected a requirement field or closing brace');
        }
        $key = \strtolower($match[1]);
        $allowed = 'element' === $this->type ? ['type', 'docref'] : ['id', 'text', 'risk', 'verifymethod'];
        if (!\in_array($key, $allowed, strict: true)) {
            throw $statement->error('Field is not valid for this requirement declaration');
        }
        $value = self::value($match[2]);
        $choices = match ($key) {
            'risk' => ['low', 'medium', 'high'],
            'verifymethod' => ['analysis', 'demonstration', 'inspection', 'test'],
            default => [],
        };
        if ([] !== $choices && !\in_array(\strtolower($value), $choices, strict: true)) {
            throw $statement->error('Invalid requirement '.$key);
        }
        $this->fields[$key] ??= $value;
    }

    private function relation(MermaidStatement $statement): void
    {
        $name = '('.RequirementLexer::NAME.')';
        $kind = '(contains|copies|derives|satisfies|verifies|refines|traces)';
        $match = [];
        if (1 !== \preg_match('~^'.$name.'\s*(?:-\s*'.$kind.'\s*->|<-\s*'.$kind.'\s*-)\s*'.$name.'$~isu', $statement->text, $match)) {
            throw $statement->error('Expected a requirement declaration or relationship');
        }
        if (\count($this->diagram->relations) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the requirement relationship limit');
        }
        $from = self::value($match[1]);
        $to = self::value($match[4]);
        $reverse = '' === $match[2];
        $this->diagram->relations[] = ['from' => $reverse ? $to : $from, 'to' => $reverse ? $from : $to, 'kind' => \strtolower($reverse ? $match[3] : $match[2]), 'origin' => $statement->line];
    }

    private static function value(string $text): string
    {
        $text = \trim($text);

        return MermaidText::decode(\str_starts_with($text, '"') ? \substr($text, offset: 1, length: -1) : $text);
    }

    public function finish(): RequirementDiagram
    {
        if (null !== $this->body) {
            throw $this->body->error('Unclosed requirement declaration');
        }
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected requirementDiagram', offset: 0, sourceLine: 0, column: 0);
        }
        foreach ($this->diagram->relations as $relation) {
            if (!\array_key_exists($relation['from'], $this->diagram->nodes) || !\array_key_exists($relation['to'], $this->diagram->nodes)) {
                throw new MermaidSyntaxError('Unknown requirement relationship endpoint', offset: 0, sourceLine: $relation['origin'], column: 0);
            }
        }

        return $this->diagram;
    }
}
