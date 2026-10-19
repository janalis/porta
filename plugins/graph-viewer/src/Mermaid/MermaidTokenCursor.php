<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Token grammars share bounded navigation and precise end-of-declaration locations. */
final class MermaidTokenCursor
{
    private int $position = 0;

    /** @param non-empty-list<MermaidStatement> $tokens */
    public function __construct(
        private readonly array $tokens,
        private readonly string $family,
    ) {}

    public function statement(): MermaidStatement
    {
        if (\array_key_exists($this->position, $this->tokens)) {
            return $this->tokens[$this->position];
        }
        $last = $this->tokens[\count($this->tokens) - 1];

        return $last->slice(\strlen($last->text), length: 0);
    }

    public function peek(): string
    {
        return \array_key_exists($this->position, $this->tokens) ? $this->tokens[$this->position]->text : '';
    }

    public function take(): string
    {
        $value = $this->peek();
        if ('' === $value) {
            throw $this->statement()->error('Incomplete '.$this->family.' declaration');
        }
        ++$this->position;

        return $value;
    }

    public function consume(string $literal): bool
    {
        if ($literal !== $this->peek()) {
            return false;
        }
        ++$this->position;

        return true;
    }

    public function expect(string $literal): void
    {
        if (!$this->consume($literal)) {
            throw $this->statement()->error('Expected '.$this->family.' '.$literal);
        }
    }

    public function finish(): void
    {
        if ('' !== $this->peek()) {
            throw $this->statement()->error('Unexpected token after '.$this->family.' declaration');
        }
    }
}
