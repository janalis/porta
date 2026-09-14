<?php

declare(strict_types=1);

namespace Porta\GraphViewer;

use Symfony\Component\Finder\Finder;

/**
 * The built-in architecture source for PHP projects: walks the composer PSR-4
 * roots, extracts each file's namespace and imports ({@see UseScanner}),
 * buckets both at "prefix + first sub-segment" granularity (the arch-gate
 * tool's granularity), and emits the dependency graph as DOT with one cluster
 * per composer root. Nodes and edges are sorted so an unchanged project
 * produces byte-identical output (stable hash ⇒ no spurious re-layout).
 */
final class NamespaceGraphScanner
{
    private const array SKIP_DIRS = ['vendor', 'node_modules'];

    public function __construct(
        private readonly string $rootDir,
    ) {}

    public function scan(): string
    {
        $roots = ComposerRoots::roots($this->rootDir);
        $nodesByCluster = [];
        $edgeSet = [];
        foreach ($roots as $prefix => $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                // @mago-expect lint:no-error-control-operator -- an unreadable file (permissions, races with deletion) warns; the false return is handled.
                $source = @\file_get_contents($file);
                if (false === $source) {
                    continue;
                }
                $scanned = UseScanner::scan($source);
                $from = self::bucket($scanned['namespace'], $roots);
                if (null === $from) {
                    continue;
                }
                $nodesByCluster[$prefix][$from[0]] = $from[1];
                foreach ($scanned['uses'] as $use) {
                    $to = self::bucket($use, $roots);
                    if (null === $to || $to[0] === $from[0]) {
                        continue;
                    }
                    $edgeSet[$from[0]."\0".$to[0]] = [$from[0], $to[0]];
                }
            }
        }

        \ksort($nodesByCluster);
        foreach ($nodesByCluster as &$nodes) {
            \ksort($nodes);
        }
        unset($nodes);
        \ksort($edgeSet);

        return DotWriter::write($nodesByCluster, \array_values($edgeSet));
    }

    /**
     * The graph granularity: a namespace (or imported FQCN) maps to its
     * "prefix + first sub-segment" bucket, e.g. `Porta\Editor\Document` under
     * prefix `Porta` → node id "Porta\Editor" labeled "Editor". A name directly
     * in the prefix maps to the prefix itself.
     *
     * @param array<string, string> $roots
     *
     * @return ?array{string, string} [node id, node label], null when no prefix matches
     */
    private static function bucket(?string $name, array $roots): ?array
    {
        if (null === $name || '' === $name) {
            return null;
        }
        foreach (\array_keys($roots) as $prefix) {
            if ($name !== $prefix && !\str_starts_with($name, $prefix.'\\')) {
                continue;
            }
            $rest = $name === $prefix ? '' : \substr($name, \strlen($prefix) + 1);
            $segment = '' !== $rest ? \explode('\\', $rest)[0] : '';

            return (
                '' !== $segment
                    ? [$prefix.'\\'.$segment, $segment]
                    : [$prefix, $prefix]
            );
        }

        return null;
    }

    /** @return list<string> sorted */
    private function phpFiles(string $dir): array
    {
        $files = [];
        // Finder's dot-file default covers the hidden-name skip; SKIP_DIRS is
        // anchored per segment so a nested vendor/ is skipped at any depth.
        $finder = new Finder()
            ->files()
            ->in($dir)
            ->name('*.php')
            ->notPath('~(^|/)(?:'.\implode('|', self::SKIP_DIRS).')/~');
        foreach ($finder as $file) {
            $files[] = $file->getPathname();
        }
        \sort($files);

        return $files;
    }
}
