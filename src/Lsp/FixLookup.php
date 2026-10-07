<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * What the language servers offer for one problem, as far as is known yet:
 * still asking, nothing, or a fix — its title, which is what the problem
 * card's "Fix:" line names — and how many actions there are in all, which
 * says whether "More actions…" has anything behind it.
 */
final readonly class FixLookup
{
    private function __construct(
        public FixState $state,
        public ?string $title = null,
        public int $actions = 0,
    ) {}

    public static function pending(): self
    {
        return new self(FixState::Pending);
    }

    /** Nothing offered — or no server to ask. */
    public static function none(int $actions = 0): self
    {
        return new self(FixState::None, actions: $actions);
    }

    public static function found(string $title, int $actions): self
    {
        return new self(FixState::Found, $title, $actions);
    }
}
