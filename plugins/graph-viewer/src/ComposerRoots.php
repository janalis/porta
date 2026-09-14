<?php

declare(strict_types=1);

namespace Porta\GraphViewer;

use Porta\Support\JsonFile;

/**
 * The project's PSR-4 roots from composer.json (`autoload` + `autoload-dev`),
 * prefix ⇒ existing absolute directory — what the architecture scanner walks
 * and resolves import names against.
 */
final class ComposerRoots
{
    /** @return array<string, string> */
    public static function roots(string $rootDir): array
    {
        $composer = JsonFile::read($rootDir.'/composer.json') ?? [];
        $roots = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            /** @var mixed $psr4 */
            $psr4 = $composer[$section]['psr-4'] ?? [];
            if (!\is_array($psr4)) {
                continue;
            }
            /** @var mixed $dirs */
            foreach ($psr4 as $prefix => $dirs) {
                if (!\is_string($prefix)) {
                    continue;
                }
                /** @var mixed $dir */
                foreach (\is_array($dirs) ? $dirs : [$dirs] as $dir) {
                    $path = \is_string($dir) ? $rootDir.'/'.\rtrim($dir, characters: '/') : '';
                    if ('' !== $path && \is_dir($path)) {
                        $roots[\trim($prefix, characters: '\\')] = $path;
                        break; // first existing dir wins for a multi-dir prefix
                    }
                }
            }
        }

        return $roots;
    }
}
