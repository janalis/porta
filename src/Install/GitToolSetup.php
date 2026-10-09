<?php

declare(strict_types=1);

namespace Porta\Install;

/**
 * Porta as git's difftool and mergetool, in the user's GLOBAL git config (so
 * `GIT_CONFIG_GLOBAL` redirects it, which is how the tests keep their hands off
 * the real one): written by `porta --git-setup` and by the Command line page of
 * the settings, which also reads it back and removes it.
 *
 * The command git runs names the binary by its ABSOLUTE path — the phar, or the
 * script that is running — so the setup works whatever git's PATH is, and a
 * checkout moved later says so plainly instead of launching something else.
 * That is also what {@see status()} tells a stale setup by: the keys are there,
 * but they name another binary.
 *
 * `mergetool.porta.trustExitCode` is what lets the IDE's exit code, not the
 * file's mtime, tell git whether the merge was resolved.
 */
final readonly class GitToolSetup
{
    /** What {@see status()} reads: Porta's two tool sections, and the two keys that pick a tool. */
    private const string KEYS = '^(difftool|mergetool)\.porta\.|^(diff|merge)\.tool$';

    /**
     * @param \Closure(list<string>): array{int, string} $git runs `git <args>`: its exit code and stdout
     *                                                          (127 when there is no git at all)
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

    /** Write every entry — a stale setup is updated by writing it again; the key that could not be written, or null. */
    public function apply(): ?string
    {
        foreach ($this->entries() as [$key, $value]) {
            if (0 !== ($this->git)(['config', '--global', $key, $value])[0]) {
                return $key;
            }
        }

        return null;
    }

    public function status(): GitToolStatus
    {
        $current = $this->current();
        if (null === $current) {
            return GitToolStatus::Unavailable;
        }

        return match (true) {
            [] === $this->owned($current) => GitToolStatus::Absent,
            // git reads keys case-insensitively and lists them in lower case.
            \array_any($this->entries(), static fn (array $entry): bool => ($current[\strtolower($entry[0])] ?? null) !== $entry[1]) => GitToolStatus::Stale,
            default => GitToolStatus::Current,
        };
    }

    /**
     * Take Porta out of the global config: its two tool sections, and
     * `diff.tool` / `merge.tool` only while they still say `porta` — a tool the
     * user picked since is theirs. The key that could not be removed, or null.
     */
    public function remove(): ?string
    {
        $removals = [];
        foreach ($this->owned($this->current() ?? []) as $key) {
            // A section goes whole (`mergetool.porta` holds two keys); a picker key alone.
            $removals[] = \str_ends_with($key, '.tool') ? ['--unset', $key] : ['--remove-section', \substr($key, offset: 0, length: (int) \strrpos($key, needle: '.'))];
        }
        foreach (\array_unique($removals, \SORT_REGULAR) as [$how, $name]) {
            if (0 !== ($this->git)(['config', '--global', $how, $name])[0]) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The keys Porta wrote that are still there: its sections' keys, and a
     * tool picker that still names it.
     *
     * @param array<string, string> $current
     *
     * @return list<string>
     */
    private function owned(array $current): array
    {
        return \array_keys(\array_filter(
            $current,
            static fn (string $value, string $key): bool => !\str_ends_with($key, '.tool') || 'porta' === $value,
            \ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** @return array<string, string>|null the keys {@see KEYS} matches, lower-cased key => value; null without git */
    private function current(): ?array
    {
        [$code, $out] = ($this->git)(['config', '--global', '--get-regexp', self::KEYS]);
        // 1 is git's "nothing matched" — a config with no such key, or no file at all.
        if (0 !== $code && 1 !== $code) {
            return null;
        }
        $current = [];
        foreach (\explode("\n", \trim($out)) as $line) {
            $pair = \explode(' ', $line, limit: 2);
            if (2 === \count($pair)) {
                $current[$pair[0]] = $pair[1];
            }
        }

        return $current;
    }
}
