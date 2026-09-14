<?php

declare(strict_types=1);

namespace Porta\Bootstrap;

use Porta\Container\AsIdeService;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Constructs every `#[AsEager]`-marked service once at the container ctor tail.
 * Iterating a tagged iterator instantiates each entry (the same mechanism
 * {@see \Porta\Command\CommandRegistryPopulator} uses for command providers);
 * the tag's priority order is preserved, so contractual construction order
 * (find→picker→toast) holds. This replaces the scattered eager
 * `$this->service(X::class)` resolves that {@see \Porta\Ide} used to carry.
 */
#[AsIdeService]
final readonly class EagerServices
{
    /**
     * @param iterable<object> $eager the `porta.eager`-tagged services, priority-ordered
     */
    public function __construct(
        #[AutowireIterator('porta.eager')]
        private iterable $eager,
    ) {}

    /** Force-construct every eager service (for its self-registering side effect). */
    public function boot(): void
    {
        \iterator_to_array($this->eager, preserve_keys: false);
    }
}
