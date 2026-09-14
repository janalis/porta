<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * PhpStorm-style class ↔ test navigation (Ctrl+Shift+T): derives the counterpart
 * file name (`Foo.php` ↔ `FooTest.php`, extension-agnostic) and finds it anywhere
 * in the project via the shared {@see FileIndex}, skipping vendored code.
 */
final readonly class TestNavigator
{
    public function __construct(
        private FileIndex $files,
    ) {}

    /** Whether the file names a test: its basename (minus extension) ends with `Test`. */
    public function isTest(string $path): bool
    {
        $name = \pathinfo($path, \PATHINFO_FILENAME);

        return 'Test' !== $name && \str_ends_with($name, 'Test');
    }

    /**
     * Project-relative paths of the file's test candidates — or, from a test,
     * its subject candidates — sorted by path.
     *
     * @return list<string>
     */
    public function locate(string $absolutePath): array
    {
        $name = \pathinfo($absolutePath, \PATHINFO_FILENAME);
        $extension = \pathinfo($absolutePath, \PATHINFO_EXTENSION);
        $target = ($this->isTest($absolutePath) ? \substr($name, offset: 0, length: -4) : $name.'Test').('' === $extension ? '' : '.'.$extension);

        $this->files->refresh();
        $candidates = [];
        foreach ($this->files->entries() as $entry) {
            if (\basename($entry['path']) !== $target || \str_starts_with($entry['path'], 'vendor/')) {
                continue;
            }

            $candidates[] = $entry['path'];
        }

        return $candidates;
    }
}
