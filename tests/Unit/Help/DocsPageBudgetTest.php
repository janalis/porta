<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\DocsTree;

/**
 * No documentation page may grow past a size an agent can read in one go.
 *
 * `docs/internals/decisions.md` reached 626KB — about 150k tokens — by one
 * dated entry per round, and CLAUDE.md routed every refactor proposal to it.
 * Only its first 25KB was the verdicts anyone needed; the rest was history,
 * and a page that size is read by grep or not at all. The history now lives
 * in `docs/internals/decisions/`, a part per ~90KB, and this budget is what
 * makes the next part start instead of the last one growing.
 */
final class DocsPageBudgetTest extends TestCase
{
    private const int BUDGET_BYTES = 100_000;

    public function testNoDocsPageIsOverItsBudget(): void
    {
        $repo = \dirname(DocsTree::core());

        $pages = 0;
        $over = [];
        foreach (DocsTree::pages() as $page) {
            ++$pages;
            $size = (int) \filesize($page);
            if ($size <= self::BUDGET_BYTES) {
                continue;
            }
            $over[] = \sprintf('%s (%d bytes)', \substr($page, \strlen($repo) + 1), $size);
        }

        \sort($over);
        // Asserted first: a walk that found nothing would find nothing over budget either.
        $this->assertGreaterThanOrEqual(20, $pages, 'the pages were walked, not skipped');
        $this->assertSame([], $over, \sprintf('over the %d-byte page budget — split the page (a history page starts a new part)', self::BUDGET_BYTES));
    }
}
