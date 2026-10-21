<?php

declare(strict_types=1);

namespace Porta\Console;

/**
 * `porta diff A B` and `porta merge L R B M`: the words for the two tool
 * modes, as git's own `difftool` and `mergetool` are words, spelled to the
 * `--diff` / `--merge` options {@see PortaCommand} already takes — so a
 * comparison typed by hand and one git launches run the same path.
 *
 * Only the first argument is read, and only as exactly the word: a file or a
 * folder called `diff` stays reachable as `porta ./diff`.
 */
final class ToolAliases
{
    private const array WORDS = ['diff' => '--diff', 'merge' => '--merge'];

    /**
     * $argv with a leading tool word turned into its option, or unchanged.
     *
     * @param list<string> $argv the process's argv, the script first
     *
     * @return list<string>
     */
    public static function expand(array $argv): array
    {
        $option = self::WORDS[$argv[1] ?? ''] ?? null;

        return null === $option ? $argv : [$argv[0], $option, ...\array_slice($argv, offset: 2)];
    }
}
