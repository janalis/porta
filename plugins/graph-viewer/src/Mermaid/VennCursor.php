<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Venn's string literals deliberately have no backslash escape grammar. */
final readonly class VennCursor
{
    public MermaidTokenCursor $tokens;

    /** @param non-empty-list<MermaidStatement> $tokens */
    public function __construct(array $tokens)
    {
        $this->tokens = new MermaidTokenCursor($tokens, 'Venn');
    }

    public function identifier(): string
    {
        $at = $this->tokens->statement();
        $raw = $this->tokens->take();
        if (1 !== \preg_match('/^(?:'.VennLexer::ID.'|"[^"]*")$/', $raw)) {
            throw $at->error('Expected a Venn set identifier');
        }

        return VennDiagram::normalize($raw);
    }

    /** @return non-empty-list<string> */
    public function identifiers(): array
    {
        $ids = [$this->identifier()];
        while ($this->tokens->consume(',')) {
            $ids[] = $this->identifier();
        }

        return $ids;
    }

    public function label(): ?string
    {
        $raw = $this->tokens->peek();
        if (!\str_starts_with($raw, '[')) {
            return null;
        }
        $this->tokens->take();

        return \str_starts_with($raw, '["') ? \substr($raw, offset: 2, length: -2) : \trim(\substr($raw, offset: 1, length: -1));
    }

    public function size(): ?float
    {
        if (!$this->tokens->consume(':')) {
            return null;
        }
        $at = $this->tokens->statement();
        $raw = $this->tokens->take();
        if (!\is_numeric($raw) || 1 !== \preg_match('/^'.VennLexer::NUMBER.'$/', $raw) || !\is_finite((float) $raw) || \abs((float) $raw) > 1.0e12) {
            throw $at->error('Venn sizes must be finite numbers bounded by one trillion');
        }

        return (float) $raw;
    }

    /** @return array<string, string> */
    public function styles(): array
    {
        $styles = [];
        do {
            $at = $this->tokens->statement();
            $key = $this->tokens->take();
            if (1 !== \preg_match('/^'.VennLexer::ID.'$/', $key)) {
                throw $at->error('Expected a Venn style property');
            }
            $this->tokens->expect(':');
            $values = $this->value();
            $styles[$key] = VennDiagram::normalize(\implode(' ', $values));
        } while ($this->tokens->consume(','));

        return $styles;
    }

    /** @return non-empty-list<string> */
    private function value(): array
    {
        $at = $this->tokens->statement();
        $first = $this->tokens->take();
        if (\str_starts_with($first, '"')) {
            return [VennDiagram::normalize($first)];
        }
        $values = [$first];
        while ('' !== $this->tokens->peek() && ',' !== $this->tokens->peek()) {
            $values[] = $this->tokens->take();
        }
        foreach ($values as $value) {
            if (1 !== \preg_match('/^(?:'.VennLexer::ID.'|'.VennLexer::NUMBER.'|#[0-9a-f]{3,8}|rgba?\([^)]+\))$/i', $value)) {
                throw $at->error('Expected a Venn style value');
            }
        }

        return $values;
    }
}
