<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;

/**
 * CLAUDE.md has a line budget, because it is loaded into context on every
 * session and everything in it is paid for on every turn.
 *
 * It reached 1693 lines by accretion — 89 commits, more than half of them
 * carrying no code — and roughly two thirds of that was orientation useful
 * only to someone already working in the subsystem it described. That belongs
 * in `docs/internals/`, which the F1 browser renders and DocsIndexTest gates.
 *
 * What stays here is what changes an agent's next action: the gates, the hard
 * rules, where to read more, and the list of settled questions. If a change
 * needs more than a line, it needs a page.
 */
final class ClaudeMdBudgetTest extends TestCase
{
    private const int BUDGET = 100;

    public function testClaudeMdStaysWithinItsBudget(): void
    {
        $path = \dirname(__DIR__, levels: 3).'/CLAUDE.md';
        $lines = substr_count((string) file_get_contents($path), needle: "\n");

        $this->assertLessThanOrEqual(
            self::BUDGET,
            $lines,
            \sprintf('CLAUDE.md is %d lines, over its %d-line budget — move the detail into docs/internals/ and route to it', $lines, self::BUDGET),
        );
    }

    public function testEveryRoutedPageExists(): void
    {
        $root = \dirname(__DIR__, levels: 3);
        $claude = (string) file_get_contents($root.'/CLAUDE.md');

        $missing = [];
        preg_match_all('/`(docs\/[a-z\/-]+\.md)`/', $claude, $matches);
        foreach (array_unique($matches[1]) as $page) {
            if (is_file($root.'/'.$page)) {
                continue;
            }
            $missing[] = $page;
        }

        $this->assertSame([], $missing, 'CLAUDE.md routes to a page that does not exist');
    }
}
