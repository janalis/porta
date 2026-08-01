<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;

/**
 * Every documentation page must be reachable from the index above it.
 *
 * Not a tidiness rule: `docs/` is what the in-IDE F1 browser renders, and
 * `docs/README.md` is its hard-coded home page. A page nothing links to exists
 * in the repository and nowhere in the product — which is what had happened to
 * `troubleshooting.md`, listed in neither table for as long as it has existed.
 *
 * The walk is RECURSIVE because `docs/internals/` exists. A subdirectory is
 * reachable only through its own `README.md` (which is also what makes the
 * browser's folder crumb clickable), so the rule composes: a page is linked
 * from its own folder's index, and that index is linked from its parent's.
 * Checking only the root index would leave every page under a subdirectory
 * ungated — the precise gap this test exists to close.
 */
final class DocsIndexTest extends TestCase
{
    public function testEveryPageIsLinkedFromTheIndexAboveIt(): void
    {
        $docs = \dirname(__DIR__, levels: 3).'/docs';

        $unlinked = [];
        foreach (self::pages($docs) as $page) {
            $folder = \dirname($page);
            // A folder's own index is linked from its PARENT's, by the relative
            // path the browser resolves: `(internals/README.md)`.
            [$index, $target] = 'README.md' === basename($page)
                ? [\dirname($folder).'/README.md', basename($folder).'/README.md']
                : [$folder.'/README.md', basename($page)];

            if ($folder === $docs && 'README.md' === basename($page)) {
                continue; // the root index is the browser's home; nothing above it
            }
            if (!is_file($index) || !str_contains((string) file_get_contents($index), '('.$target.')')) {
                $unlinked[] = substr($page, \strlen($docs) + 1);
            }
        }

        sort($unlinked);
        $this->assertSame([], $unlinked, 'unreachable from the F1 browser — add a row to the README.md beside it');
    }

    /** @return list<string> every .md under docs/, at any depth */
    private static function pages(string $docs): array
    {
        $pages = [];
        $walk = static function (string $dir) use (&$walk, &$pages): void {
            foreach ((array) scandir($dir) as $entry) {
                if (!\is_string($entry) || str_starts_with($entry, '.')) {
                    continue;
                }
                $path = $dir.'/'.$entry;
                if (is_dir($path)) {
                    $walk($path);

                    continue;
                }
                if (str_ends_with($entry, '.md')) {
                    $pages[] = $path;
                }
            }
        };
        $walk($docs);

        return $pages;
    }
}
