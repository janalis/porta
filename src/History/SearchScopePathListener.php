<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\Support\ProjectPaths;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Saved Find-in-Files scopes follow a renamed folder and drop a deleted one.
 *
 * The odd one out among the path-keyed listeners: its store speaks
 * project-RELATIVE paths, because that is what a scope shows and what the JSON
 * holds, while the facts carry absolute canonical ones. The translation lives
 * here rather than in the store — the store's vocabulary is the one the user
 * reads.
 *
 * A scope pointing at a folder that has been renamed away is worse than a stale
 * bookmark: it does not fail, it silently searches nothing and reports no
 * matches, which reads exactly like a query with no results.
 */
final readonly class SearchScopePathListener
{
    public function __construct(
        private SearchScopeStore $store,
        private ProjectPaths $paths,
    ) {}

    #[AsEventListener]
    public function onFileRenamed(FileRenamed $event): void
    {
        $from = $this->relative($event->from);
        $to = $this->relative($event->to);
        if (null === $from || null === $to) {
            return;
        }
        $this->store->rename($from, $to);
    }

    #[AsEventListener]
    public function onFileDeleted(FileDeleted $event): void
    {
        $relative = $this->relative($event->path);
        if (null === $relative) {
            return;
        }
        $this->store->forget($relative);
    }

    /**
     * The project-relative form, or null when the path is outside the project:
     * {@see ProjectPaths::relative()} answers an ABSOLUTE path for one it
     * cannot make relative, and nothing outside the root can be a scope in the
     * first place.
     *
     * The root ITSELF relativises to `''`, which is not guarded here because it
     * needs no guard: `Path::isBasePath('', …)` is false, so an empty root
     * matches no scope path and both store methods no-op on it.
     */
    private function relative(string $path): ?string
    {
        $relative = $this->paths->relative($path);

        return \str_starts_with($relative, '/') ? null : $relative;
    }
}
