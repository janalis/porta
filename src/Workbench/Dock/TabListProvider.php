<?php

declare(strict_types=1);

namespace Porta\Workbench\Dock;

use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Ui\Glyphs;

/** The tab strip's rows, pulled at render time from the buffer list. */
final readonly class TabListProvider
{
    public function __construct(
        private BufferManager $buffers,
        private Translator $translator,
    ) {}

    /** @return list<array{name: string, dirty: bool, active: bool}> */
    public function tabs(): array
    {
        $buffers = $this->buffers->all();
        $titles = $this->titles($buffers);

        return \array_map(
            fn (Buffer $b, string $title): array => [
                // The pin rides the NAME rather than becoming a fourth field: the
                // strip's width arithmetic already measures the label, and adding a
                // column to it is the one change that needs no new maths.
                'name' => ($b->pinned ? Glyphs::PINNED.' ' : '').$title,
                'dirty' => $b->isDirty(),
                'active' => $b === $this->buffers->active(),
            ],
            $buffers,
            $titles,
        );
    }

    /**
     * One title per buffer, with same-named files told apart.
     *
     * `basename()` alone is how two open tabs both read `plugin.php` — and in
     * a project with per-directory conventions (a plugin.php per plugin, a
     * composer.json per package, an index.php per entry point) that is the
     * normal case, not an edge one. Closing or editing the wrong one of two
     * identical tabs is the whole cost.
     *
     * Only path-derived titles take part. The help pages deliberately SHARE
     * one "Documentation" tab and a titled buffer shows the title it was
     * given, so neither is a collision to resolve.
     *
     * @param list<Buffer> $buffers
     *
     * @return list<string>
     */
    private function titles(array $buffers): array
    {
        $titles = [];
        /** @var array<string, list<int>> $collisions basename => the indexes claiming it */
        $collisions = [];
        foreach ($buffers as $i => $buffer) {
            $titles[$i] = $this->title($buffer);
            if ($this->isPathDerived($buffer)) {
                $collisions[$titles[$i]][] = $i;
            }
        }
        foreach ($collisions as $indexes) {
            if (1 >= \count($indexes)) {
                continue;
            }

            foreach ($this->distinguish($buffers, $titles, $indexes) as $i => $title) {
                $titles[$i] = $title;
            }
        }

        return \array_values($titles);
    }

    /**
     * Qualify each of $indexes with as much of its parent path as it takes.
     *
     * The qualifier goes AFTER the name — `plugin.php (symfony-lsp)`, the
     * shape PhpStorm and VS Code both use — and that ordering is load bearing
     * here rather than cosmetic: the side strip gives a name 19 columns, so a
     * leading `symfony-lsp/plugin.php` would truncate away the only part that
     * says what the file IS, while this truncates the tail and still reads
     * `plugin.php (symfo…`.
     *
     * Walks up a directory at a time, because one level is not always enough:
     * two `GitSidebar.php` under `<plugin>/src/View/` agree on `View` and on
     * `src/View`, and only separate at the plugin name.
     *
     * @param list<Buffer>       $buffers
     * @param array<int, string> $titles
     * @param list<int>          $indexes the buffers sharing one basename
     *
     * @return array<int, string> the qualified titles, keyed by buffer index
     */
    private function distinguish(array $buffers, array $titles, array $indexes): array
    {
        $segments = [];
        $qualified = [];
        foreach ($indexes as $i) {
            $parts = \explode(\DIRECTORY_SEPARATOR, \trim($buffers[$i]->path, characters: \DIRECTORY_SEPARATOR));
            // Drop the basename itself: what is left is the parent path, and
            // its tail is what tells these buffers apart.
            \array_pop($parts);
            $segments[$i] = $parts;
            // Seeded with the bare names, so paths with no parent at all to
            // offer answer with those rather than with nothing.
            $qualified[$i] = $titles[$i];
        }
        $deepest = \max(\array_map(\count(...), $segments));
        for ($depth = 1; $depth <= $deepest; ++$depth) {
            foreach ($indexes as $i) {
                $tail = \array_slice($segments[$i], -$depth);
                $qualified[$i] = [] === $tail ? $titles[$i] : $titles[$i].' ('.\implode(\DIRECTORY_SEPARATOR, $tail).')';
            }
            // Distinct, or as distinct as these paths can be made: either way
            // this is the shortest qualifier that says everything it can.
            if (\count(\array_unique($qualified)) === \count($qualified)) {
                break;
            }
        }

        return $qualified;
    }

    /** Whether this buffer's title came from its path, and so can be qualified with more of it. */
    private function isPathDerived(Buffer $b): bool
    {
        return 'help' !== $b->paneId && null === $b->title;
    }

    /** Help pages share one "Documentation" tab name; a titled buffer (the architecture graph) shows its translated title; everything else its basename. */
    private function title(Buffer $b): string
    {
        if ('help' === $b->paneId) {
            return $this->translator->trans('Documentation');
        }

        return null !== $b->title ? $this->translator->trans($b->title) : \basename($b->path);
    }
}
