<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Config\PortaHome;
use Porta\Support\ControlChars;

/**
 * Folder completion for a typed path, as a shell completes one: the folders
 * in what the path names so far that start with its last segment.
 *
 * Read from disk on every call: a folder made while the picker is up is
 * offered on the next keystroke.
 */
final readonly class FolderCompletion
{
    /** Whether $typed is a path rather than a name to look for: what a shell would read as one. */
    public static function isPath(string $typed): bool
    {
        return \str_starts_with($typed, '~') || \str_starts_with($typed, '/') || \str_starts_with($typed, '.');
    }

    /**
     * The folders $typed could go on to name, natural order. A hidden folder
     * only when the segment asks for one, as `ls` hides them.
     *
     * @return list<array{name: string, path: string, isProject: bool}> `path` as the field spells it, `/` ended
     */
    public static function candidates(string $typed): array
    {
        [$folder, $segment] = self::split($typed);
        // @mago-expect lint:no-error-control-operator -- a folder that is missing or cannot be listed warns; the false return is what is answered below
        $names = @\scandir(PortaHome::expand('' === $folder ? './' : $folder));
        if (false === $names) {
            return [];
        }

        $base = PortaHome::expand($folder);
        $matches = \array_filter(
            $names,
            static fn (string $name): bool => (
                '.' !== $name
                && '..' !== $name
                && (\str_starts_with($segment, '.') || !\str_starts_with($name, '.'))
                && 0 === \strncasecmp($name, $segment, \strlen($segment))
                && \is_dir($base.$name)
            ),
        );
        \usort($matches, \strnatcasecmp(...));

        return \array_map(
            static fn (string $name): array => ['name' => ControlChars::line($name), 'path' => $folder.$name.'/', 'isProject' => \is_file($base.$name.'/composer.json')],
            $matches,
        );
    }

    /**
     * What Tab makes of $typed: the folder chosen in the list, or else as much
     * as every candidate shares; $typed itself when nothing completes it.
     */
    public static function complete(string $typed, ?string $selected = null): string
    {
        $candidates = null === $selected ? self::candidates($typed) : [];

        return match (true) {
            null !== $selected => $selected,
            [] === $candidates => $typed,
            1 === \count($candidates) => $candidates[0]['path'],
            default => self::shared($typed, $candidates),
        };
    }

    /**
     * As much as every candidate's name shares, character by character and
     * any case, spelled as the first; never shorter than $typed: completion
     * only ever adds. The names as the disk has them, not as they are shown.
     *
     * @param non-empty-list<array{name: string, path: string, isProject: bool}> $candidates
     */
    private static function shared(string $typed, array $candidates): string
    {
        [$folder] = self::split($typed);
        $names = \array_map(static fn (array $candidate): string => \substr($candidate['path'], \strlen($folder), length: -1), $candidates);
        $shared = \mb_str_split($names[0]);
        foreach ($names as $name) {
            $characters = \mb_str_split($name);
            $length = 0;
            while ($length < \count($shared) && $length < \count($characters) && \mb_strtolower($shared[$length]) === \mb_strtolower($characters[$length])) {
                ++$length;
            }
            $shared = \array_slice($shared, offset: 0, length: $length);
        }
        $completed = $folder.\implode('', $shared);

        return \strlen($completed) > \strlen($typed) ? $completed : $typed;
    }

    /**
     * $typed as the folder it names so far (`/` ended, as typed) and the
     * segment being typed. A bare `~` is the home folder, as a shell has it.
     *
     * @return array{string, string}
     */
    private static function split(string $typed): array
    {
        if ('~' === $typed) {
            return ['~/', ''];
        }
        $slash = \strrpos($typed, needle: '/');

        return false === $slash ? ['', $typed] : [\substr($typed, offset: 0, length: $slash + 1), \substr($typed, offset: $slash + 1)];
    }
}
