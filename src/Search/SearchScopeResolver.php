<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\BufferManager;
use Porta\Support\ProjectPaths;
use Porta\Ui\ScopeKind;
use Porta\Widget\CodeEditorWidget;

/**
 * Resolves a search scope kind to the grep path(s) it means right now,
 * against the current editor file and the open buffers.
 */
final class SearchScopeResolver
{
    public function __construct(
        private readonly BufferManager $buffers,
        private readonly CodeEditorWidget $editor,
        private readonly string $rootDir,
    ) {}

    /** @return list<string> */
    public function resolve(ScopeKind $kind): array
    {
        if (ScopeKind::Project === $kind) {
            return ['.'];
        }
        if (ScopeKind::OpenFiles === $kind) {
            $paths = [];
            foreach ($this->buffers->all() as $buffer) {
                $relative = ProjectPaths::relative($this->rootDir, $buffer->path);
                if ('' !== $relative && !str_starts_with($relative, '/')) {
                    $paths[] = $relative;
                }
            }

            return $paths; // empty (no open files) → matches nothing
        }
        $file = $this->editor->getFilePath();
        if (null === $file) {
            return ['.']; // no file open → fall back to the whole project
        }
        if (ScopeKind::Module === $kind) {
            return $this->moduleScope($file);
        }
        $target = ScopeKind::CurrentFile === $kind ? $file : \dirname($file);
        $relative = ProjectPaths::relative($this->rootDir, $target);

        return '' === $relative || str_starts_with($relative, '/') ? ['.'] : [$relative];
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
        $dir = \dirname(ProjectPaths::relative($this->rootDir, $file));
        while ('.' !== $dir && '' !== $dir && !str_starts_with($dir, '/')) {
            if (is_file($this->rootDir.'/'.$dir.'/composer.json')) {
                return [$dir];
            }
            $dir = \dirname($dir);
        }

        return ['.']; // no package root below the project → the whole project
    }
}
