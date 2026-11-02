<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\DocsTree;

/**
 * Every documentation page must be reachable from the index above it — a
 * bundled plugin's own guide included, from Porta's front page.
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
        $docs = DocsTree::core();
        // A plugin's docs root hangs off Porta's own front page, by the
        // relative path the browser resolves from it.
        $roots = [$docs => null];
        foreach (DocsTree::plugins() as $name => $dir) {
            $roots[$dir] = '../plugins/'.$name.'/docs/README.md';
        }

        $unlinked = [];
        foreach ($roots as $root => $fromHome) {
            foreach (DocsTree::pagesUnder($root) as $page) {
                $folder = \dirname($page);
                // A folder's own index is linked from its PARENT's, by the
                // relative path the browser resolves: `(internals/README.md)`.
                [$index, $target] = 'README.md' === \basename($page)
                    ? [\dirname($folder).'/README.md', \basename($folder).'/README.md']
                    : [$folder.'/README.md', \basename($page)];

                if ($folder === $root && 'README.md' === \basename($page)) {
                    if (null === $fromHome) {
                        continue; // the root index is the browser's home; nothing above it
                    }
                    [$index, $target] = [$docs.'/README.md', $fromHome];
                }
                if (!\is_file($index) || !\str_contains((string) \file_get_contents($index), '('.$target.')')) {
                    $unlinked[] = self::relative($page);
                }
            }
        }

        \sort($unlinked);
        $this->assertSame([], $unlinked, 'unreachable from the F1 browser — add a row to the README.md beside it (a plugin\'s docs/README.md: to docs/README.md\'s Bundled plugins table)');
    }

    /**
     * Every bundled plugin documents itself, in its own `docs/README.md`.
     *
     * A plugin is a feature somebody turns on from a list of names; the page
     * is where that list leads (Settings → Plugins → Documentation, and the
     * front page's Bundled plugins table). Without this, the next plugin
     * would ship as a name and nothing else — which is what nine of the
     * seventeen were when plugins started carrying their own docs.
     */
    public function testEveryBundledPluginShipsItsOwnGuide(): void
    {
        $plugins = DocsTree::plugins();
        $this->assertGreaterThanOrEqual(17, \count($plugins), 'the bundled plugins were found, not skipped');

        $undocumented = \array_keys(\array_filter($plugins, static fn (string $dir): bool => !\is_file($dir.'/README.md')));

        $this->assertSame([], $undocumented, 'these bundled plugins have no docs/README.md');
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
     * exists, not that a heading inside it does. And it must exist INSIDE a
     * docs tree — Porta's or a plugin's — because that is all the browser
     * opens: a link to a directory or to source code resolves on disk and is
     * refused on click.
     */
    public function testEveryRelativeLinkPointsAtAFileThatExists(): void
    {
        $checked = 0;
        $dead = [];
        foreach (DocsTree::pages() as $page) {
            $matches = [];
            \preg_match_all('~\]\(([^)#][^)]*)\)~', (string) \file_get_contents($page), $matches);
            foreach ($matches[1] as $link) {
                if (1 === \preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/screenshots/)~i', $link)) {
                    continue; // an external scheme is not ours to resolve, nor a screenshot: DocsScreenshotsTest's
                }
                $target = \explode('#', $link)[0];
                if ('' === $target) {
                    continue;
                }
                ++$checked;
                $resolved = \realpath(\dirname($page).'/'.$target);
                if (false !== $resolved && \is_file($resolved) && self::insideATree($resolved)) {
                    continue;
                }
                $dead[] = self::relative($page).' → '.$link;
            }
        }

        \sort($dead);
        // Asserted first: a regex that stopped matching would find no dead
        // links either, and this gate would go green and stay there.
        $this->assertGreaterThanOrEqual(40, $checked, 'the links were read, not skipped');
        $this->assertSame([], $dead, "docs link to files that do not exist — the F1 browser offers a page it cannot open:\n  ".\implode("\n  ", $dead));
    }

    /**
     * Whether $file sits in a tree the browser will open: Porta's docs or a
     * plugin's. A link to anywhere else exists on disk and is refused on
     * click all the same, which is no better than a dead one.
     */
    private static function insideATree(string $file): bool
    {
        foreach ([DocsTree::core(), ...\array_values(DocsTree::plugins())] as $root) {
            $root = \realpath($root);
            if (false !== $root && \str_starts_with($file, $root.'/')) {
                return true;
            }
        }

        return false;
    }

    /** $page as the repository names it, for a failure a reader can open. */
    private static function relative(string $page): string
    {
        return \substr($page, \strlen(\dirname(DocsTree::core())) + 1);
    }
}
