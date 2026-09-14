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
            [$index, $target] = 'README.md' === \basename($page)
                ? [\dirname($folder).'/README.md', \basename($folder).'/README.md']
                : [$folder.'/README.md', \basename($page)];

            if ($folder === $docs && 'README.md' === \basename($page)) {
                continue; // the root index is the browser's home; nothing above it
            }
            if (!\is_file($index) || !\str_contains((string) \file_get_contents($index), '('.$target.')')) {
                $unlinked[] = \substr($page, \strlen($docs) + 1);
            }
        }

        \sort($unlinked);
        $this->assertSame([], $unlinked, 'unreachable from the F1 browser — add a row to the README.md beside it');
    }

    /**
     * And the other direction: every relative link must point at a file that
     * is there.
     *
     * The test above asks whether each page is linked, which cannot see a ROW
     * for a page that no longer exists. Rename or delete one and its index row
     * stays, and the F1 browser then offers a page it cannot open — the same
     * half-a-rule that let `docs/keybindings.md` document three chords the find
     * modal does not bind, found the same day.
     *
     * Anchors are stripped and external schemes skipped: this checks the file
     * exists, not that a heading inside it does.
     */
    public function testEveryRelativeLinkPointsAtAFileThatExists(): void
    {
        $docs = \dirname(__DIR__, levels: 3).'/docs';

        $checked = 0;
        $dead = [];
        foreach (self::pages($docs) as $page) {
            $matches = [];
            \preg_match_all('~\]\(([^)#][^)]*)\)~', (string) \file_get_contents($page), $matches);
            foreach ($matches[1] as $link) {
                if (1 === \preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $link)) {
                    continue; // an external scheme is not ours to resolve
                }
                $target = \explode('#', $link)[0];
                if ('' === $target) {
                    continue;
                }
                ++$checked;
                if (\file_exists(\dirname($page).'/'.$target)) {
                    continue;
                }
                $dead[] = \substr($page, \strlen($docs) + 1).' → '.$link;
            }
        }

        \sort($dead);
        // Asserted first: a regex that stopped matching would find no dead
        // links either, and this gate would go green and stay there.
        $this->assertGreaterThanOrEqual(40, $checked, 'the links were read, not skipped');
        $this->assertSame([], $dead, "docs link to files that do not exist — the F1 browser offers a page it cannot open:\n  ".\implode("\n  ", $dead));
    }

    /** @return list<string> every .md under docs/, at any depth */
    private static function pages(string $docs): array
    {
        $pages = [];
        $walk = static function (string $dir) use (&$walk, &$pages): void {
            foreach ((array) \scandir($dir) as $entry) {
                if (!\is_string($entry) || \str_starts_with($entry, '.')) {
                    continue;
                }
                $path = $dir.'/'.$entry;
                if (\is_dir($path)) {
                    $walk($path);

                    continue;
                }
                if (\str_ends_with($entry, '.md')) {
                    $pages[] = $path;
                }
            }
        };
        $walk($docs);

        return $pages;
    }
}
