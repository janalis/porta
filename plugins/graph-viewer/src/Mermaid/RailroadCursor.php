<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Recursive descent preserves the precedence and transformations of each pinned grammar. */
final readonly class RailroadCursor
{
    private MermaidTokenCursor $tokens;

    /** @param non-empty-list<MermaidStatement> $tokens */
    public function __construct(
        array $tokens,
        private string $mode,
    ) {
        $this->tokens = new MermaidTokenCursor($tokens, 'railroad');
    }

    /** @return array{name: string, definition: RailroadExpression, statement: MermaidStatement} */
    public function rule(): array
    {
        $statement = $this->tokens->statement();
        $name = $this->identifier();
        if ('ebnf' !== $this->mode || !$this->tokens->consume('::=')) {
            $this->tokens->expect('peg' === $this->mode ? '<-' : '=');
        }
        $expression = $this->expression(0);
        $expression->validate();
        $this->tokens->expect(';');
        $this->tokens->finish();

        return ['name' => $name, 'definition' => $expression, 'statement' => $statement];
    }

    private function identifier(): string
    {
        $at = $this->tokens->statement();
        $raw = $this->tokens->take();
        $pattern = 'abnf' === $this->mode ? '[A-Za-z][A-Za-z0-9-]*' : '[A-Za-z_][A-Za-z0-9_-]*';
        if (
            1 !== \preg_match('/^'.$pattern.'$/', $raw)
            || ('ir' === $this->mode && \in_array($raw, ['sequence', 'choice', 'optional', 'oneOrMore', 'zeroOrMore', 'terminal', 'nonterminal', 'special'], strict: true))
        ) {
            throw $at->error('Expected a railroad identifier');
        }

        return $raw;
    }

    private function expression(int $depth): RailroadExpression
    {
        if ($depth > 128) {
            throw $this->tokens->statement()->error('Railroad nesting exceeds 128 levels');
        }
        if ('ir' === $this->mode) {
            return $this->constructor($depth);
        }
        $at = $this->tokens->statement();
        $alternatives = [$this->sequence($depth)];
        $operator = 'ebnf' === $this->mode ? '|' : '/';
        while ($this->tokens->consume($operator)) {
            $alternatives[] = $this->sequence($depth);
        }

        return 1 === \count($alternatives) ? $alternatives[0] : new RailroadExpression('choice', $at, children: $alternatives);
    }

    private function sequence(int $depth): RailroadExpression
    {
        $at = $this->tokens->statement();
        $elements = [$this->term($depth)];
        while (!\in_array($this->tokens->peek(), ['', ';', ')', ']', '}', '|', '/'], strict: true)) {
            if ('ebnf' === $this->mode) {
                $this->tokens->consume(',');
            }
            $elements[] = $this->term($depth);
        }

        return 1 === \count($elements) ? $elements[0] : new RailroadExpression('sequence', $at, children: $elements);
    }

    private function term(int $depth): RailroadExpression
    {
        return match ($this->mode) {
            'abnf' => $this->abnf($depth),
            'peg' => $this->peg($depth),
            default => $this->ebnf($depth),
        };
    }

    private function constructor(int $depth): RailroadExpression
    {
        $at = $this->tokens->statement();
        $kind = $this->tokens->take();
        $this->tokens->expect('(');
        if (\in_array($kind, ['terminal', 'nonterminal', 'special'], strict: true)) {
            $label = $this->literal();
            $this->tokens->expect(')');

            return new RailroadExpression($kind, $at, $label);
        }
        if (!\in_array($kind, ['sequence', 'choice', 'optional', 'oneOrMore', 'zeroOrMore'], strict: true)) {
            throw $at->error('Unknown railroad constructor '.$kind);
        }
        $children = [$this->expression($depth + 1)];
        if (\in_array($kind, ['sequence', 'choice'], strict: true)) {
            while ($this->tokens->consume(',')) {
                $children[] = $this->expression($depth + 1);
            }
        }
        $this->tokens->expect(')');
        $type = match ($kind) {
            'oneOrMore', 'zeroOrMore' => 'repetition',
            default => $kind,
        };

        return \in_array($kind, ['sequence', 'choice'], strict: true) && 1 === \count($children)
            ? $children[0]
            : new RailroadExpression($type, $at, children: $children, min: 'oneOrMore' === $kind ? 1 : 0);
    }

    private function literal(): string
    {
        $at = $this->tokens->statement();
        $raw = $this->tokens->take();
        if (('abnf' === $this->mode && '"' !== $raw[0]) || !\in_array($raw[0], ['"', "'"], strict: true)) {
            throw $at->error('Expected a railroad quoted string');
        }

        return RailroadText::quoted($raw, $this->mode);
    }

    private function primary(int $depth): RailroadExpression
    {
        $at = $this->tokens->statement();
        $raw = $this->tokens->peek();

        return match (true) {
            \in_array($raw[0] ?? '', ['"', "'"], strict: true) => new RailroadExpression('terminal', $at, $this->literal()),
            \in_array($raw, ['(', '[', '{'], strict: true) => $this->group($depth),
            ('peg' === $this->mode && '.' === $raw) || ('ebnf' === $this->mode && \str_starts_with($raw, '?')) => $this->special(),
            'abnf' === $this->mode && \str_starts_with($raw, '%') => new RailroadExpression('terminal', $at, $this->tokens->take()),
            default => new RailroadExpression('nonterminal', $at, $this->identifier()),
        };
    }

    private function special(): RailroadExpression
    {
        $at = $this->tokens->statement();
        $raw = $this->tokens->take();

        return new RailroadExpression('special', $at, 'peg' === $this->mode ? '.' : \trim(\substr($raw, offset: 1, length: -1)));
    }

    private function group(int $depth): RailroadExpression
    {
        $at = $this->tokens->statement();
        $open = $this->tokens->take();
        if (('peg' === $this->mode && '(' !== $open) || ('abnf' === $this->mode && '{' === $open)) {
            throw $at->error('Unsupported railroad grouping delimiter');
        }
        $inner = $this->expression($depth + 1);
        $this->tokens->expect(match ($open) {
            '(' => ')',
            '[' => ']',
            default => '}',
        });

        return match ($open) {
            '[' => new RailroadExpression('optional', $at, children: [$inner]),
            '{' => new RailroadExpression('repetition', $at, children: [$inner]),
            default => $inner,
        };
    }

    private function ebnf(int $depth): RailroadExpression
    {
        $node = $this->primary($depth);
        $wrappers = 0;
        while (\in_array($this->tokens->peek(), ['?', '*', '+', '-'], strict: true)) {
            if (++$wrappers > 128) {
                throw $this->tokens->statement()->error('Railroad postfix nesting exceeds 128 levels');
            }
            $at = $this->tokens->statement();
            $operator = $this->tokens->take();
            $node = '-' === $operator
                ? new RailroadExpression('sequence', $at, children: [$node, new RailroadExpression('terminal', $at, '-'), $this->primary($depth)])
                : self::postfix($node, $operator, $at);
        }

        return $node;
    }

    private function peg(int $depth): RailroadExpression
    {
        $at = $this->tokens->statement();
        $prefix = \in_array($this->tokens->peek(), ['!', '&'], strict: true) ? $this->tokens->take() : '';
        $node = $this->primary($depth);
        if (\in_array($this->tokens->peek(), ['?', '*', '+'], strict: true)) {
            $node = self::postfix($node, $this->tokens->take(), $at);
        }
        if ('' !== $prefix) {
            $label = match ($node->type) {
                'terminal' => '"'.$node->label.'"',
                'nonterminal', 'special' => $node->label,
                default => '(...)',
            };
            $node = new RailroadExpression('special', $at, $prefix.$label);
        }

        return $node;
    }

    private function abnf(int $depth): RailroadExpression
    {
        $at = $this->tokens->statement();
        $repeat = $this->tokens->peek();
        if (1 !== \preg_match('/^(?:\d*\*\d*|\d+)$/', $repeat)) {
            return $this->primary($depth);
        }
        $this->tokens->take();
        $pieces = \explode('*', $repeat);
        $min = (int) $pieces[0];
        $max = match (true) {
            1 === \count($pieces) => $min,
            '' === $pieces[1] => null,
            default => (int) $pieces[1],
        };
        if ($min > 1_000_000 || (null !== $max && $max > 1_000_000)) {
            throw $at->error('Railroad repetition bound exceeds one million');
        }
        $inner = $this->primary($depth);

        return (
            0 === $min && 1 === $max
                ? new RailroadExpression('optional', $at, children: [$inner])
                : new RailroadExpression('repetition', $at, children: [$inner], min: $min, max: $max)
        );
    }

    private static function postfix(RailroadExpression $node, string $operator, MermaidStatement $at): RailroadExpression
    {
        return (
            '?' === $operator
                ? new RailroadExpression('optional', $at, children: [$node])
                : new RailroadExpression('repetition', $at, children: [$node], min: '+' === $operator ? 1 : 0)
        );
    }
}
