<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * grep's output lines read into hits, for one search: `path:line:text`, or
 * `path\0line:text` from a grep that ended the name with a NUL. What it found
 * to be a file here is remembered for the search: a line read without the
 * NUL is checked per reading, and each line is read twice (counted, then
 * parsed).
 */
final class GrepHits
{
    /** The longest path a file system takes (Linux's PATH_MAX; macOS's is 1024). */
    private const int PATH_MAX = 4096;

    /** @var array<string, bool> the search's answers to "is this a file here?" */
    private array $files = [];

    public function __construct(
        private readonly string $rootDir,
    ) {}

    /**
     * One line of grep's output read: the path as the project names it —
     * `vendor/./a.php`, from a scope handed over as `vendor/.`, is
     * `vendor/a.php` — or null for a line that is no hit.
     *
     * @return array{path: ?string, line: int, text: string, line_text: string} `text` trimmed, `line_text` as the file holds it
     */
    public function hit(string $line): array
    {
        [$path, $number, $text] = \str_contains($line, "\0") ? self::named($line) : $this->guessed($line);
        if (null === $path) {
            return ['path' => null, 'line' => 0, 'text' => '', 'line_text' => ''];
        }

        return ['path' => self::clean($path), 'line' => (int) $number - 1, 'text' => \trim($text), 'line_text' => $text];
    }

    /**
     * `path\0line:text`, from a grep that ended the name with a NUL: exact.
     *
     * @return array{?string, string, string}
     */
    private static function named(string $line): array
    {
        [$path, $rest] = \explode("\0", $line, limit: 2);
        $m = [];

        return 1 === \preg_match('/^(\d+):(.*)$/', $rest, $m) ? [$path, $m[1], $m[2]] : [null, '', ''];
    }

    /**
     * `path:line:text`, from a grep that could not end the name: a name may
     * hold `:12:` itself, overlapping its own `:N:` too (`meeting-10:30`), so
     * every `:N:` is a place the name may end. The first reading that names a
     * file here is the hit — a remote project's placeholder counts — and the
     * first reading when none does. Two that both name a file cannot be told
     * apart without the NUL; the shorter wins.
     *
     * @return array{?string, string, string}
     */
    private function guessed(string $line): array
    {
        $first = null;
        $pair = [];
        // No path is longer than PATH_MAX: past it, a `:N:` is the text's own.
        // Unbounded, a 166 KB log line of timestamps held 871 MB of readings.
        for ($at = \strpos($line, needle: ':'); false !== $at && $at <= self::PATH_MAX; $at = \strpos($line, needle: ':', offset: $at + 1)) {
            if (0 === $at || 1 !== \preg_match('/\G:(\d+):/', $line, $pair, offset: $at)) {
                continue;
            }
            $reading = [\substr($line, offset: 0, length: $at), $pair[1], \substr($line, $at + \strlen($pair[0]))];
            $first ??= $reading;
            if ($this->isFile($reading[0])) {
                return $reading;
            }
        }

        return $first ?? [null, '', ''];
    }

    /** A path as grep printed it, as the project names it: no `./` before it, no `/./` in it. */
    private static function clean(string $path): string
    {
        return \str_replace('/./', replace: '/', subject: \str_starts_with($path, './') ? \substr($path, offset: 2) : $path);
    }

    /** Whether $path, as grep printed it, is a file of the project here — a remote one's placeholder too. Asked once a search. */
    private function isFile(string $path): bool
    {
        $clean = self::clean($path);

        return $this->files[$clean] ??= \is_file($this->rootDir.'/'.$clean);
    }
}
