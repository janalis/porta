<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Located class declarations, namespaces, ordered members and UML relationships. */
final class ClassParser
{
    private readonly ClassDiagram $diagram;
    private bool $header = false;
    private readonly ClassNamespaces $namespaces;
    private int $members = 0;

    public function __construct()
    {
        $this->diagram = new ClassDiagram();
        $this->namespaces = new ClassNamespaces($this->diagram->graph);
    }

    public function accept(MermaidStatement $statement): void
    {
        $text = $statement->text;
        if (!$this->header) {
            if (!\in_array($text, ['classDiagram', 'classDiagram-v2'], strict: true)) {
                throw $statement->error('Expected classDiagram');
            }
            $this->header = true;

            return;
        }
        if (';' === $text) {
            return;
        }
        $this->statement($statement);
    }

    private function statement(MermaidStatement $statement): void
    {
        $text = $statement->text;
        $match = [];
        match (true) {
            1 === \preg_match('/^direction\s+(TB|BT|LR|RL)$/', $text, $match) => $this->diagram->graph->direction = $match[1],
            1 === \preg_match('/^acc(?:Title|Descr)\s*[:{](.*)$/s', $text, $match) => $this->diagram->metadata[] = ['text' => \trim($match[1], characters: " \t\r\n}"), 'origin' => $statement->line],
            '}' === $text || 1 === \preg_match('/^namespace\s/', $text) => $this->namespaces->accept($statement),
            1 === \preg_match('/^class\s/', $text) => $this->declaration($statement),
            default => $this->outside($statement),
        };
    }

    private function declaration(MermaidStatement $statement): void
    {
        $match = [];
        if (
            1 !== \preg_match(
                '!^class\s+('.ClassLexer::NAME.')(?:\s*\[\s*"([^"]*)"\s*\])?(?:\s*:::\s*([\w-]+))?(?:\s*<<([\w-]+)>>)?(?:\s*\{(.*)\})?$!su',
                $statement->text,
                $match,
                flags: \PREG_UNMATCHED_AS_NULL,
            )
        ) {
            throw $statement->error('Invalid class declaration');
        }
        $id = $this->node($match[1], $statement);
        /** @var array{string, string, ?string, ?string, ?string, ?string} $match */
        if (null !== $match[2]) {
            $this->diagram->graph->nodes[$id]['label'] = MermaidText::decode($match[2]);
        }
        if (null !== $match[3]) {
            $this->diagram->graph->styles->assign($id, $match[3], $statement);
        }
        if (null !== $match[4]) {
            $this->diagram->annotations[$id][] = $match[4];
        }
        if (null !== $match[5]) {
            $offset = (int) \strpos($statement->text, needle: '{') + 1;
            $line = $statement->line + \substr_count(\substr($statement->text, offset: 0, length: $offset), needle: "\n");
            foreach (\explode("\n", $match[5]) as $member) {
                $this->member($id, $member, new MermaidStatement($member, $statement->offset + $offset, $line++, column: 0));
                $offset += \strlen($member) + 1;
            }
        }
    }

    private function outside(MermaidStatement $statement): void
    {
        $text = $statement->text;
        $match = [];
        match (true) {
            1 === \preg_match('/^(?:style|classDef)\s/', $text) => $this->diagram->graph->styles->accept($statement),
            1 === \preg_match('/^cssClass\s+"([^"]+)"\s+([\w-]+)$/', $text, $match) => $this->diagram
                ->graph
                ->styles
                ->accept(new MermaidStatement('class '.$match[1].' '.$match[2], $statement->offset, $statement->line, $statement->column)),
            1 === \preg_match('!^(?:<<|&lt;&lt;)([\w-]+)(?:>>|&gt;&gt;)\s*('.ClassLexer::NAME.')$!u', $text, $match) => $this->annotation($match[2], $match[1], $statement),
            default => $this->metadataOrRelationship($statement),
        };
    }

    private function annotation(string $name, string $annotation, MermaidStatement $statement): void
    {
        $id = $this->node($name, $statement);
        $this->diagram->annotations[$id][] = $annotation;
    }

    private function metadataOrRelationship(MermaidStatement $statement): void
    {
        $text = $statement->text;
        if (1 === \preg_match('/^(?:note|click|link|callback)\b/', $text)) {
            $this->diagram->metadata[] = ['text' => MermaidText::decode($text), 'origin' => $statement->line];
            $note = [];
            // Pinned notes draw as boxes; `\n` in their text breaks a line.
            if (1 === \preg_match('/^note\s+(?:for\s+('.ClassLexer::NAME.')\s+)?"(.*)"\s*$/su', $text, $note)) {
                $this->diagram->notes[] = [
                    'for' => $note[1],
                    'text' => MermaidText::decode(\str_replace('\\n', replace: "\n", subject: $note[2])),
                    'origin' => $statement->line,
                ];
            }

            return;
        }
        $this->relationshipOrMember($statement);
    }

    private function relationshipOrMember(MermaidStatement $statement): void
    {
        if ($this->relation($statement)) {
            return;
        }
        $match = [];
        $text = $statement->text;
        if (1 !== \preg_match('!^('.ClassLexer::NAME.')(?:\s*:\s*(.+))?$!su', $text, $match)) {
            throw $statement->error('Expected class declaration, member or relationship');
        }
        $id = $this->node($match[1], $statement);
        if (null !== ($match[2] ?? null)) {
            $this->member($id, $match[2], $statement);
        }
    }

    private function relation(MermaidStatement $statement): bool
    {
        $name = '('.ClassLexer::NAME.')';
        $marker = '('.ClassLexer::ENDPOINT.')';
        $match = [];
        if (
            1 !== \preg_match(
                '!^'.$name.'\s*(?:"([^"]*)"\s*)?'.$marker.'(--|\.\.)'.$marker.'\s*(?:"([^"]*)"\s*)?'.$name.'(?:\s*:\s*(.*))?$!u',
                $statement->text,
                $match,
                flags: \PREG_UNMATCHED_AS_NULL,
            )
        ) {
            return false;
        }
        $from = $this->node($match[1], $statement);
        $to = $this->node($match[7], $statement);
        $label = MermaidText::decode(\trim(($match[2] ?? '').' '.($match[8] ?? '').' '.($match[6] ?? '')));
        $this->diagram->graph->edge($from, $to, $label, '..' === $match[4] ? '-.-' : '--', $statement);
        $this->diagram->markers[] = ['left' => $match[3] ?? '', 'right' => $match[5] ?? ''];

        return true;
    }

    private function member(string $id, string $text, MermaidStatement $statement): void
    {
        $text = MermaidText::decode(\trim($text));
        if ('' === $text || \str_starts_with($text, '%%')) {
            return;
        }
        if (++$this->members > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the class member limit');
        }
        $match = [];
        if (1 === \preg_match('/^<<([\w-]+)>>$/', $text, $match)) {
            $this->diagram->annotations[$id][] = $match[1];

            return;
        }
        $this->diagram->members[$id][] = ['text' => $text, 'origin' => $statement->line];
    }

    private function node(string $name, MermaidStatement $statement): string
    {
        $parts = \explode('~', \str_replace('`', replace: '', subject: $name));
        $id = $parts[0];
        $this->diagram->types[$id] ??= $parts[1] ?? '';
        $this->diagram->graph->node($id, null, $statement, groups: []);
        $groups = $this->namespaces->groups();
        if ([] !== $groups) {
            $this->diagram->graph->nodes[$id]['groups'] = $groups;
        }

        return $id;
    }

    public function finish(): ClassDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected classDiagram', offset: 0, sourceLine: 0, column: 0);
        }
        $this->namespaces->finish();
        $this->diagram->declared = $this->namespaces->declared;

        return $this->diagram;
    }
}
