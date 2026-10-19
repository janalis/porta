<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Semicolon-delimited rules are parsed into a shared bounded expression tree. */
final class RailroadParser
{
    private readonly RailroadDiagram $diagram;
    private ?string $mode = null;
    /** @var list<MermaidStatement> */
    private array $pending = [];

    public function __construct()
    {
        $this->diagram = new RailroadDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if ('' === $statement->text) {
            return;
        }
        if (null === $this->mode) {
            $this->header($statement);

            return;
        }
        $this->token($statement);
    }

    private function header(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('/^railroad(?:-(ebnf|abnf|peg))?-beta$/', $statement->text, $match)) {
            throw $statement->error('Expected a railroad header');
        }
        $this->mode = $match[1] ?? 'ir';
        $this->diagram->origin = $statement->line;
    }

    private function token(MermaidStatement $statement): void
    {
        if ([] === $this->pending && [] === $this->diagram->rules && $this->metadata($statement)) {
            return;
        }
        if (\count($this->pending) >= 8192) {
            throw $statement->error('Railroad rule exceeds the field limit');
        }
        $this->pending[] = $statement;
        if (';' !== $statement->text) {
            return;
        }
        if (\count($this->diagram->rules) >= MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Railroad rule limit exceeded');
        }
        $cursor = new RailroadCursor($this->pending, $this->mode ?? 'ir');
        $this->pending = [];
        $this->diagram->rules[] = $cursor->rule();
    }

    private function metadata(MermaidStatement $statement): bool
    {
        $match = [];
        if (1 !== \preg_match('/^(title(?=\s|$)|accTitle(?=[\t ]*:)|accDescr(?=[\t ]*:|\s*\{))/', $statement->text, $match)) {
            return false;
        }
        $key = $match[1];
        $value = \trim(\substr($statement->text, \strlen($key)));
        $value = \ltrim($value, characters: ':');
        $value = \trim($value);
        if ('accDescr' === $key && \str_starts_with($value, '{')) {
            $value = \substr($value, offset: 1, length: -1);
        }
        $value = MermaidMetadata::normalize($value);
        if ('title' === $key && \strlen($value) >= 2 && \in_array($value[0], ['"', "'"], strict: true) && \str_ends_with($value, $value[0])) {
            $value = RailroadText::quoted($value, $this->mode ?? 'ir');
        }
        $this->diagram->metadata[$key] = $value;
        if ('title' === $key) {
            $this->diagram->title = new MermaidStatement($value, $statement->offset, $statement->line, $statement->column);
        }

        return true;
    }

    public function finish(): RailroadDiagram
    {
        if (null === $this->mode) {
            throw new MermaidSyntaxError('Expected a railroad header', offset: 0, sourceLine: 0, column: 0);
        }
        if ([] !== $this->pending) {
            throw $this->pending[0]->error('Railroad rules must end with a semicolon');
        }

        return $this->diagram;
    }
}
