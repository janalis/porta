<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DiagnosticMessageMode;
use Porta\Editor\DiagnosticSeverity;
use Porta\Tests\Support\RendersExactWidth;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The trailing cell — the space after a line's text, and the three things that
 * want it.
 *
 * A line can carry a debugger's value for a variable, an inline diagnostic
 * message and a plugin's annotation (git blame) all at once, and there is room
 * for one. The order is settled here: the debug value first, because it exists
 * only while the debugger is STOPPED and the other two keep their other
 * surfaces.
 *
 * Split out of `CodeEditorWidgetTest` when that class hit the method ratchet.
 * These belong together anyway: they are one cell, and every bug in it has come
 * from a claimant measuring its position differently from the others.
 */
final class CodeEditorTrailingCellTest extends TestCase
{
    use RendersExactWidth;

    private function editor(string $content = "<?php\n\necho 'hi';\n", string $path = 'demo.php'): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile($path, $content);
        $editor->setFocused(true);

        return $editor;
    }

    public function testTheDEBUGValueWinsTheTrailingCellFromTheDiagnosticAndTheAnnotation(): void
    {
        // One cell, three claimants. The value wins because it only exists
        // while the debugger is STOPPED — a mode entered on purpose, answering
        // the question being asked right now — and because the other two keep
        // their other surfaces: the diagnostic has its squiggle, its gutter
        // mark, its stripe cell and the Problems panel; blame has Alt+B.
        $editor = $this->editor("<?php\n\$count = 41;\n", 'debug.php');
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'Boom', 'col' => 0, 'endCol' => 6]]]);
        $editor->decorations->setLineAnnotations([1 => 'you, 3 days ago']);
        $editor->decorations->setDebugValues([1 => '$count = 41']);

        $rows = $editor->render(new RenderContext(80, 10));
        $row = AnsiUtils::stripAnsiCodes($rows[1]);

        $this->assertStringContainsString('$count = 41', $row);
        $this->assertStringNotContainsString('Boom', $row, 'the diagnostic keeps every surface but this one');
        $this->assertStringNotContainsString('3 days ago', $row);
        $this->assertExactWidth($rows, 80);
    }

    public function testWithoutADebugValueTheCellFallsBackToWhatItAlwaysShowed(): void
    {
        // The regression that matters: every line in every file with no
        // debugger running takes this path, and a precedence chain that got the
        // empty case wrong would blank the inline diagnostics everywhere.
        $editor = $this->editor("<?php\n\$count = 41;\n\$other = 1;\n", 'debug.php');
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'Boom', 'col' => 0, 'endCol' => 6]]]);
        $editor->decorations->setLineAnnotations([2 => 'you, 3 days ago']);
        $editor->decorations->setDebugValues([]);

        $rows = $editor->render(new RenderContext(80, 10));

        $this->assertStringContainsString('Boom', AnsiUtils::stripAnsiCodes($rows[1]));
        $this->assertStringContainsString('3 days ago', AnsiUtils::stripAnsiCodes($rows[2]));
        $this->assertExactWidth($rows, 80);
    }

    public function testAnAgentAtWorkOnALineTakesTheCellSecondAndPaintsItsCaret(): void
    {
        $editor = $this->editor("<?php\n\$count = 41;\n\$other = 1;\n", 'agent.php');
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'Boom', 'col' => 0, 'endCol' => 6]]]);
        $editor->decorations->setLineAnnotations([1 => 'you, 3 days ago', 2 => 'them, today']);
        $editor->decorations->setDebugValues([2 => '$other = 1']);
        $editor->decorations->setRemoteCarets([
            'agent.php' => [['line' => 1, 'col' => 1, 'label' => '✦ Claude', 'slot' => 1], ['line' => 2, 'col' => 0, 'label' => '✦ Codex', 'slot' => 0]],
            'elsewhere.php' => [['line' => 0, 'col' => 0, 'label' => '✦ Nobody here', 'slot' => 2]],
        ]);

        $rows = $editor->render(new RenderContext(80, 10));

        $this->assertStringContainsString('✦ Claude', AnsiUtils::stripAnsiCodes($rows[1]));
        $this->assertStringNotContainsString('Boom', AnsiUtils::stripAnsiCodes($rows[1]), 'the agent before the inline diagnostic');
        $this->assertStringContainsString('$other = 1', AnsiUtils::stripAnsiCodes($rows[2]), 'the debugger\'s value before the agent');
        $this->assertStringNotContainsString('Nobody here', AnsiUtils::stripAnsiCodes(\implode("\n", $rows)), 'another file\'s agents stay there');
        // The agent's background (Agent2's, dark: 130) opens on the second
        // character; the highlighter's own foreground may re-assert inside it.
        $this->assertMatchesRegularExpression('/\e\[48;5;130m(\e\[[0-9;:]*m)*c/', $rows[1], 'the caret cell, on the second character, in the agent\'s colour');
        $this->assertExactWidth($rows, 80);
    }

    public function testOnlyInlineDiagnosticMessagesTakeTheCell(): void
    {
        // In the menu, or nowhere, the message leaves the line — and the cell
        // goes back to the annotation it had been outranking.
        foreach ([DiagnosticMessageMode::Menu, DiagnosticMessageMode::None] as $mode) {
            $editor = $this->editor("<?php\n\$count = 41;\n", 'messages.php');
            $editor->display->setDiagnosticMessages($mode);
            $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'Boom', 'col' => 0, 'endCol' => 6]]]);
            $editor->decorations->setLineAnnotations([1 => 'you, 3 days ago']);

            $row = AnsiUtils::stripAnsiCodes($editor->render(new RenderContext(80, 10))[1]);

            $this->assertStringNotContainsString('Boom', $row, $mode->value.': the message is not in the line');
            $this->assertStringContainsString('3 days ago', $row, $mode->value.': blame has the cell back');
        }
    }

    public function testADebugValueOnONELineLeavesTheOtherLinesAlone(): void
    {
        // Per line, not per file: stopping on line 2 must not silence a
        // diagnostic on line 1.
        $editor = $this->editor("<?php\n\$count = 41;\n\$other = 1;\n", 'debug.php');
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'Boom', 'col' => 0, 'endCol' => 6]]]);
        $editor->decorations->setDebugValues([2 => '$other = 1']);

        $rows = $editor->render(new RenderContext(80, 10));

        $this->assertStringContainsString('Boom', AnsiUtils::stripAnsiCodes($rows[1]));
        $this->assertStringContainsString('$other = 1', AnsiUtils::stripAnsiCodes($rows[2]));
    }

    public function testTheTRAILINGCellFindsTheLastRowOfASoftWrappedLine(): void
    {
        // It used to find no row at all. The trailing slot measured from the
        // horizontal SCROLL while the content was sliced at the wrap segment's
        // offset, so every segment of a wrapped line looked full — and a blame
        // annotation, an inline diagnostic and a debug value alike were
        // silently dropped. Pre-existing, and inherited by every consumer of
        // that cell; found by rendering the combination rather than the pieces.
        $editor = $this->editor("<?php\n\$total = 'aaaa' . 'bbbb' . 'cccc' . 'dddd';\n", 'wrapped.php');
        $editor->display->setSoftWrap(true);
        $editor->decorations->setLineAnnotations([1 => 'you, 3 days ago']);

        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(40, 10)));

        $this->assertStringNotContainsString('3 days ago', $rows[1], 'the first segment is full');
        $this->assertStringContainsString('3 days ago', $rows[2], 'it goes after the text ends, on the last segment');
    }

    public function testADebugValueOnASoftWrappedLineFollowsTheSameRule(): void
    {
        $editor = $this->editor("<?php\n\$total = 'aaaa' . 'bbbb' . 'cccc' . 'dddd';\n", 'wrapped.php');
        $editor->display->setSoftWrap(true);
        $editor->decorations->setDebugValues([1 => 'V = 1']);

        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(40, 10)));

        $this->assertStringNotContainsString('V = 1', $rows[1]);
        $this->assertStringContainsString('V = 1', $rows[2]);
    }

    public function testTheDebugValueGoesAfterTheINLAYHintsNotThroughThem(): void
    {
        // The trap this editor already has scars from: inlay hints and the
        // collapsed-fold marker widen the DECORATED line past the raw
        // document line's width, so anything placed by the raw width slices
        // into them. The debug value rides the same slot as the inline
        // diagnostic and inherits the same rule.
        $editor = $this->editor("<?php\n\$r = add(1, 2);\n", 'hints.php');
        $editor->decorations->setInlayHints([1 => [['col' => 9, 'text' => 'a:'], ['col' => 12, 'text' => 'b:']]]);
        $editor->decorations->setDebugValues([1 => '$r = 3']);

        $row = AnsiUtils::stripAnsiCodes($editor->render(new RenderContext(60, 5))[1]);

        $this->assertStringContainsString('add(a:1, b:2);', $row, 'both hints survive intact');
        $this->assertLessThan(\mb_strpos($row, needle: '$r = 3'), \mb_strpos($row, needle: 'b:2'), 'and the value comes after them');
    }

    public function testTheDebugValueIsDrawnInItsOWNPenNotTheInlayGrey(): void
    {
        // An inlay hint is what the code MEANS and is always there; a debug
        // value is what it currently HOLDS and only while stopped. Reading the
        // same would make a live value look like part of the source.
        $editor = $this->editor("<?php\n\$count = 41;\n\$other = 1;\n", 'pen.php');
        $editor->decorations->setDebugValues([1 => '$count = 41']);
        $editor->decorations->setLineAnnotations([2 => 'you, 3 days ago']);

        $rows = $editor->render(new RenderContext(80, 10));

        $this->assertStringContainsString("\x1b[38;5;149m", $rows[1], 'the debug pen');
        $this->assertStringContainsString("\x1b[38;5;243m", $rows[2], 'and the annotation keeps the inlay grey');
        $this->assertStringNotContainsString("\x1b[38;5;149m", $rows[2]);
    }
}
