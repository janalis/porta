<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Config\PortaHome;
use Porta\Container\AsIdeService;
use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Every project root this IDE has opened, most-recent-first, in
 * `<PORTA_HOME|HOME>/.porta/recent-projects.json`.
 *
 * GLOBAL, unlike its sibling {@see RecentFilesStore}, and that is the whole
 * point: a list of projects kept inside one project is a list you can only read
 * from the place you no longer want to be. The path is resolved per call rather
 * than baked in the constructor, so a test pointing `PORTA_HOME` at a temp dir
 * is obeyed by an instance the container built before it
 * ({@see \Porta\Tests\Support\PreservesPortaHome} explains why that matters).
 *
 * An environment with neither variable has nowhere to write: the store is then
 * inert rather than throwing — the switcher shows an empty list and says so,
 * which is a worse experience than remembering, not a broken one.
 */
#[AsIdeService]
final readonly class RecentProjectsStore
{
    /**
     * Shorter than the recent-FILES cap on purpose. Files accumulate by the
     * dozen in a day; a person works on a handful of projects, and a list long
     * enough to need searching defeats a switcher meant to be read at a glance.
     */
    private const int MAX = 16;

    public function __construct(
        private Filesystem $fs = new Filesystem(),
    ) {}

    /** @return list<string> absolute project roots, most-recent-first */
    public function all(): array
    {
        $file = self::file();
        if (null === $file) {
            return [];
        }
        $data = JsonFile::read($file);
        if (null === $data || !\is_array($data['roots'] ?? null)) {
            return [];
        }
        /** @var list<scalar|array<mixed>|null> $entries */
        $entries = $data['roots'];
        $roots = [];
        foreach ($entries as $entry) {
            if (!\is_string($entry) || '' === $entry) {
                continue;
            }

            $roots[] = $entry;
        }

        return \array_slice($roots, offset: 0, length: self::MAX);
    }

    /** Record a root as the most recent (dedup, capped), and persist. */
    public function record(string $root): void
    {
        $file = self::file();
        if (null === $file || '' === $root) {
            return;
        }
        $roots = $this->all();
        if (($roots[0] ?? null) === $root) {
            return; // already at the front: reopening the same project is not a visit
        }

        $roots = \array_values(\array_filter($roots, static fn (string $r): bool => $r !== $root));
        \array_unshift($roots, $root);
        // Best-effort persistence; a recent-projects list is non-critical.
        JsonFile::write($this->fs, $file, ['roots' => \array_slice($roots, offset: 0, length: self::MAX)]);
    }

    /** The store's path, or null when the environment names no home. */
    private static function file(): ?string
    {
        $base = PortaHome::base();

        return null === $base ? null : $base.'/.porta/recent-projects.json';
    }
}
