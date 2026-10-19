<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Newline-aware token statements preserve the pinned implicit-text indentation mode. */
final class VennParser
{
    private readonly VennDiagram $diagram;
    private bool $header = false;
    private bool $bol = false;
    private bool $indent = false;
    private bool $implicit = false;
    /** @var non-empty-list<string>|null */
    private ?array $current = null;
    /** @var list<MermaidStatement> */
    private array $pending = [];
    private string $kind = '';
    private int $count = 0;

    public function __construct()
    {
        $this->diagram = new VennDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if ('' === $statement->text) {
            return;
        }
        match (true) {
            "\n" === $statement->text => $this->newline(),
            !$this->header => $this->start($statement),
            default => $this->token($statement),
        };
    }

    private function newline(): void
    {
        $this->declaration();
        $this->bol = true;
    }

    private function start(MermaidStatement $statement): void
    {
        if ('venn-beta' !== \strtolower($statement->text)) {
            throw $statement->error('Expected venn-beta header');
        }
        $this->header = true;
        $this->diagram->origin = $statement->line;
        $this->bol = false;
    }

    private function token(MermaidStatement $statement): void
    {
        $raw = \strtolower($statement->text);
        if ('venn-beta' === $raw) {
            throw $statement->error('Unexpected Venn header');
        }
        $kind = 1 === \preg_match('/^title\s/i', $raw) ? 'title' : $raw;
        $start = \in_array($kind, ['set', 'union', 'text', 'style', 'title'], strict: true);
        if ($start) {
            $this->declaration();
            $this->implicit = 'text' === $kind && $this->bol && $statement->column > 0 && $this->indent;
            if ($this->bol && !$this->implicit) {
                $this->indent = false;
            }
            $this->kind = $kind;
        }
        if ([] === $this->pending && !$start) {
            throw $statement->error('Expected a Venn statement');
        }
        if (\count($this->pending) >= 8192) {
            throw $statement->error('Venn declaration exceeds the field limit');
        }
        $this->bol = false;
        $this->pending[] = $statement;
    }

    private function declaration(): void
    {
        if ([] === $this->pending) {
            return;
        }
        $statement = $this->pending[0];
        if (++$this->count > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Venn declaration limit exceeded');
        }
        $cursor = new VennCursor($this->pending);
        $this->pending = [];
        $cursor->tokens->take();
        match ($this->kind) {
            'set', 'union' => $this->subset($cursor, $statement),
            'text' => $this->text($cursor, $statement),
            'style' => $this->style($cursor, $statement),
            default => $this->diagram->title = $statement->slice(6),
        };
        $cursor->tokens->finish();
    }

    private function subset(VennCursor $cursor, MermaidStatement $statement): void
    {
        $ids = 'set' === $this->kind ? [$cursor->identifier()] : $cursor->identifiers();
        if ('union' === $this->kind) {
            if (\count($ids) < 2) {
                throw $statement->error('Venn union requires multiple identifiers');
            }
            $this->diagram->validate($ids, $statement);
        }
        $label = $cursor->label();
        $size = $cursor->size();
        $this->diagram->subset($ids, $label, $size, $statement);
        $this->current = VennDiagram::identifiers($ids);
        $this->indent = true;
    }

    private function text(VennCursor $cursor, MermaidStatement $statement): void
    {
        $ids = $this->implicit ? $this->current : $cursor->identifiers();
        /** @var non-empty-list<string> $ids Indentation mode is entered only by a successful subset declaration. */
        $at = $cursor->tokens->statement();
        $raw = $cursor->tokens->take();
        if (1 !== \preg_match('/^(?:'.VennLexer::ID.'|'.VennLexer::NUMBER.'|"[^"]*")$/', $raw)) {
            throw $at->error('Expected a Venn text identifier');
        }
        $label = $cursor->label();
        $this->diagram->texts[] = [
            'sets' => VennDiagram::identifiers($ids),
            'id' => VennDiagram::normalize($raw),
            'label' => null === $label || '' === $label ? null : VennDiagram::normalize($label),
            'statement' => $statement,
        ];
    }

    private function style(VennCursor $cursor, MermaidStatement $statement): void
    {
        $ids = $cursor->identifiers();
        $styles = $cursor->styles();
        $this->diagram->styles[] = ['targets' => VennDiagram::identifiers($ids), 'styles' => $styles, 'statement' => $statement];
    }

    public function finish(): VennDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected venn-beta header', offset: 0, sourceLine: 0, column: 0);
        }
        $this->declaration();

        return $this->diagram;
    }
}
