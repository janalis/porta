<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** A bounded declaration cursor retains each token's original error location. */
final readonly class EventModelingCursor
{
    public MermaidTokenCursor $tokens;

    /** @param non-empty-list<MermaidStatement> $tokens */
    public function __construct(array $tokens)
    {
        $this->tokens = new MermaidTokenCursor($tokens, 'event-modeling');
    }

    public function identifier(): string
    {
        $at = $this->tokens->statement();
        $id = $this->tokens->take();
        if (
            1 !== \preg_match('/^'.EventModelingLexer::ID.'$/', $id)
            || \in_array($id, [...EventModelingLexer::TOP, ...EventModelingLexer::ROLES, ...EventModelingLexer::DATA_TYPES, 'given', 'when', 'then', 'eventmodeling'], strict: true)
        ) {
            throw $at->error('Expected an event-modeling entity identifier');
        }

        return $id;
    }

    public function qualified(): string
    {
        $id = $this->identifier();
        while ($this->tokens->consume('.')) {
            $id .= '.'.$this->identifier();
        }

        return $id;
    }

    public function frame(): string
    {
        $at = $this->tokens->statement();
        $value = $this->tokens->take();
        if (1 !== \preg_match('/^\d{1,3}$/', $value)) {
            throw $at->error('Expected a one-to-three-digit event-modeling frame ID');
        }

        return $value;
    }

    public function role(): string
    {
        $at = $this->tokens->statement();
        $value = $this->tokens->take();
        if (!\in_array($value, EventModelingLexer::ROLES, strict: true)) {
            throw $at->error('Expected an event-modeling entity type');
        }

        return $value;
    }

    /** @return array{type: ?string, value: string} */
    public function data(string $kind): array
    {
        $type = null;
        if ($this->tokens->consume('`')) {
            $at = $this->tokens->statement();
            $type = $this->tokens->take();
            if (!\in_array($type, EventModelingLexer::DATA_TYPES, strict: true)) {
                throw $at->error('Unknown event-modeling data type');
            }
            $this->tokens->expect('`');
        }
        $at = $this->tokens->statement();
        $raw = $this->tokens->take();
        $block = 1 === \preg_match('/^\{[\t ]*\r?\n/', $raw);
        $valid = $block ? 'block' === $kind : 'inline' === $kind && \in_array($raw[0], ['{', '"', "'"], strict: true);
        if (!$valid) {
            throw $at->error('Expected event-modeling '.$kind.' data');
        }

        return ['type' => $type, 'value' => $raw];
    }
}
