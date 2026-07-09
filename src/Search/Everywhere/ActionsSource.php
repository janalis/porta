<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

use Porta\Config\CommandRegistry;
use Porta\Support\FuzzyMatcher;

/**
 * Actions source: fuzzy-matches IDE command labels (the Find-Action set), best
 * score first. Activating a hit runs the command.
 */
final class ActionsSource implements SearchSource
{
    public function __construct(
        private readonly CommandRegistry $commands,
    ) {}

    public function category(): SearchCategory
    {
        return SearchCategory::Actions;
    }

    public function query(string $query, \Closure $emit): void
    {
        /** @var list<array{int, SearchHit}> $scored */
        $scored = [];
        foreach ($this->commands->paletteCommands() as $command) {
            $score = FuzzyMatcher::score($command['label'], $query);
            if (null === $score) {
                continue;
            }
            $run = $command['run'];
            $scored[] = [$score, new SearchHit(SearchCategory::Actions, $command['label'], '', static function () use ($run): void {
                    $run();
                })];
        }
        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        $emit(array_map(static fn (array $entry): SearchHit => $entry[1], $scored));
    }

    public function cancel(): void {}
}
