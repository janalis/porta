<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** A bounded grammar expression retains the authored source of each selectable operand. */
final readonly class RailroadExpression
{
    /** @param list<self> $children */
    public function __construct(
        public string $type,
        public MermaidStatement $statement,
        public string $label = '',
        public array $children = [],
        public int $min = 0,
        public ?int $max = null,
    ) {}

    public function validate(): void
    {
        $pending = [[$this, 0]];
        while ([] !== $pending) {
            [$node, $depth] = \array_pop($pending);
            if ($depth > 128) {
                throw $node->statement->error('Railroad expression tree exceeds 128 levels');
            }
            foreach ($node->children as $child) {
                $pending[] = [$child, $depth + 1];
            }
        }
    }
}
