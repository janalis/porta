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
        /** @var (\Closure(string): void)|null called with a command's id before it runs */
        private ?\Closure $beforeRun = null,
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
            $id = $command['id'];
            $beforeRun = $this->beforeRun;
            $hint = null === $this->shortcutOf ? '' : ($this->shortcutOf)($command['id']);
            $scored[] = [
                $score,
                new SearchHit(
                    SearchCategory::Actions,
                    $command['label'],
                    $hint,
                    static function () use ($run, $id, $beforeRun): void {
                        if (null !== $beforeRun) {
                            $beforeRun($id);
                        }
                        $run();
                    },
                    enabled: $command['enabled'],
                ),
            ];
        }
        // What can run first, then by score: a command greyed for want of its
        // context (Step Over with no debug session) is listed, but below the
        // ones that answer the query now.
        \usort($scored, static fn (array $a, array $b): int => [$b[1]->enabled, $b[0]] <=> [$a[1]->enabled, $a[0]]);

        $emit(\array_map(static fn (array $entry): SearchHit => $entry[1], $scored));
    }

    #[\Override]
    public function cancel(): void {}
}
