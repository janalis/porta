<?php

declare(strict_types=1);

namespace Porta\Config\EditorConfig;

use Porta\Support\Files;

/**
 * Resolves the effective EditorConfig for an absolute file path by walking up
 * the directory tree — reading every `.editorconfig` from the file's directory
 * upward and stopping once one declares `root = true` (or at the filesystem
 * root). Properties from `.editorconfig` files nearer the file, and from later
 * sections within a file, win. Section globs are matched against the file path
 * relative to each `.editorconfig`'s own directory.
 */
final class EditorConfigResolver
{
    public function resolve(string $absoluteFilePath): ResolvedEditorConfig
    {
        $real = \realpath($absoluteFilePath);
        $file = false !== $real ? $real : $absoluteFilePath;

        // Collect (dir, parsed) walking up, nearest first, until a root file.
        $collected = [];
        $dir = \dirname($file);
        while (true) {
            $path = ('/' === $dir ? '' : $dir).'/.editorconfig';
            $contents = Files::read($path);
            if ('' !== $contents) {
                $parsed = EditorConfigParser::parse($contents);
                $collected[] = ['dir' => $dir, 'parsed' => $parsed];
                if ($parsed['root']) {
                    break;
                }
            }
            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        if ([] === $collected) {
            return ResolvedEditorConfig::none();
        }

        // Merge farthest→nearest so nearer files win; later sections win too.
        $props = [];
        foreach (\array_reverse($collected) as $entry) {
            $relative = self::relativePath($entry['dir'], $file);
            foreach ($entry['parsed']['sections'] as $section) {
                if (!EditorConfigGlob::matches($section['glob'], $relative)) {
                    continue;
                }

                foreach ($section['props'] as $key => $value) {
                    $props[$key] = $value;
                }
            }
        }

        // `unset` explicitly clears an inherited property (spec).
        $props = \array_filter($props, static fn (string $value): bool => 'unset' !== \strtolower($value));

        return ResolvedEditorConfig::fromProps($props);
    }

    private static function relativePath(string $dir, string $file): string
    {
        $prefix = '/' === $dir ? '/' : $dir.'/';

        return \str_starts_with($file, $prefix) ? \substr($file, \strlen($prefix)) : $file;
    }
}
