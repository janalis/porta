<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

use Porta\Config\CommandRegistry;
use Porta\Support\FuzzyMatcher;

/**
 * Actions source: fuzzy-matches IDE command labels (the Find-Action set), best
 * score first. Activating a hit runs the command.
 *
 * Each hit carries its KEY as the hint, which is how anyone learns a shortcut:
 * beside the thing they just searched for, in the same dialect the menus and
 * the hint strips use ({@see \Porta\Ui\ShortcutFormatter}, whose docblock has
 * said "the menu bar and the command palette" since before the palette showed
 * one).
 */
final readonly class ActionsSource implements SearchSource
{
    /**
     * @param (\Closure(string): string)|null $shortcutOf action id => its formatted key, '' when unbound;
     *                                                    null leaves every hint blank, which is what a
     *                                                    bare source outside a running IDE can honestly say
     */
    public function __construct(
        private CommandRegistry $commands,
        private ?\Closure $shortcutOf = null,
    ) {}

    #[\Override]
    public function category(): SearchCategory
    {
        return SearchCategory::Actions;
    }

    #[\Override]
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
            $hint = null === $this->shortcutOf ? '' : ($this->shortcutOf)($command['id']);
            $scored[] = [
                $score,
                new SearchHit(
                    SearchCategory::Actions,
                    $command['label'],
                    $hint,
                    static function () use ($run): void {
                        $run();
                    },
                    enabled: $command['enabled'],
                ),
            ];
        }
        \usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        $emit(\array_map(static fn (array $entry): SearchHit => $entry[1], $scored));
    }

    #[\Override]
    public function cancel(): void {}
}
