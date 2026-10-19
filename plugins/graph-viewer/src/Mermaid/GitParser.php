<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Pinned Langium Git commands; history validation stays separate from lexical options. */
final class GitParser
{
    private readonly GitDiagram $diagram;
    private readonly GitHistory $history;
    private bool $header = false;
    private int $statements = 0;

    public function __construct(GitConfiguration $configuration = new GitConfiguration())
    {
        $this->diagram = new GitDiagram();
        $this->diagram->current = $configuration->name();
        $this->diagram->branches = [$configuration->name() => ['tip' => null, 'order' => $configuration->order(), 'origin' => 0]];
        $this->history = new GitHistory($this->diagram);
    }

    public function accept(MermaidStatement $statement): void
    {
        $match = [];
        if (!$this->header) {
            if (1 !== \preg_match('/^gitGraph(?:[\t ]+(LR|TB|BT)[\t ]*:|[\t ]*:)?$/', $statement->text, $match)) {
                throw $statement->error('Expected gitGraph with optional direction');
            }
            $this->header = true;
            $this->diagram->direction = '' !== ($match[1] ?? '') ? $match[1] : 'LR';
            $this->diagram->origin = $statement->line;
            $this->diagram->branches[$this->diagram->current]['origin'] = $statement->line;

            return;
        }
        if (++$this->statements > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Git graph exceeds the command limit');
        }
        if (1 === \preg_match('/^(?:title\b|accTitle\s*:|accDescr\s*[:{])/', $statement->text)) {
            $this->diagram->metadata[] = ['text' => MermaidText::decode($statement->text), 'origin' => $statement->line];

            return;
        }
        if (1 !== \preg_match('/^(commit|branch|merge|checkout|switch|cherry-pick)\b[\t ]*(.*)$/s', $statement->text, $match)) {
            throw $statement->error('Expected Git graph command');
        }
        $options = GitArguments::parse($match[1], $match[2], $statement);
        $this->history->accept($match[1], $options, $statement);
    }

    public function finish(): GitDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected gitGraph', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
