<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\ActiveFileProvider;
use Porta\Editor\BufferManager;
use Porta\Support\ProjectPaths;
use Porta\Ui\ScopeKind;

/**
 * Resolves a search scope kind to the grep path(s) it means right now,
 * against the current editor file and the open buffers.
 */
final readonly class SearchScopeResolver
{
    public function __construct(
        private BufferManager $buffers,
        private ActiveFileProvider $editor,
        private ProjectPaths $paths,
    ) {}

    /** @return list<string> */
    public function resolve(ScopeKind $kind): array
    {
        return match ($kind) {
            ScopeKind::Project => ['.'],
            ScopeKind::OpenFiles => $this->openFiles(), // empty (no open files) → matches nothing
            default => $this->editorScope($kind),
        };
    }

    /** @return list<string> every open buffer's project-relative path */
    private function openFiles(): array
    {
        $paths = [];
        foreach ($this->buffers->all() as $buffer) {
            $relative = $this->paths->relative($buffer->path);
            if ('' !== $relative && !\str_starts_with($relative, '/')) {
                $paths[] = $relative;
            }
        }

        return $paths;
    }

    /**
     * A scope relative to the editor's file: the file itself, its directory,
     * or its module.
     *
     * @return list<string>
     */
    private function editorScope(ScopeKind $kind): array
    {
        $file = $this->editor->getFilePath();
        $relative = null === $file || ScopeKind::Module === $kind ? '' : $this->paths->relative(ScopeKind::CurrentFile === $kind ? $file : \dirname($file));

        return match (true) {
            null === $file => ['.'], // no file open → fall back to the whole project
            ScopeKind::Module === $kind => $this->moduleScope($file),
            '' === $relative || \str_starts_with($relative, '/') => ['.'],
            default => [$relative],
        };
    }

    /**
     * The scope for the "Module" kind: the nearest ancestor directory of $file
     * (below the project root) that holds a `composer.json`, or the whole
     * project when none is found.
     *
     * @return list<string>
     */
    private function moduleScope(string $file): array
    {
        // Climb the file's root-relative directory looking for a composer.json;
        // the loop stops at the project root ('.'), a blank, or an out-of-tree path.
        $dir = \dirname($this->paths->relative($file));
        while ('.' !== $dir && '' !== $dir && !\str_starts_with($dir, '/')) {
            if (\is_file($this->paths->absolute($dir.'/composer.json'))) {
                return [$dir];
            }
            $dir = \dirname($dir);
        }

        return ['.']; // no package root below the project → the whole project
    }
}
