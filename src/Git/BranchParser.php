<?php

declare(strict_types=1);

namespace Porta\Git;

/**
 * Parses `git branch --format='%(refname:short)%09%(HEAD)'` output: one
 * branch per line, a tab, then `*` for the checked-out branch.
 */
final class BranchParser
{
    /** @return list<array{name: string, current: bool}> */
    public static function parse(string $output): array
    {
        $branches = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line, "\r");
            if ('' === trim($line)) {
                continue;
            }
            [$name, $head] = array_pad(explode("\t", $line, 2), 2, '');
            $name = trim($name);
            if ('' === $name) {
                continue;
            }
            $branches[] = ['name' => $name, 'current' => '*' === trim($head)];
        }

        return $branches;
    }
}
