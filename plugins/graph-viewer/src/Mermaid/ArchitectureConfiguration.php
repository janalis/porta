<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Random\Randomizer;

/** Native tie ordering is seeded independently of the upstream force solver. */
final readonly class ArchitectureConfiguration
{
    /** The options this family's native rendering reads here; MermaidConfiguration leaves them to this parser. */
    public const array KEYS = ['seed', 'randomize'];

    public function __construct(
        public ?int $seed = null,
        public ?bool $randomize = null,
    ) {}

    public static function parse(\stdClass $object, MermaidStatement $statement): self
    {
        $seed = null;
        $randomize = null;
        // @mago-expect analysis:mixed-assignment -- Closed options are narrowed before construction.
        foreach (\get_object_vars($object) as $key => $value) {
            if (!\in_array($key, self::KEYS, strict: true)) {
                continue; // Other pinned options are validated by MermaidOptionSchema.
            }
            if ('seed' === $key && \is_int($value) && $value >= 0 && $value <= 4_294_967_295) {
                $seed = $value;
                continue;
            }
            if ('randomize' === $key && \is_bool($value)) {
                $randomize = $value;
                continue;
            }
            throw $statement->error('Unsupported architecture configuration value ('.$key.')');
        }

        return new self($seed, $randomize);
    }

    public function overlay(self $later): self
    {
        return new self($later->seed ?? $this->seed, $later->randomize ?? $this->randomize);
    }

    public function configured(): bool
    {
        return null !== $this->seed || null !== $this->randomize;
    }

    /** @param list<array-key> $keys
     * @return list<array-key>
     */
    public function order(array $keys, string $scope, Randomizer $randomizer): array
    {
        $seed = $this->seed ?? 1;
        if (0 === $seed) {
            /** @var list<array-key> $shuffled The RNG only permutes the input keys. */
            // @mago-expect lint:inline-variable-return -- The local annotation preserves shuffleArray input element types.
            // @noinspection OneTimeUseVariablesInspection -- The local annotation preserves shuffleArray input element types.
            $shuffled = $randomizer->shuffleArray($keys);

            return $shuffled;
        }
        if (1 !== $seed || true === $this->randomize) {
            \usort($keys, static fn (string|int $a, string|int $b): int => \strcmp(\hash('sha256', $seed.':'.$scope.':'.$a), \hash('sha256', $seed.':'.$scope.':'.$b)));
        }

        return $keys;
    }
}
