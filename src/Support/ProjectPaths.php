<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Small pure helpers for project-relative paths and language identification,
 * shared by the coordinator and the LSP feature controllers.
 */
final class ProjectPaths
{
    /** Path relative to the project root, or the absolute path when outside it. */
    public static function relative(string $rootDir, string $path): string
    {
        $realPath = realpath($path);
        $real = $realPath ? $realPath : $path;
        $realRoot = realpath($rootDir);
        $root = rtrim($realRoot ? $realRoot : $rootDir, characters: '/');

        return str_starts_with($real, $root.'/') ? substr($real, \strlen($root) + 1) : $real;
    }

    /** LSP languageId for a file, by extension. */
    public static function languageId(string $path): string
    {
        return match (strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            'php', 'phtml' => 'php',
            'js', 'mjs' => 'javascript',
            'ts' => 'typescript',
            'py' => 'python',
            'json' => 'json',
            'css' => 'css',
            'scss' => 'scss',
            'html', 'htm' => 'html',
            'md' => 'markdown',
            'yaml', 'yml' => 'yaml',
            'twig' => 'twig',
            default => 'plaintext',
        };
    }
}
