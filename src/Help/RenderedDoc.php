<?php

declare(strict_types=1);

namespace Porta\Help;

/**
 * A markdown document rendered for one pane width: the styled ANSI lines and
 * the link spans keyed by rendered position, ready for the help viewer's
 * Tab-cycling and click hit-testing.
 */
final readonly class RenderedDoc
{
    /**
     * @param list<string>   $lines
     * @param list<LinkSpan> $links ordered by group, then line
     */
    public function __construct(
        public array $lines,
        public array $links,
    ) {}

    /** The number of distinct links (Tab cycles these groups). */
    public function groupCount(): int
    {
        $max = -1;
        foreach ($this->links as $span) {
            $max = \max($max, $span->group);
        }

        return $max + 1;
    }

    /** @return list<LinkSpan> every rendered fragment of one link */
    public function spansOfGroup(int $group): array
    {
        return \array_values(\array_filter($this->links, static fn (LinkSpan $span): bool => $span->group === $group));
    }

    /** The link target of a group, or null for an unknown group. */
    public function target(int $group): ?string
    {
        foreach ($this->links as $span) {
            if ($span->group === $group) {
                return $span->target;
            }
        }

        return null;
    }
}
