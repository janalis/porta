<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * Where the documentation the F1 browser renders lives: Porta's own `docs/`,
 * and each bundled plugin's `plugins/<name>/docs/` — a plugin carries its own
 * guide, and Porta's pages link into it. The doc gates walk both through
 * here, so a gate that learns one tree cannot forget the other.
 */
final class DocsTree
{
    /** Porta's own docs root. */
    public static function core(): string
    {
        return self::repo().'/docs';
    }

    /**
     * Every bundled plugin, whether or not it ships docs — the gate that
     * requires them needs the ones that do not.
     *
     * @return array<string, string> plugin name => its docs dir (which may not exist)
     */
    public static function plugins(): array
    {
        $plugins = [];
        $entries = \glob(self::repo().'/plugins/*/plugin.php');
        foreach (false === $entries ? [] : $entries as $entry) {
            $plugins[\basename(\dirname($entry))] = \dirname($entry).'/docs';
        }

        return $plugins;
    }

    /**
     * Every Markdown page in every tree, at any depth.
     *
     * @return list<string>
     */
    public static function pages(): array
    {
        $pages = [];
        foreach ([self::core(), ...\array_values(self::plugins())] as $root) {
            \array_push($pages, ...self::pagesUnder($root));
        }

        return $pages;
    }

    /**
     * The Markdown pages under one tree, at any depth; none when it is absent.
     *
     * @return list<string>
     */
    public static function pagesUnder(string $root): array
    {
        if (!\is_dir($root)) {
            return [];
        }
        $pages = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'md' !== $file->getExtension() || \str_starts_with($file->getFilename(), '.')) {
                continue;
            }
            $pages[] = $file->getPathname();
        }
        \sort($pages);

        return $pages;
    }

    /** The repository root. */
    private static function repo(): string
    {
        return \dirname(__DIR__, levels: 2);
    }
}
