<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Config\PortaHome;
use Porta\Support\ControlChars;

/**
 * The PHP projects already on this computer, for a first launch that has no
 * recent ones to offer: one level down in the folders people keep code in,
 * every folder holding a `composer.json`.
 *
 * One level, never a walk: the picker draws before anything else happens, and
 * a home full of node_modules would hold that frame for seconds.
 *
 * On Windows PHP runs inside WSL, so this home is WSL's: `C:\Users\…` is not
 * searched, and is reached through the system's folder dialog instead.
 */
final readonly class ProjectDiscovery
{
    /** Where people keep code, by the names the common tools and habits give those folders. */
    public const array FOLDERS = ['Sites', 'Projects', 'projects', 'code', 'Code', 'dev', 'Developer', 'src', 'workspace', 'git', 'www', 'PhpstormProjects'];

    private const int LIMIT = 20;

    /**
     * Newest `composer.json` first: the project touched last is the likeliest.
     *
     * @param list<string> $except roots offered already (the recent list), not offered twice
     *
     * @return list<array{label: string, hint: string, value: string}>
     */
    public function found(array $except = []): array
    {
        $home = PortaHome::base();
        if (null === $home) {
            return [];
        }

        /** @var array<string, int> $touched root => its composer.json's mtime */
        $touched = [];
        // A case-insensitive disk (macOS) answers both `code` and `Code` with
        // one folder: a project is known by its inode, and kept once.
        $seen = [];
        foreach (self::FOLDERS as $folder) {
            foreach (self::children($home.'/'.$folder) as $root) {
                $manifest = $root.'/composer.json';
                $stat = \is_file($manifest) ? \stat($manifest) : false;
                $inode = false === $stat ? '' : $stat['dev'].':'.$stat['ino'];
                if (false === $stat || \array_key_exists($inode, $seen) || \in_array($root, $except, strict: true)) {
                    continue;
                }
                $seen[$inode] = true;
                $touched[$root] = $stat['mtime'];
            }
        }
        \uksort($touched, static fn (string $a, string $b): int => [$touched[$b], $a] <=> [$touched[$a], $b]);

        return \array_map(
            static fn (string $root): array => ['label' => ControlChars::line(\basename($root)), 'hint' => ControlChars::line(PortaHome::tilde($root)), 'value' => $root],
            \array_slice(\array_keys($touched), offset: 0, length: self::LIMIT),
        );
    }

    /**
     * The visible folders in $parent, resolved as the recent list records a
     * root (macOS's /tmp is /private/tmp); none when it is missing or unreadable.
     *
     * @return list<string>
     */
    private static function children(string $parent): array
    {
        // @mago-expect lint:no-error-control-operator -- a missing or unreadable folder warns; the false return is what is answered below
        $names = @\scandir($parent);
        if (false === $names) {
            return [];
        }
        $roots = [];
        foreach ($names as $name) {
            $root = \realpath($parent.'/'.$name);
            if (false !== $root && !\str_starts_with($name, '.') && \is_dir($root)) {
                $roots[] = $root;
            }
        }

        return $roots;
    }
}
