<?php

declare(strict_types=1);

namespace Porta\Tests\Behat\Catalog;

/**
 * Which catalog ids the features exercise, and which they name wrongly.
 *
 * Evidence is a tag — `@action:<id>`, `@command:<id>`, `@setting:<key>` on a
 * feature or a scenario — or the step `I use the action "<id>"`, which counts
 * for an action or a command of that id. An exemption names an id no feature
 * can drive, with the reason; one that is also covered, or names nothing in
 * the catalog, is stale.
 */
final readonly class Coverage
{
    /** @var list<string> */
    public array $missing;

    /** @var list<string> */
    public array $unknown;

    /** @var list<string> */
    public array $staleExemptions;

    /**
     * @param array{action: list<string>, command: list<string>, setting: list<string>} $catalog
     * @param array<string, string>                                                     $features  path => Gherkin source
     * @param array<string, string>                                                     $exemptions "kind:id" => why no feature can drive it
     */
    public function __construct(array $catalog, array $features, array $exemptions)
    {
        $known = [];
        foreach ($catalog as $kind => $ids) {
            foreach ($ids as $id) {
                $known[$kind.':'.$id] = true;
            }
        }

        $covered = [];
        $unknown = [];
        foreach ($features as $path => $source) {
            foreach (self::evidence($source) as $ref) {
                if (\array_key_exists($ref, $known)) {
                    $covered[$ref] = true;

                    continue;
                }
                $used = self::used($ref, $known);
                if (null !== $used) {
                    $covered[$used] = true;

                    continue;
                }
                $unknown[] = $path.': '.$ref;
            }
        }

        $stale = [];
        foreach ($exemptions as $ref => $reason) {
            if (\array_key_exists($ref, $known) && !\array_key_exists($ref, $covered) && '' !== \trim($reason)) {
                continue;
            }
            $stale[] = $ref;
        }

        $this->missing = \array_values(\array_filter(
            \array_keys($known),
            static fn (string $ref): bool => !\array_key_exists($ref, $covered) && !\array_key_exists($ref, $exemptions),
        ));
        $this->unknown = $unknown;
        $this->staleExemptions = $stale;
    }

    public function passes(): bool
    {
        return [] === $this->missing && [] === $this->unknown && [] === $this->staleExemptions;
    }

    /**
     * The tags and the used actions of one feature file, as "kind:id".
     *
     * @return list<string>
     */
    private static function evidence(string $source): array
    {
        $refs = [];
        $matches = [];
        \preg_match_all('/(?<=^|\s)@(action|command|setting):(\S+)/m', $source, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $refs[] = $match[1].':'.$match[2];
        }
        \preg_match_all('/^\s*(?:Given|When|Then|And|But|\*)\s+I use the action "([^"]+)"/m', $source, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $refs[] = 'use:'.$match[1];
        }

        return $refs;
    }

    /**
     * What `I use the action "<id>"` covers: the action of that id, else the command.
     *
     * @param array<string, true> $known
     */
    private static function used(string $ref, array $known): ?string
    {
        $id = \str_starts_with($ref, 'use:') ? \substr($ref, \strlen('use:')) : null;
        $candidates = null === $id ? [] : ['action:'.$id, 'command:'.$id];
        $matched = \array_values(\array_filter($candidates, static fn (string $candidate): bool => \array_key_exists($candidate, $known)));

        return $matched[0] ?? null;
    }
}
