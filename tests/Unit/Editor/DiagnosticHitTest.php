<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DiagnosticHit;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\DiagnosticSpan;
use Porta\Editor\DiagnosticStyle;
use Porta\Editor\Document;

/**
 * Where a problem is, in the columns the screen and the caret count in: the
 * squiggle's cells for the pointer, PhpStorm's tolerance for the caret.
 */
final class DiagnosticHitTest extends TestCase
{
    public function testASpanIsDrawnInDisplayColumns(): void
    {
        // é is one UTF-16 unit and one cell; 😀 is two units and two cells; the
        // tab runs to the next stop of four, one cell from column 3.
        $doc = new Document("é😀\tx\n");

        $this->assertSame([0, 1], DiagnosticSpan::display($doc, 0, ['col' => 0, 'endCol' => 1]));
        $this->assertSame([1, 3], DiagnosticSpan::display($doc, 0, ['col' => 1, 'endCol' => 3]), 'the emoji: two units, two cells');
        $this->assertSame([3, 4], DiagnosticSpan::display($doc, 0, ['col' => 3, 'endCol' => 4]), 'the tab: to its stop');
        $this->assertSame([4, 5], DiagnosticSpan::display($doc, 0, ['col' => 4]), 'no end is one cell');
        $this->assertSame([4, 5], DiagnosticSpan::display($doc, 0, ['col' => 4, 'endCol' => 4]), 'an empty range still covers its cell');
        $this->assertSame([3, \PHP_INT_MAX], DiagnosticSpan::display($doc, 0, ['col' => 3, 'endCol' => \PHP_INT_MAX]), 'a range past its line runs on');
    }

    public function testThePointerFindsTheProblemsDrawnUnderItWorstFirst(): void
    {
        $doc = new Document("\$a = foo(\$b);\n");
        $warning = ['severity' => DiagnosticSeverity::Warning, 'message' => 'w', 'col' => 0, 'endCol' => 2];
        $error = ['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 1, 'endCol' => 8];
        $rest = ['severity' => DiagnosticSeverity::Hint, 'message' => 'h', 'col' => 9, 'endCol' => \PHP_INT_MAX];

        $this->assertSame([$error, $warning], DiagnosticHit::atDisplayCol($doc, 0, [$warning, $error, $rest], 1));
        $this->assertSame([$warning], DiagnosticHit::atDisplayCol($doc, 0, [$warning, $error, $rest], 0), 'the first cell');
        $this->assertSame([], DiagnosticHit::atDisplayCol($doc, 0, [$warning, $error], 8), 'one past the end is not the problem');
        $this->assertSame([$rest], DiagnosticHit::atDisplayCol($doc, 0, [$rest], 12), "a range past its line covers the line's last character");
        $this->assertSame([], DiagnosticHit::atDisplayCol($doc, 0, [$rest], 13), 'but not the empty cells after the code');
    }

    public function testAProblemAtTheEndOfTheLineKeepsItsCell(): void
    {
        $doc = new Document("ab\n");
        $atEnd = ['severity' => DiagnosticSeverity::Error, 'message' => 'missing ;', 'col' => 2, 'endCol' => \PHP_INT_MAX];

        $this->assertSame([$atEnd], DiagnosticHit::atDisplayCol($doc, 0, [$atEnd], 2));
    }

    public function testTheCaretFindsTheProblemItRestsOnOrJustAfter(): void
    {
        $range = ['severity' => DiagnosticSeverity::Warning, 'message' => 'w', 'col' => 4, 'endCol' => 7];
        $empty = ['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 2, 'endCol' => 2];

        $this->assertSame([], DiagnosticHit::atCaret([$range], 3), 'before it');
        $this->assertSame([$range], DiagnosticHit::atCaret([$range], 4));
        $this->assertSame([$range], DiagnosticHit::atCaret([$range], 7), 'just after it, where typing leaves the caret');
        $this->assertSame([], DiagnosticHit::atCaret([$range], 8));
        $this->assertSame([$empty], DiagnosticHit::atCaret([$empty, $range], 2), 'an empty range at its column');
        $noEnd = ['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 2];
        $this->assertSame([$noEnd], DiagnosticHit::atCaret([$noEnd], 3), 'no end: one unit wide, so the caret after it still counts');
        $this->assertSame([], DiagnosticHit::atCaret([$noEnd], 4));
    }

    public function testWorstFirstKeepsTheOrderWithinASeverity(): void
    {
        $a = ['severity' => DiagnosticSeverity::Warning, 'message' => 'a'];
        $b = ['severity' => DiagnosticSeverity::Error, 'message' => 'b'];
        $c = ['severity' => DiagnosticSeverity::Warning, 'message' => 'c'];

        $this->assertSame([$b, $a, $c], DiagnosticStyle::worstFirst([$a, $b, $c]));
    }
}
