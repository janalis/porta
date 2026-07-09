<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Ui\ScopeKind;

/**
 * One entry in the Find-in-Files scope selector cycled with Alt+S: either a
 * built-in {@see ScopeKind} (resolved against the live editor) or a saved scope
 * carrying its own fixed grep paths. Exactly one of `kind`/`paths` is set.
 */
final readonly class ScopeChoice
{
    /** @param list<string>|null $paths */
    private function __construct(
        public string $label,
        public ?ScopeKind $kind,
        public ?array $paths,
    ) {}

    public static function builtin(ScopeKind $kind): self
    {
        return new self($kind->label(), $kind, null);
    }

    /** @param list<string> $paths */
    public static function saved(string $label, array $paths): self
    {
        return new self($label, null, $paths);
    }
}
