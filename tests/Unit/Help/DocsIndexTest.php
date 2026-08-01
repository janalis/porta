<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;

/**
 * Every documentation page must be reachable from the index.
 *
 * Not a tidiness rule: `docs/` is what the in-IDE F1 browser renders, and
 * `docs/README.md` is its hard-coded home page. A page nothing links to exists
 * in the repository and nowhere in the product — which is what had happened to
 * `troubleshooting.md`, listed in neither table for as long as it has existed.
 */
final class DocsIndexTest extends TestCase
{
    public function testEveryPageIsLinkedFromTheIndex(): void
    {
        $docs = \dirname(__DIR__, levels: 3).'/docs';
        $index = (string) file_get_contents($docs.'/README.md');

        $unlinked = [];
        foreach (glob($docs.'/*.md') as $page) {
            $name = basename($page);
            if ('README.md' === $name) {
                continue; // the index does not link itself
            }
            if (!str_contains($index, '('.$name.')')) {
                $unlinked[] = $name;
            }
        }

        $this->assertSame([], $unlinked, 'unreachable from the F1 browser — add a row to docs/README.md');
    }
}
