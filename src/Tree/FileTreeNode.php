<?php

declare(strict_types=1);

namespace Porta\Tree;

/**
 * One visible row in the file tree: the entry's absolute path, its display name,
 * whether it is a directory, and its indentation depth. Produced by
 * {@see \Porta\Widget\FileTreeWidget} and consumed by {@see TreeOperations} and
 * the context-menu opener. Replaces the former
 * array{path, name, isDir, depth} shape.
 */
final readonly class FileTreeNode
{
    public function __construct(
        public string $path,
        public string $name,
        public bool $isDir,
        public int $depth,
    ) {}

    /**
     * The folder this row means as a destination: itself, or a file's parent.
     * PhpStorm's rule, and the one that makes a tree of files a target at all —
     * a drag, a paste and a drop from outside all aim with it.
     */
    public function folder(): string
    {
        return $this->isDir ? $this->path : \dirname($this->path);
    }
}
