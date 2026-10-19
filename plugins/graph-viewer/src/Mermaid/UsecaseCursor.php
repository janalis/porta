<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** A declaration cursor keeps source byte offsets while suffixes remain strictly ordered. */
final class UsecaseCursor
{
    public int $position = 0;

    public function __construct(
        public readonly MermaidStatement $statement,
    ) {}

    public function rest(): string
    {
        $this->position += \strspn($this->statement->text, characters: " \t\r\n", offset: $this->position);

        return \substr($this->statement->text, $this->position);
    }

    public function consume(string $literal): bool
    {
        if (!\str_starts_with($this->rest(), $literal)) {
            return false;
        }
        $this->position += \strlen($literal);

        return true;
    }

    public function expect(string $literal): void
    {
        if (!$this->consume($literal)) {
            throw $this->statement->error('Expected use-case '.$literal, $this->position);
        }
    }

    public function identifier(): string
    {
        $this->rest();
        $match = [];
        if (1 !== \preg_match('/\G'.UsecaseLabels::IDENTIFIER.'/', $this->statement->text, $match, offset: $this->position)) {
            throw $this->statement->error('Expected a use-case identifier', $this->position);
        }
        $id = UsecaseLabels::identifier($match[0], $this->statement->slice($this->position));
        $this->position += \strlen($match[0]);

        return $id;
    }

    /** @return array{raw: string, label: string, markdown: bool} */
    public function quoted(): array
    {
        $this->rest();
        $match = [];
        if (1 !== \preg_match('/\G(?:'.UsecaseLabels::QUOTED.')/', $this->statement->text, $match, offset: $this->position)) {
            throw $this->statement->error('Expected a quoted use-case label', $this->position);
        }
        $this->position += \strlen($match[0]);

        return UsecaseLabels::quoted($match[0]);
    }

    /** @return array{raw: string, label: string, markdown: bool} */
    public function label(string $close): array
    {
        $rest = $this->rest();
        if (\str_starts_with($rest, '"') || \str_starts_with($rest, "'")) {
            $label = $this->quoted();
            $this->expect($close);

            return $label;
        }
        $end = \strpos($this->statement->text, $close, $this->position);
        $raw = false === $end ? '' : \trim(\substr($this->statement->text, $this->position, $end - $this->position));
        if (false === $end || '' === $raw || 1 === \preg_match('/[\[\](){}"\'\r\n]|--|\.\.>|:::|@\{|<</', $raw)) {
            throw $this->statement->error('Invalid unquoted use-case label', $this->position);
        }
        $this->position = $end + \strlen($close);

        return ['raw' => $raw, 'label' => MermaidText::decode($raw), 'markdown' => false];
    }

    /** @return array{id: string, raw: string, label: string, markdown: bool, generated: bool, shaped: bool, shape: string} */
    public function name(string $kind): array
    {
        $base = $this->base();
        $id = $base['id'];
        $generated = $base['generated'];
        $label = ['raw' => $base['raw'], 'label' => $base['label'], 'markdown' => $base['markdown']];
        $shape = 'actor' === $kind ? 'actor' : 'ellipse';
        $shaped = false;
        if (!$generated && ($this->consume('(') || $this->consume('['))) {
            $opener = $this->statement->text[$this->position - 1];
            if ('[' === $opener && 'actor' === $kind) {
                throw $this->statement->error('Actor labels require parentheses', $this->position - 1);
            }
            $label = $this->label('(' === $opener ? ')' : ']');
            $shape = '[' === $opener ? 'rect' : $shape;
            $shaped = true;
        }

        return ['id' => $id, ...$label, 'generated' => $generated, 'shaped' => $shaped, 'shape' => $shape];
    }

    /** @return array{id: string, raw: string, label: string, markdown: bool, generated: bool} */
    private function base(): array
    {
        $rest = $this->rest();
        if (\str_starts_with($rest, '"') || \str_starts_with($rest, "'")) {
            $label = $this->quoted();

            return ['id' => UsecaseLabels::generated($label['raw']), ...$label, 'generated' => true];
        }
        $id = $this->identifier();

        return ['id' => $id, 'raw' => $id, 'label' => $id, 'markdown' => false, 'generated' => false];
    }

    /** @return list<array{key: string, value: string|bool, statement: MermaidStatement}> */
    public function metadata(): array
    {
        $this->rest();
        $start = $this->position;
        if (!$this->consume('@{')) {
            return [];
        }
        $quote = new UsecaseQuotedText();
        $length = \strlen($this->statement->text);
        while ($this->position < $length) {
            $char = $this->statement->text[$this->position];
            ++$this->position;
            if ($quote->advance($char, $this->statement->text[$this->position] ?? '') && '}' === $char) {
                return UsecaseMetadata::parse($this->statement->slice($start, $this->position - $start));
            }
        }
        throw $this->statement->error('Unclosed use-case metadata', $start);
    }

    public function stereotype(): ?string
    {
        if (!$this->consume('<<')) {
            return null;
        }
        $end = \strpos($this->statement->text, needle: '>>', offset: $this->position);
        if (false === $end) {
            throw $this->statement->error('Unclosed use-case stereotype', $this->position);
        }
        $value = \trim(\substr($this->statement->text, $this->position, $end - $this->position));
        if ('' === $value || \str_contains($value, "\n")) {
            throw $this->statement->error('Expected one use-case stereotype', $this->position);
        }
        $this->position = $end + 2;

        return MermaidText::decode($value);
    }

    /** @return list<string> */
    public function classes(): array
    {
        $classes = [];
        if ($this->consume(':::')) {
            $classes[] = $this->identifier();
            while ($this->consume(',')) {
                $classes[] = $this->identifier();
            }
        }

        return $classes;
    }

    public function finish(): void
    {
        if ('' !== $this->rest()) {
            throw $this->statement->error('Unexpected token after a complete use-case statement', $this->position);
        }
    }
}
