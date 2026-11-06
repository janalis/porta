<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\LineOperations;

final class LineOperationsTest extends TestCase
{
    public function testSortedSortsAsPlainStrings(): void
    {
        $this->assertSame(['Banana', 'apple', 'cherry'], LineOperations::sorted(['cherry', 'apple', 'Banana']));
    }

    public function testCaseToggledUppercasesMultibyteCharactersCorrectly(): void
    {
        // mb_strtoupper vs the byte-based strtoupper: the latter leaves a
        // multibyte lowercase letter untouched.
        $this->assertSame(['CAFÉ'], LineOperations::caseToggled(['café']));
    }

    public function testCaseToggledLowercasesMultibyteCharactersCorrectly(): void
    {
        $this->assertSame(['café'], LineOperations::caseToggled(['CAFÉ']));
    }

    public function testJoinedTrimsTheFirstLinesTrailingWhitespace(): void
    {
        $this->assertSame('  hello', LineOperations::joined(['  hello   '], 0, 0));
    }

    public function testJoinedTrimsTrailingWhitespaceAfterMerging(): void
    {
        $this->assertSame('a b', LineOperations::joined(['a', 'b   '], 0, 1));
    }

    public function testCommentToggledAddsThePrefixToNonBlankLines(): void
    {
        $this->assertSame(['// a', '', ' // b'], LineOperations::commentToggled(['a', '', ' b'], '//'));
    }

    public function testCommentToggledRemovesThePrefixWhenAllLinesCarryIt(): void
    {
        $this->assertSame(['a', '', ' b', 'c'], LineOperations::commentToggled(['// a', '', '//  b', '//c'], '//'));
        $this->assertSame(['   foo();'], LineOperations::commentToggled(['//    foo();'], '//'), 'only the space commenting adds goes: the code keeps the rest of its indent');
    }

    public function testStatementTargetWithDeltaZeroTakesThePositiveBranch(): void
    {
        // delta === 0 must not be treated as "moving up" (delta < 0).
        $lines = ['a', 'b', 'c', 'd', 'e'];
        $this->assertSame(2, LineOperations::statementTarget($lines, 0, 1, 1));
    }

    public function testStatementTargetUpAtTheTopReturnsNull(): void
    {
        $this->assertNull(LineOperations::statementTarget(['a', 'b'], -1, 0, 0));
    }

    public function testStatementTargetDownAtTheBottomReturnsNull(): void
    {
        $this->assertNull(LineOperations::statementTarget(['a', 'b'], 1, 1, 1));
    }

    public function testStatementTargetUpWithNoBlankLineStepsByOne(): void
    {
        $lines = ['a', 'b', 'c'];
        $this->assertSame(1, LineOperations::statementTarget($lines, -1, 2, 2));
    }

    public function testStatementTargetUpHopsOverASingleBlankLine(): void
    {
        $lines = ['a', '', 'c'];
        $this->assertSame(1, LineOperations::statementTarget($lines, -1, 2, 2));
    }

    public function testStatementTargetUpHopsOverAWhitespaceOnlyBlankLine(): void
    {
        // A blank check that forgot to trim() would treat "   " as non-blank.
        $lines = ['a', '   ', 'c'];
        $this->assertSame(1, LineOperations::statementTarget($lines, -1, 2, 2));
    }

    public function testStatementTargetUpHopsOverARunOfWhitespaceOnlyBlankLines(): void
    {
        $lines = ['a', '   ', '   ', 'c'];
        $this->assertSame(1, LineOperations::statementTarget($lines, -1, 3, 3));
    }

    public function testStatementTargetUpWithBlankRunReachingTheTop(): void
    {
        $lines = ['', '', 'c'];
        $this->assertSame(0, LineOperations::statementTarget($lines, -1, 2, 2));
    }

    public function testStatementTargetDownWithNoBlankLineStepsByOne(): void
    {
        $lines = ['a', 'b', 'c', 'd', 'e'];
        $this->assertSame(2, LineOperations::statementTarget($lines, 1, 1, 1));
    }

    public function testStatementTargetDownHopsOverAWhitespaceOnlyRun(): void
    {
        $lines = ['a', 'b', '   ', '   ', 'e'];
        $this->assertSame(3, LineOperations::statementTarget($lines, 1, 1, 1));
    }

    public function testStatementTargetDownWithFirstAheadOfLast(): void
    {
        $lines = ['a', 'b', 'c'];
        $this->assertSame(1, LineOperations::statementTarget($lines, 1, 0, 1));
    }
}
