<?php

declare(strict_types=1);

namespace Porta\Console;

/**
 * `porta --git-setup`: make Porta git's difftool and mergetool, in the user's
 * GLOBAL git config (so `GIT_CONFIG_GLOBAL` redirects it, which is how the
 * tests keep their hands off the real one).
 *
 * The command git runs names the binary by its ABSOLUTE path — the phar, or the
 * script that is running — so the setup works whatever git's PATH is, and a
 * checkout moved later says so plainly instead of launching something else.
 * `mergetool.porta.trustExitCode` is what lets the IDE's exit code, not the
 * file's mtime, tell git whether the merge was resolved.
 */
final readonly class GitToolSetup
{
    /**
     * @param \Closure(list<string>): bool $git runs `git <args>`; false when it failed
     */
    public function __construct(
        private string $porta,
        private \Closure $git,
    ) {}

    /** @return list<array{string, string}> the global config entries, key => value, in the order they are written */
    public function entries(): array
    {
        $porta = \escapeshellarg($this->porta);

        return [
            ['difftool.porta.cmd', $porta.' --diff "$LOCAL" "$REMOTE"'],
            ['mergetool.porta.cmd', $porta.' --merge "$LOCAL" "$REMOTE" "$BASE" "$MERGED"'],
            ['mergetool.porta.trustExitCode', 'true'],
            ['diff.tool', 'porta'],
            ['merge.tool', 'porta'],
        ];
    }

    /** @return list<string> the shell commands {@see apply()} amounts to — what `--dry-run` prints */
    public function commands(): array
    {
        return \array_map(
            static fn (array $entry): string => 'git config --global '.$entry[0].' '.\escapeshellarg($entry[1]),
            $this->entries(),
        );
    }

    /** Write every entry; the key that could not be written, or null when all were. */
    public function apply(): ?string
    {
        foreach ($this->entries() as [$key, $value]) {
            if (!($this->git)(['config', '--global', $key, $value])) {
                return $key;
            }
        }

        return null;
    }
}
