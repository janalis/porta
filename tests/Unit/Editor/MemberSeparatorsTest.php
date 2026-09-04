<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BreadcrumbScanner;
use Porta\Editor\MemberSeparators;

/**
 * Which lines get the rule. Fed by the same scopes the breadcrumb trail and the
 * sticky band read, so it works in every language they do.
 */
final class MemberSeparatorsTest extends TestCase
{
    public function testTheRuleGoesUNDEREachMembersLastLine(): void
    {
        // 1-based scopes in, 0-based document lines out: a member closing on
        // scanner line 9 rules document line 8.
        $scopes = [['label' => 'run', 'startLine' => 5, 'endLine' => 9, 'kind' => 'function']];

        self::assertSame([8 => true], MemberSeparators::linesFor($scopes));
    }

    public function testClassesAndNamespacesGetNoRule(): void
    {
        // Under a class is the file's last brace, and under a namespace,
        // nothing at all — neither separates one member from another.
        $scopes = [
            ['label' => 'App', 'startLine' => 3, 'endLine' => 40, 'kind' => 'namespace'],
            ['label' => 'Svc', 'startLine' => 5, 'endLine' => 39, 'kind' => 'type'],
        ];

        self::assertSame([], MemberSeparators::linesFor($scopes));
    }

    public function testTwoMembersClosingOnTheSameLineRuleItOnce(): void
    {
        // A one-line function nested in another: a set, not a count.
        $scopes = [
            ['label' => 'outer', 'startLine' => 3, 'endLine' => 7, 'kind' => 'function'],
            ['label' => 'inner', 'startLine' => 5, 'endLine' => 7, 'kind' => 'function'],
        ];

        self::assertSame([6 => true], MemberSeparators::linesFor($scopes));
    }

    public function testItReadsTheSCANNERSOwnOutput(): void
    {
        // The join, rather than a hand-written scope list: the shape has to be
        // the one BreadcrumbScanner actually produces, and the rule has to miss
        // the docblock that sits between the two methods.
        $source = <<<'SRC'
            <?php

            class A
            {
                public function one(): void
                {
                }

                /** Two. */
                public function two(): void
                {
                }
            }
            SRC;

        $lines = MemberSeparators::linesFor(BreadcrumbScanner::scan($source));

        // one() closes on line 7 (1-based) → rules line 6, the row above the
        // blank line and two lines above two()'s docblock. two() closes on 12.
        self::assertSame([6 => true, 11 => true], $lines);
    }
}
