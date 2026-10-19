<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Transitions and declarations share identities; start/end markers are scoped independently. */
final class StateParser
{
    private readonly StateDiagram $diagram;
    private readonly StateScopes $scopes;
    private bool $header = false;
    private int $notes = 0;

    public function __construct()
    {
        $this->diagram = new StateDiagram();
        $this->scopes = new StateScopes($this->diagram->graph);
    }

    public function accept(MermaidStatement $statement): void
    {
        $text = $statement->text;
        if (!$this->header) {
            if (1 !== \preg_match('/^stateDiagram(?:-v2)?$/i', $text)) {
                throw $statement->error('Expected stateDiagram');
            }
            $this->header = true;

            return;
        }
        if (';' === $text || \str_starts_with($text, '#')) {
            return;
        }
        $match = [];
        match (true) {
            '}' === $text => $this->scopes->close($statement),
            '--' === $text => $this->scopes->divide($statement),
            1 === \preg_match('/^direction\s+(TB|BT|LR|RL)$/i', $text, $match) => $this->scopes->direction(\strtoupper($match[1])),
            1 === \preg_match('/^(?:classDef|class|style)\s/i', $text) => $this->diagram->graph->styles->accept($statement),
            1 === \preg_match('/^state\s/i', $text) => $this->declaration($statement),
            default => $this->outside($statement),
        };
    }

    private function declaration(MermaidStatement $statement): void
    {
        $match = [];
        if (
            1 !== \preg_match(
                '!^state\s+(?:"([^"]*)"\s+as\s+('.StateLexer::NAME.')|('.StateLexer::NAME.'))(?:[\t ]*(?:<<|\[\[)(fork|join|choice)(?:>>|\]\]))?(?:[\t ]*:[\t ]*([^\r\n{]+))?\s*(\{)?$!iu',
                $statement->text,
                $match,
            )
        ) {
            throw $statement->error('Invalid state declaration');
        }
        $name = '' !== $match[2] ? $match[2] : $match[3];
        $id = $this->node($name, $statement, kind: 'state');
        if ('' !== $match[2]) {
            $this->diagram->descriptions[$id] = [['text' => MermaidText::decode($match[1]), 'origin' => $statement->line]];
        }
        if ('' !== ($match[4] ?? '')) {
            $this->diagram->kinds[$id] = \strtolower($match[4]);
        }
        if ('' !== ($match[5] ?? '')) {
            $this->diagram->descriptions[$id][] = ['text' => MermaidText::decode($match[5]), 'origin' => $statement->line];
        }
        if ('{' === ($match[6] ?? '')) {
            $this->scopes->open($id, $statement);
        }
    }

    private function outside(MermaidStatement $statement): void
    {
        $text = $statement->text;
        if (1 === \preg_match('/^(?:accTitle|accDescr|hide\s+empty\s+description|scale\s|click\s)/i', $text)) {
            $this->diagram->metadata[] = ['text' => MermaidText::decode($text), 'origin' => $statement->line];

            return;
        }
        $this->transitionOrNote($statement);
    }

    private function transitionOrNote(MermaidStatement $statement): void
    {
        $text = $statement->text;
        if (1 === \preg_match('/^note\s/i', $text)) {
            $this->note($statement);

            return;
        }
        $match = [];
        if (1 === \preg_match('!^('.StateLexer::ENDPOINT.')[\t ]*-->[\t ]*('.StateLexer::ENDPOINT.')(?:[\t ]*:[\t ]*(.*))?$!u', $text, $match)) {
            $from = $this->node($match[1], $statement, kind: 'start');
            $to = $this->node($match[2], $statement, kind: 'end');
            $this->diagram->graph->edge($from, $to, MermaidText::decode($match[3] ?? ''), '-->', $statement);

            return;
        }
        $this->description($statement);
    }

    private function description(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('!^('.StateLexer::ENDPOINT.')(?:[\t ]*:[\t ]*(.+))?$!su', $statement->text, $match)) {
            throw $statement->error('Expected state declaration or transition');
        }
        $id = $this->node($match[1], $statement, kind: 'state');
        if ('' !== ($match[2] ?? '')) {
            $this->diagram->descriptions[$id][] = ['text' => MermaidText::decode($match[2]), 'origin' => $statement->line];
        }
    }

    private function note(MermaidStatement $statement): void
    {
        $match = [];
        if (1 === \preg_match('!^note\s+"([^"]*)"\s+as\s+('.StateLexer::NAME.')$!isu', $statement->text, $match)) {
            $id = $this->node($match[2], $statement, kind: 'note');
            $this->diagram->graph->nodes[$id]['label'] = MermaidText::decode($match[1]);

            return;
        }
        if (1 !== \preg_match('!^note\s+(left|right)\s+of\s+('.StateLexer::NAME.')(?:[\t ]*:[\t ]*([^\r\n]+)|[\t ]*\r?\n([\s\S]*?)\r?\n[\t ]*end[\t ]+note)$!iu', $statement->text, $match)) {
            throw $statement->error('Invalid state note');
        }
        $target = $this->node($match[2], $statement, kind: 'state');
        $id = $this->node('@note:'.$this->notes++, $statement, kind: 'note');
        $text = '' !== ($match[3] ?? '') ? $match[3] : $match[4];
        $this->diagram->graph->nodes[$id]['label'] = MermaidText::decode(\trim($text));
        $left = 'left' === \strtolower($match[1]);
        $this->diagram->notes[$id] = ['target' => $target, 'side' => $left ? 'left' : 'right'];
        $this->diagram->graph->edge($left ? $id : $target, $left ? $target : $id, '', '-.-', $statement);
    }

    private function node(string $name, MermaidStatement $statement, string $kind): string
    {
        $parts = \explode(':::', $name, limit: 2);
        $id = \trim($parts[0]);
        if ('[*]' === $id) {
            $id = '@'.$kind.':'.$this->scopes->current();
        }
        if (!\array_key_exists($id, $this->diagram->graph->groups)) {
            $this->diagram->graph->node($id, null, $statement, groups: []);
            $groups = $this->scopes->groups();
            if ([] !== $groups && [] === $this->diagram->graph->nodes[$id]['groups']) {
                $this->diagram->graph->nodes[$id]['groups'] = $groups;
            }
        }
        $this->diagram->kinds[$id] ??= \str_starts_with($id, '@'.$kind.':') ? $kind : 'state';
        if ('note' === $kind) {
            $this->diagram->kinds[$id] = 'note';
        }
        if (\array_key_exists(1, $parts)) {
            $this->diagram->graph->styles->assign($id, \trim($parts[1]), $statement);
        }

        return $id;
    }

    public function finish(): StateDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected stateDiagram', offset: 0, sourceLine: 0, column: 0);
        }
        $this->scopes->finish();

        return $this->diagram;
    }
}
