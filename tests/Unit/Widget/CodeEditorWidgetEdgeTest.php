<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\CompletionItem;
use Porta\Editor\Document;
use Porta\Editor\LineNumberMode;
use Porta\Highlight\CodeHighlighter;
use Porta\Support\AnsiText;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\FakeEditorNavigation;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorCallbacks;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Branch coverage for CodeEditorWidget beyond the main scenarios.
 */
final class CodeEditorWidgetEdgeTest extends TestCase
{
    private function editor(string $content = "<?php\nline one\nline two\n", string $path = 'edge.php'): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile($path, $content);
        $editor->setFocused(true);

        return $editor;
    }

    public function testOnInputHookConsumesInput(): void
    {
        $editor = $this->editor();
        $editor->onInput(static fn (string $data): bool => 'X' === $data);

        $editor->handleInput('X');
        $this->assertStringNotContainsString('X', $editor->getDocument()->getLine(0), 'consumed by hook');

        $editor->handleInput('Y');
        $this->assertStringContainsString('Y', $editor->getDocument()->getLine(0), 'unconsumed input flows through');
    }

    public function testExpandVerticallyIsAlwaysOn(): void
    {
        $editor = $this->editor();
        $this->assertSame($editor, $editor->expandVertically(false));
        $this->assertTrue($editor->isVerticallyExpanded());
    }

    public function testActionKeysWithoutCallbacksAreNoOps(): void
    {
        $editor = $this->editor();
        foreach (["\x1b[1;3C", "\x05", "\x1bw", "\x06", "\x10", "\x1b[1;2P", "\x1b[18;3~", "\x1b[17;2~", "\x00", "\x13", "\x1b[24~", "\x1b[1;7D"] as $key) {
            $editor->handleInput($key);
        }
        $this->assertStringStartsWith('<?php', $editor->getDocument()->getLine(0), 'nothing crashed or typed');
    }

    public function testPlainEnterInsertsNewLine(): void
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(1, 4);
        $editor->handleInput("\r");

        $this->assertSame('line', $editor->getDocument()->getLine(1));
        $this->assertSame(' one', $editor->getDocument()->getLine(2));
    }

    public function testTabIndentsAndRedoKeystroke(): void
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(1, 0);
        $editor->handleInput("\t");
        $this->assertSame('    line one', $editor->getDocument()->getLine(1));

        $editor->handleInput("\x1a"); // ctrl+z undo
        $this->assertSame('line one', $editor->getDocument()->getLine(1));
        $editor->handleInput("\x1b[122;6u"); // ctrl+shift+z redo (CSI-u)
        $this->assertSame('    line one', $editor->getDocument()->getLine(1));
    }

    public function testTabIndentedFileInsertsTabAndRendersExpanded(): void
    {
        // A tab-indented file: Tab inserts a real tab, and rendering expands
        // it to spaces so widths line up.
        $editor = $this->editor("<?php\n\tfoo();\n", 'tabbed.php');
        $doc = $editor->getDocument();
        $this->assertTrue($doc->usesTabs());

        $doc->setCursor(2, 0);
        $editor->handleInput("\t");
        $this->assertSame("\tfoo();", $doc->getLine(1), 'existing tab preserved');
        $this->assertSame("\t", $doc->getLine(2), 'Tab inserted a real tab, not spaces');

        // Select the tab-indented line; the rendered row shows the tab as
        // spaces (no literal tab byte) and stays the exact terminal width.
        $rendered = $editor->render(new RenderContext(40, 6));
        $plain = AnsiUtils::stripAnsiCodes($rendered[1]);
        $this->assertStringNotContainsString("\t", $plain, 'tab expanded to spaces on screen');
        // The tab renders as a 4-cell indent (an indent guide occupies the
        // first cell), followed by the code.
        $this->assertStringContainsString('foo();', $plain);
        $this->assertStringContainsString('│', $plain, 'indent guide drawn in the tab indentation');
    }

    public function testSelectAllDocumentBoundsAndPagingKeys(): void
    {
        $editor = $this->editor();

        $editor->handleInput("\x01"); // ctrl+a select all
        $this->assertTrue($editor->getDocument()->hasSelection());

        $editor->handleInput("\x1b[1;5H"); // ctrl+home
        $this->assertSame(0, $editor->getDocument()->getCursorLine());
        $this->assertFalse($editor->getDocument()->hasSelection());

        $editor->handleInput("\x1b[1;5F"); // ctrl+end
        $this->assertSame(3, $editor->getDocument()->getCursorLine());

        $editor->handleInput("\x1b[5~"); // page up
        $this->assertSame(0, $editor->getDocument()->getCursorLine());
        $editor->handleInput("\x1b[6~"); // page down
        $this->assertGreaterThan(0, $editor->getDocument()->getCursorLine());
    }

    public function testCutAndPasteReplacingSelection(): void
    {
        $editor = $this->editor("abc def\n");
        $doc = $editor->getDocument();
        $doc->setCursor(0, 0);
        for ($i = 0; $i < 3; ++$i) {
            $editor->handleInput("\x1b[1;2C"); // select "abc"
        }

        $editor->handleInput("\x18"); // ctrl+x cut
        $this->assertSame(' def', $doc->getLine(0));

        // Select " def" and paste over it
        $doc->setCursor(0, 0);
        for ($i = 0; $i < 4; ++$i) {
            $editor->handleInput("\x1b[1;2C");
        }
        $editor->handleInput("\x16"); // ctrl+v
        $this->assertSame('abc', $doc->getLine(0));

        // Copy/cut with no selection are no-ops
        $editor->handleInput("\x03");
        $editor->handleInput("\x18");
        $this->assertSame('abc', $doc->getLine(0));
    }

    public function testPasteWithEmptyClipboardIsNoOp(): void
    {
        $editor = $this->editor("x\n");
        $editor->handleInput("\x16");
        $this->assertSame('x', $editor->getDocument()->getLine(0));
    }

    public function testCompletionFilterNarrowingAndDivergence(): void
    {
        $editor = $this->editor("<?php\nstr\n");
        $editor->getDocument()->setCursor(1, 3);
        $items = [
            new CompletionItem(label: 'strlen', insertText: 'strlen', kind: 'fn', detail: ''),
            new CompletionItem(label: 'strrev', insertText: 'strrev', kind: 'fn', detail: ''),
        ];
        $editor->showCompletions($items);

        // Typing identifier chars narrows the filter
        $editor->handleInput('l');
        $this->assertTrue($editor->isCompletionOpen());
        $rendered = implode("\n", $editor->render(new RenderContext(80, 10)));
        $this->assertStringContainsString('strlen', AnsiUtils::stripAnsiCodes($rendered));
        $this->assertStringNotContainsString('strrev', AnsiUtils::stripAnsiCodes($rendered));

        // Backspace widens again (word still non-empty keeps popup open)
        $editor->handleInput("\x7f");
        $this->assertTrue($editor->isCompletionOpen());

        // Typing until nothing matches closes the popup on the next key
        $editor->handleInput('z');
        $editor->handleInput('z');
        $this->assertFalse($editor->isCompletionOpen());
    }

    public function testCompletionFuzzyCamelHumpRanking(): void
    {
        $editor = $this->editor("<?php\ng\n");
        $editor->getDocument()->setCursor(1, 1);
        $editor->showCompletions([
            new CompletionItem(label: 'genericmoment', insertText: 'genericmoment', kind: 'fn', detail: ''),
            new CompletionItem(label: 'getMessage', insertText: 'getMessage', kind: 'fn', detail: ''),
        ]);

        // Type "m" so the query becomes "gm" — a subsequence of both, but the
        // camelCase hump in getMessage ranks it above the buried lowercase match.
        $editor->handleInput('m');
        $this->assertTrue($editor->isCompletionOpen());

        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $editor->render(new RenderContext(80, 10))));
        $this->assertStringContainsString('getMessage', $plain);
        $this->assertStringContainsString('genericmoment', $plain);
        $this->assertLessThan(
            strpos($plain, needle: 'genericmoment'),
            strpos($plain, needle: 'getMessage'),
            'camelCase-hump match ranks first',
        );
    }

    public function testSnippetExpansionWithTabStops(): void
    {
        $editor = $this->editor("<?php\n\n");
        $editor->getDocument()->setCursor(1, 0);
        $editor->showCompletions([new CompletionItem(
            label: 'foo',
            insertText: 'foo',
            kind: 'fn',
            detail: '',
            snippet: 'foo(${1:a}, ${2:b})$0',
        )]);
        $editor->handleInput("\r"); // accept -> expand snippet

        $doc = $editor->getDocument();
        $this->assertSame('foo(a, b)', $doc->getLine(1), 'snippet expanded with placeholder text');
        $this->assertSame('a', $doc->getSelectedText(), 'first tab stop selected');

        // Type over the first placeholder.
        $editor->handleInput('X');
        $this->assertSame('foo(X, b)', $doc->getLine(1));

        // Tab jumps to the second tab stop and selects it.
        $editor->handleInput("\t");
        $this->assertSame('b', $doc->getSelectedText(), 'second tab stop selected');

        $editor->handleInput('Y');
        $this->assertSame('foo(X, Y)', $doc->getLine(1));

        // Tab -> $0 (final caret, no selection), Tab again -> session ends.
        $editor->handleInput("\t");
        $this->assertFalse($doc->hasSelection());
        $editor->handleInput("\t");

        // Session over: Tab now inserts indentation instead of navigating.
        $editor->handleInput("\t");
        $this->assertStringContainsString('    ', $doc->getLine(1), 'Tab indents once the session ends');
    }

    public function testSnippetShiftTabAndEscape(): void
    {
        $editor = $this->editor("<?php\n\n");
        $editor->getDocument()->setCursor(1, 0);
        $editor->showCompletions([new CompletionItem(
            label: 'pair',
            insertText: 'pair',
            kind: 'fn',
            detail: '',
            snippet: '${1:x}-${2:y}',
        )]);
        $editor->handleInput("\r");
        $doc = $editor->getDocument();
        $this->assertSame('x-y', $doc->getLine(1));
        $this->assertSame('x', $doc->getSelectedText());

        $editor->handleInput("\t"); // -> second stop
        $this->assertSame('y', $doc->getSelectedText());
        $editor->handleInput("\x1b[Z"); // shift+tab -> back to first
        $this->assertSame('x', $doc->getSelectedText());

        $editor->handleInput("\x1b"); // esc ends the session
        $this->assertFalse($doc->hasSelection(), 'esc collapses the placeholder selection');

        // Session over: Tab now indents instead of navigating stops.
        $editor->handleInput("\t");
        $this->assertStringContainsString('    ', $doc->getLine(1), 'Tab indents once the session ends');
    }

    public function testSnippetBackspaceAndArrowExit(): void
    {
        $editor = $this->editor("<?php\n\n");
        $editor->getDocument()->setCursor(1, 0);
        $editor->showCompletions([new CompletionItem(
            label: 'p',
            insertText: 'p',
            kind: 'fn',
            detail: '',
            snippet: '${1:aa}-${2:b}',
        )]);
        $editor->handleInput("\r");
        $doc = $editor->getDocument();
        $this->assertSame('aa-b', $doc->getLine(1));
        $this->assertSame('aa', $doc->getSelectedText());

        // Backspace deletes the selected placeholder (deleteSelection path).
        $editor->handleInput("\x7f");
        $this->assertSame('-b', $doc->getLine(1));
        $this->assertFalse($doc->hasSelection());

        // Type then backspace with no selection (deleteBackward path).
        $editor->handleInput('Z');
        $this->assertSame('Z-b', $doc->getLine(1));
        $editor->handleInput("\x7f");
        $this->assertSame('-b', $doc->getLine(1));

        // A cursor-movement key ends the session and is handled normally.
        $editor->handleInput("\x1b[C"); // right arrow
        $editor->handleInput("\t"); // now a plain indent, session is over
        $this->assertStringContainsString('    ', $doc->getLine(1));
    }

    private const CTRL_DOT = "\x1b[46;5u"; // toggle_fold

    private function foldFixture(): CodeEditorWidget
    {
        $editor = $this->editor("<?php\nfunction f() {\n    a();\n    b();\n}\nafter();\n");
        $editor->setFocused(true);

        return $editor;
    }

    /** @return string[] visible screen rows, ANSI stripped */
    private function screenRows(CodeEditorWidget $editor, int $w = 60, int $h = 10): array
    {
        return array_map(
            static fn (string $r) => rtrim(AnsiUtils::stripAnsiCodes($r)),
            $editor->render(new RenderContext($w, $h)),
        );
    }

    public function testFoldCollapsesRegionAndUnfolds(): void
    {
        $editor = $this->foldFixture();
        $editor->getDocument()->setCursor(1, 0); // on "function f() {"

        $editor->handleInput(self::CTRL_DOT); // fold
        $joined = implode("\n", $this->screenRows($editor));
        $this->assertStringContainsString('⋯', $joined, 'fold marker shown');
        $this->assertStringNotContainsString('a();', $joined, 'interior hidden');
        $this->assertStringContainsString('after();', $joined, 'code after the fold still visible');

        $editor->handleInput(self::CTRL_DOT); // unfold
        $joined = implode("\n", $this->screenRows($editor));
        $this->assertStringContainsString('a();', $joined, 'interior restored');
        $this->assertStringNotContainsString('⋯', $joined);
    }

    public function testClickMapsThroughFoldedLines(): void
    {
        $editor = $this->foldFixture();
        $editor->getDocument()->setCursor(1, 0);
        $editor->handleInput(self::CTRL_DOT); // fold lines 2-4
        $this->screenRows($editor); // render to settle vScroll

        // Screen row 2 is the first line after the fold ("after();", doc line 5).
        $editor->clickAt(2, 20);
        $this->assertSame(5, $editor->getDocument()->getCursorLine());
    }

    public function testMovingIntoFoldedRegionAutoUnfolds(): void
    {
        $editor = $this->foldFixture();
        $editor->getDocument()->setCursor(1, 0);
        $editor->handleInput(self::CTRL_DOT);
        $this->assertStringNotContainsString('a();', implode("\n", $this->screenRows($editor)));

        // Placing the caret inside the collapsed region reveals it on render.
        $editor->getDocument()->setCursor(3, 0);
        $this->assertStringContainsString('a();', implode("\n", $this->screenRows($editor)));
    }

    public function testToggleFoldWithNoRegionDoesNothing(): void
    {
        $editor = $this->foldFixture();
        $editor->getDocument()->setCursor(0, 0); // "<?php" — no enclosing block
        $editor->handleInput(self::CTRL_DOT);
        $joined = implode("\n", $this->screenRows($editor));
        $this->assertStringNotContainsString('⋯', $joined);
        $this->assertStringContainsString('a();', $joined);
    }

    public function testFoldedRegionBelowViewportScrollsCorrectly(): void
    {
        // A fold above a caret that sits past the viewport bottom exercises the
        // scroll-into-view path over visible (not raw) lines.
        $lines = "<?php\nfunction f() {\n    a();\n    b();\n}\n";
        for ($i = 0; $i < 20; ++$i) {
            $lines .= "line{$i}();\n";
        }
        $editor = $this->editor($lines);
        $editor->setFocused(true);

        $editor->getDocument()->setCursor(1, 0);
        $editor->handleInput(self::CTRL_DOT); // fold the function
        $editor->getDocument()->setCursor(20, 0); // well below the viewport

        $rows = $this->screenRows($editor, 60, 6);
        $this->assertStringContainsString('line15();', implode("\n", $rows), 'caret line is scrolled into view');
    }

    public function testScrollingUpAcrossAFoldSnapsToVisibleLine(): void
    {
        // A large folded block just below the caret; a tiny viewport forces the
        // scroll anchor to walk up *through* the fold interior and snap to its
        // visible start line.
        $lines = "<?php\n";
        $lines .= "function big() {\n";
        for ($i = 0; $i < 12; ++$i) {
            $lines .= "    step{$i}();\n";
        }
        $lines .= "}\n";
        $lines .= "tail();\n";
        $editor = $this->editor($lines);
        $editor->setFocused(true);

        $editor->getDocument()->setCursor(1, 0); // "function big() {"
        $editor->handleInput(self::CTRL_DOT); // fold lines 2..14
        $editor->getDocument()->setCursor(15, 0); // "tail();" just after the fold

        // Height 2 with the caret at the bottom forces the anchor above the
        // caret to land inside the collapsed region and snap to line 1.
        $rows = $this->screenRows($editor, 60, 2);
        $joined = implode("\n", $rows);
        $this->assertStringContainsString('tail();', $joined);
        $this->assertStringContainsString('⋯', $joined, 'fold start becomes the scroll anchor');
    }

    public function testCompletionClosesWhenWordFullyDeleted(): void
    {
        $editor = $this->editor("<?php\ns\n");
        $editor->getDocument()->setCursor(1, 1);
        $editor->showCompletions([new CompletionItem(label: 'strlen', insertText: 'strlen', kind: 'fn', detail: '')]);

        $editor->handleInput("\x7f"); // deletes the "s"; popup stays open one more key
        $editor->handleInput("\x7f"); // word already empty -> closes
        $this->assertFalse($editor->isCompletionOpen());
    }

    public function testCompletionNonIdentifierKeyClosesAndExecutes(): void
    {
        $editor = $this->editor("<?php\nstr\n");
        $editor->getDocument()->setCursor(1, 3);
        $editor->showCompletions([new CompletionItem(label: 'strlen', insertText: 'strlen', kind: 'fn', detail: '')]);

        $editor->handleInput(';');
        $this->assertFalse($editor->isCompletionOpen());
        $this->assertSame('str;', $editor->getDocument()->getLine(1), 'the key was still applied');
    }

    public function testCompletionUpArrowWrapsAndTabAccepts(): void
    {
        $editor = $this->editor("<?php\nstr\n");
        $editor->getDocument()->setCursor(1, 3);
        $editor->showCompletions([
            new CompletionItem(label: 'strlen', insertText: 'strlen', kind: 'fn', detail: ''),
            new CompletionItem(label: 'strrev', insertText: 'strrev', kind: 'fn', detail: ''),
        ]);

        $editor->handleInput("\x1b[A"); // up from 0 wraps to last
        $editor->handleInput("\t"); // tab accepts
        $this->assertSame('strrev', $editor->getDocument()->getLine(1));
    }

    public function testCompletionPopupWindowScrollsPastEightItems(): void
    {
        $editor = $this->editor("<?php\nit\n");
        $editor->getDocument()->setCursor(1, 2);
        $items = [];
        for ($i = 0; $i < 12; ++$i) {
            $items[] = new CompletionItem(label: \sprintf('item%02d', $i), insertText: 'x', kind: 'fn', detail: '');
        }
        $editor->showCompletions($items);

        for ($i = 0; $i < 10; ++$i) {
            $editor->handleInput("\x1b[B");
        }
        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $editor->render(new RenderContext(80, 20))));
        $this->assertStringContainsString('item10', $plain, 'window followed selection');
        $this->assertStringNotContainsString('item00', $plain);
    }

    public function testOverlayUpWrapCtrlECyclingAndUnknownKey(): void
    {
        $editor = $this->editor();
        $picked = null;
        $editor->openListOverlay(
            'Buffers',
            [
                ['label' => 'one', 'hint' => '', 'value' => '1'],
                ['label' => 'two', 'hint' => '', 'value' => '2'],
            ],
            static function (string $v) use (&$picked): void {
                $picked = $v;
            },
        );

        $editor->handleInput("\x1b[A"); // up from 0 wraps to last
        $editor->handleInput("\x05"); // ctrl+e cycles forward (wraps to first)
        $editor->handleInput('q'); // unknown key ignored
        $editor->handleInput("\r");
        $this->assertSame('1', $picked);

        // Empty overlay refuses to open
        $editor->openListOverlay('Empty', [], static fn () => null);
        $this->assertFalse(EditorState::pickerOpen($editor));
    }

    public function testSignatureHintClearedOnCloseParenAndVerticalMove(): void
    {
        $editor = $this->editor("<?php\nf(\n");
        $editor->getDocument()->setCursor(1, 2);
        $editor->setSignatureHint('f(int $x)');

        $editor->handleInput(')');
        $rendered = AnsiUtils::stripAnsiCodes(implode("\n", $editor->render(new RenderContext(80, 10))));
        $this->assertStringNotContainsString('f(int $x)', $rendered, 'closing paren clears the hint');

        $editor->setSignatureHint('f(int $x)');
        $editor->handleInput("\x1b[A"); // cursor up clears
        $rendered = AnsiUtils::stripAnsiCodes(implode("\n", $editor->render(new RenderContext(80, 10))));
        $this->assertStringNotContainsString('f(int $x)', $rendered);
    }

    public function testSignatureHintSkippedWhenNoRoom(): void
    {
        $editor = $this->editor("a\n");
        $editor->setSignatureHint('very long signature text');

        // Tiny width: hint area (columns - gutter - 1) < 4 -> skipped
        $lines = $editor->render(new RenderContext(9, 3));
        $this->assertStringNotContainsString('very', AnsiUtils::stripAnsiCodes(implode("\n", $lines)));
    }

    public function testCommentPrefixVariants(): void
    {
        $cases = [
            ['script.sh', 'echo hi',   '# echo hi'],
            ['query.sql', 'SELECT 1;', '-- SELECT 1;'],
            ['page.twig', '{{ x }}',   '{{ x }}'], // twig: unsupported, no change
            ['notes.xyz', 'plain',     'plain'], // unknown language: no change
        ];
        foreach ($cases as [$file, $before, $after]) {
            $editor = $this->editor($before."\n", $file);
            $editor->getDocument()->setCursor(0, 0);
            $editor->handleInput("\x1f"); // toggle_comment
            $this->assertSame($after, $editor->getDocument()->getLine(0), $file);
        }
    }

    public function testEscapeClearsSelection(): void
    {
        $editor = $this->editor();
        $editor->handleInput("\x1b[1;2C"); // shift+right
        $this->assertTrue($editor->getDocument()->hasSelection());

        $editor->handleInput("\x1b");
        $this->assertFalse($editor->getDocument()->hasSelection());

        $editor->handleInput("\x1b"); // escape with nothing to do
        $this->assertFalse($editor->getDocument()->hasSelection());
    }

    public function testScrollClampsWhenJumpingBackToTop(): void
    {
        $lines = implode("\n", array_map(static fn (int $i) => "row {$i}", range(0, end: 99)));
        $editor = $this->editor($lines);
        $editor->getDocument()->setCursor(80, 0);
        $editor->render(new RenderContext(40, 10)); // scrolls down

        $editor->getDocument()->setCursor(0, 0);
        $rendered = $editor->render(new RenderContext(40, 10)); // vScroll snaps back up
        $this->assertStringContainsString('row 0', AnsiUtils::stripAnsiCodes($rendered[0]));
    }

    public function testHorizontalScrollSnapsLeftward(): void
    {
        $long = str_repeat('x', times: 200);
        $editor = $this->editor("<?php\n{$long}\n");
        $doc = $editor->getDocument();
        $doc->setCursor(1, 150);
        $editor->render(new RenderContext(40, 5)); // hScroll follows right

        $doc->setCursor(1, 2);
        $rendered = $editor->render(new RenderContext(40, 5)); // hScroll snaps back left
        foreach ($rendered as $line) {
            $this->assertSame(40, AnsiUtils::visibleWidth($line));
        }
        $this->assertStringContainsString("\x1b_pi:c;", implode('', $rendered));
    }

    public function testBracketScanGivesUpAtCapAndOnUnmatched(): void
    {
        // Unmatched opening brace with > 20k characters of scan distance
        $filler = str_repeat(str_repeat('x', times: 100)."\n", times: 250);
        $editor = $this->editor("{\n".$filler);
        $editor->getDocument()->setCursor(0, 0);
        $rendered = $editor->render(new RenderContext(120, 5));
        $this->assertSame(0, substr_count($rendered[0], needle: "\x1b[1m\x1b[7m"), 'no highlight for unmatched bracket');

        // No bracket near the cursor at all
        $editor2 = $this->editor("plain text\n");
        $editor2->getDocument()->setCursor(0, 5);
        $editor2->render(new RenderContext(40, 3));

        // Closing bracket scans backwards
        $editor3 = $this->editor("(a)\n");
        $editor3->getDocument()->setCursor(0, 3); // just after ')'
        $rendered = $editor3->render(new RenderContext(40, 3));
        $this->assertSame(2, substr_count($rendered[0], needle: "\x1b[1m\x1b[7m"));
    }

    public function testDiagnosticSpanningToLineEnd(): void
    {
        $editor = $this->editor("<?php\nbroken line here\n");
        $editor->setDiagnostics([1 => [['severity' => 2, 'message' => 'multi-line issue', 'col' => 2, 'endCol' => \PHP_INT_MAX]]]);

        $rendered = $editor->render(new RenderContext(60, 5));
        $this->assertStringContainsString("\x1b[4:5m\x1b[58;5;179m", $rendered[1], 'dashed amber underline to line end');
        $this->assertSame(1, $editor->getDiagnosticCounts()['warnings']);
        $this->assertSame(60, AnsiUtils::visibleWidth($rendered[1]));
    }

    public function testCompletionPopupClampedInTinyViewport(): void
    {
        $editor = $this->editor("<?php\nst\n");
        $editor->getDocument()->setCursor(1, 2);
        $items = [];
        for ($i = 0; $i < 6; ++$i) {
            $items[] = new CompletionItem(label: "opt{$i}", insertText: 'x', kind: 'fn', detail: '');
        }
        $editor->showCompletions($items);

        $rendered = $editor->render(new RenderContext(30, 3)); // popup taller than viewport
        foreach ($rendered as $line) {
            $this->assertSame(30, AnsiUtils::visibleWidth($line));
        }
    }

    public function testListOverlayTallerThanViewportIsClipped(): void
    {
        $editor = $this->editor();
        $items = [];
        for ($i = 0; $i < 20; ++$i) {
            $items[] = ['label' => "entry{$i}", 'hint' => '', 'value' => (string) $i];
        }
        $editor->openListOverlay('Big', $items, static fn () => null);

        $rendered = $editor->render(new RenderContext(50, 4)); // 4 rows can't fit title+items
        $this->assertCount(4, $rendered);
        foreach ($rendered as $line) {
            $this->assertSame(50, AnsiUtils::visibleWidth($line));
        }

        // Single-row viewport: even the title+first-item pair overflows
        $rendered = $editor->render(new RenderContext(50, 1));
        $this->assertCount(1, $rendered);
        $this->assertSame(50, AnsiUtils::visibleWidth($rendered[0]));
    }

    public function testCompletionPopupWithNoFilteredItemsRendersNothing(): void
    {
        $editor = $this->editor("<?php\nst\n");
        $editor->getDocument()->setCursor(1, 2);
        $editor->showCompletions([new CompletionItem(label: 'strlen', insertText: 'strlen', kind: 'fn', detail: '')]);

        // Mutate the document directly (bypassing handleInput) so the popup
        // is still open but the filter word no longer matches anything.
        $editor->getDocument()->insert('zzz');

        $rendered = $editor->render(new RenderContext(60, 8));
        $this->assertStringNotContainsString('strlen', AnsiUtils::stripAnsiCodes(implode("\n", $rendered)));
    }

    public function testInlineDiagnosticRendersAndCanBeDisabled(): void
    {
        $editor = $this->editor("<?php\n\$x = 1;\n");
        $editor->setDiagnostics([1 => [['severity' => 1, 'message' => 'undefined variable', 'col' => 0, 'endCol' => 2]]]);

        $rendered = $editor->render(new RenderContext(80, 5));
        $this->assertStringContainsString('undefined variable', AnsiUtils::stripAnsiCodes($rendered[1]));
        $this->assertSame(80, AnsiUtils::visibleWidth($rendered[1]));

        $editor->disableInlineDiagnostics();
        $rendered = $editor->render(new RenderContext(80, 5));
        $this->assertStringNotContainsString('undefined variable', AnsiUtils::stripAnsiCodes($rendered[1]));
    }

    public function testDiagnosticAndRelativeNumberTogglesAreIdempotentBothWays(): void
    {
        $editor = $this->editor("<?php\n\$x = 1;\n");
        $editor->setDiagnostics([1 => [['severity' => 1, 'message' => 'undefined variable', 'col' => 0, 'endCol' => 2]]]);

        // inlineDiagnostics defaults on: disable (set), disable again (skip), enable (set), enable again (skip).
        $editor->disableInlineDiagnostics();
        $this->assertStringNotContainsString('undefined variable', AnsiUtils::stripAnsiCodes(implode("\n", $editor->render(new RenderContext(80, 5)))));
        $editor->disableInlineDiagnostics();
        $editor->enableInlineDiagnostics();
        $this->assertStringContainsString('undefined variable', AnsiUtils::stripAnsiCodes(implode("\n", $editor->render(new RenderContext(80, 5)))));
        $editor->enableInlineDiagnostics();
        $this->assertStringContainsString('undefined variable', AnsiUtils::stripAnsiCodes(implode("\n", $editor->render(new RenderContext(80, 5)))));

        // lineNumbers defaults to absolute: change to relative, set relative again (no-op), then absolute, then off.
        $editor->setLineNumberMode(LineNumberMode::Relative);
        $this->assertSame(LineNumberMode::Relative, EditorState::display($editor)->lineNumbers());
        $editor->setLineNumberMode(LineNumberMode::Relative); // same → guard skips
        $this->assertSame(LineNumberMode::Relative, EditorState::display($editor)->lineNumbers());
        $editor->setLineNumberMode(LineNumberMode::Off);
        $this->assertSame(LineNumberMode::Off, EditorState::display($editor)->lineNumbers());

        // Sticky scroll and indent guides default on: disable (set), disable again (skip), enable (set), enable again (skip).
        foreach ([
            [$editor->disableStickyScroll(...), $editor->enableStickyScroll(...), static fn (): bool => EditorState::display($editor)->stickyScroll()],
            [$editor->disableIndentGuides(...), $editor->enableIndentGuides(...), static fn (): bool => EditorState::display($editor)->indentGuides()],
        ] as [$off, $on, $has]) {
            $off();
            $this->assertFalse($has());
            $off(); // skip
            $this->assertFalse($has());
            $on();
            $this->assertTrue($has());
            $on(); // skip
            $this->assertTrue($has());
        }
    }

    public function testInlineDiagnosticPlacedByTabExpandedWidth(): void
    {
        // A tab-indented line renders tab-expanded, so the message must start
        // after the *display* width of the code, not its byte/character width
        // (which would overprint the code's tail).
        $editor = $this->editor("<?php\n\t\t\$x = 1;\n");
        $editor->setDiagnostics([1 => [['severity' => 1, 'message' => 'undefined variable', 'col' => 0]]]);

        $rendered = $editor->render(new RenderContext(80, 5));
        $plain = AnsiUtils::stripAnsiCodes($rendered[1]);
        $this->assertStringContainsString('$x = 1;', $plain, 'the code itself is intact');
        $this->assertStringContainsString('undefined variable', $plain);
        // Two-space gap after the tab-expanded text (2 tabs à 4 cells + code).
        $this->assertStringContainsString('$x = 1;  ‹', $plain);
        $this->assertSame(80, AnsiUtils::visibleWidth($rendered[1]));
    }

    public function testInlineDiagnosticPlacedAfterInlayHints(): void
    {
        // Inlay hints widen the visible line past the raw doc line's width, so
        // the message must start after the hints, not slice into them — the
        // config-file overprint bug (dense LSP hints + diagnostics drawing the
        // message on top of the code/hints).
        $hint = ': SomeType';
        $message = 'undefined variable';
        $editor = $this->editor("<?php\n\$x = 1;\n");
        $editor->setInlayHints([1 => [['col' => 7, 'text' => $hint]]]);
        $editor->setDiagnostics([1 => [['severity' => 1, 'message' => $message, 'col' => 0]]]);

        $plain = AnsiUtils::stripAnsiCodes($editor->render(new RenderContext(80, 5))[1]);
        $this->assertStringContainsString('$x = 1;', $plain, 'the code itself is intact');
        $this->assertStringContainsString($hint, $plain, 'the inlay hint is not overprinted by the message');
        // The hint precedes the message rather than being sliced by it.
        $hintPos = (int) strpos($plain, $hint);
        $messagePos = (int) strpos($plain, $message);
        $this->assertLessThan($messagePos, $hintPos, 'the inlay hint stays left of the diagnostic message');
    }

    public function testInlineDiagnosticSkippedWhenNoRoom(): void
    {
        // A long line leaves no trailing room for the message.
        $long = str_repeat('x', times: 70);
        $editor = $this->editor("<?php\n{$long}\n");
        $editor->getDocument()->setCursor(1, 0);
        $editor->setDiagnostics([1 => [['severity' => 2, 'message' => 'a warning here', 'col' => 0]]]);

        $rendered = $editor->render(new RenderContext(40, 5));
        $this->assertStringNotContainsString('a warning here', AnsiUtils::stripAnsiCodes($rendered[1]));
        $this->assertSame(40, AnsiUtils::visibleWidth($rendered[1]));
    }

    public function testInlineDiagnosticWithEmptyMessageRendersNothingExtra(): void
    {
        $editor = $this->editor("<?php\n\$x = 1;\n");
        $editor->setDiagnostics([1 => [['severity' => 1, 'message' => '', 'col' => 0]]]);

        $rendered = $editor->render(new RenderContext(80, 5));
        // Still an exact-width line, just no message text appended.
        $this->assertSame(80, AnsiUtils::visibleWidth($rendered[1]));
        $this->assertStringContainsString('$x = 1;', AnsiUtils::stripAnsiCodes($rendered[1]));
    }

    public function testPeekCallbackFires(): void
    {
        $editor = $this->editor("<?php\n\$x;\n");
        $peekedAt = null;
        $editor->setCallbacks(new EditorCallbacks(
            navigation: new FakeEditorNavigation(
                requestPeek: static function (int $l, int $c) use (&$peekedAt): void {
                    $peekedAt = [$l, $c];
                },
            ),
        ));
        $editor->getDocument()->setCursor(1, 1);

        $editor->handleInput("\x1b[105;6u"); // ctrl+shift+i
        $this->assertSame([1, 1], $peekedAt);
    }

    public function testGitGutterMarksRenderForEveryChangeKind(): void
    {
        $editor = $this->editor("<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n");
        $editor->setVcsMarks([0 => ChangeMarkKind::Modified, 1 => ChangeMarkKind::Added, 2 => ChangeMarkKind::Deleted]);

        $rendered = $editor->render(new RenderContext(60, 6));
        $this->assertStringContainsString("\x1b[38;5;75m┆", $rendered[0], 'modified: blue dashed bar');
        $this->assertStringContainsString("\x1b[38;5;114m▎", $rendered[1], 'added: green solid bar');
        $this->assertStringContainsString("\x1b[38;5;203m▁", $rendered[2], 'deleted: red underline');
        // Unmarked line and the exact-width contract both hold
        $this->assertStringNotContainsString('▎', $rendered[3]);
        $this->assertStringNotContainsString('┆', $rendered[3]);
        foreach ($rendered as $line) {
            $this->assertSame(60, AnsiUtils::visibleWidth($line));
        }
    }

    public function testSignatureHintOnFirstLineRendersBelow(): void
    {
        $editor = $this->editor("f(x\nmore\n");
        $editor->getDocument()->setCursor(0, 3);
        $editor->setSignatureHint('f(int $x): void');

        $rendered = $editor->render(new RenderContext(60, 4));
        // The framed hint flips below the caret: its top border on row 1, the
        // hint text railed on row 2.
        $this->assertStringContainsString('f(int $x): void', AnsiUtils::stripAnsiCodes($rendered[2]), 'hint below cursor when on row 0');
    }

    public function testSignatureHintOnATinyPaneDegradesToTheBareRow(): void
    {
        // Two rows: neither three-row framed slot fits (above is negative,
        // below overflows), so the hint degrades to the unframed single row.
        $editor = $this->editor("f(x\nmore\n");
        $editor->getDocument()->setCursor(0, 3);
        $editor->setSignatureHint('f(int $x): void');

        $rendered = $editor->render(new RenderContext(60, 2));
        $this->assertStringContainsString('f(int $x): void', AnsiUtils::stripAnsiCodes($rendered[1]), 'the bare hint row below the caret');
        $this->assertStringNotContainsString('╭', AnsiUtils::stripAnsiCodes(implode('', $rendered)), 'no frame on a pane too small for one');
    }

    public function testSignatureHintWithSingleRowViewportIsDropped(): void
    {
        $editor = $this->editor("f(x\n");
        $editor->getDocument()->setCursor(0, 3);
        $editor->setSignatureHint('f(int $x): void');

        $rendered = $editor->render(new RenderContext(60, 1));
        $this->assertStringNotContainsString('f(int', AnsiUtils::stripAnsiCodes(implode('', $rendered)));
    }

    // --- showDocument: highlighter, caret hooks, closeList, invalidate --------

    public function testShowDocumentUsesTheProvidedHighlighterNotAFreshPlaceholder(): void
    {
        $hl = new CodeHighlighter();
        $hl->setFile('demo.js'); // pre-configures it to javascript; the widget must not touch it (only setFile()s an implicit one)
        $editor = new CodeEditorWidget();

        $editor->showDocument('demo.js', new Document('x'), highlighter: $hl);

        $this->assertSame('javascript', $editor->getLanguage(), 'the passed, already-configured highlighter must be used, not a fresh placeholder');
    }

    public function testShowDocumentBindsCaretHooksSoUndoRestoresExtraCaretsOnThisDocument(): void
    {
        $editor = $this->editor("<?php\nline1\nline2\nline3\n");
        $doc = $editor->getDocument();
        $mc = Probe::get($editor, 'multiCaret');
        $mc->toggleCaretAt(2, 0);
        $this->assertTrue($mc->hasMultiple(), 'sanity: extra caret added');

        $doc->insert('x'); // pushUndo() snapshots the pre-edit state, including the extra caret
        $mc->clear(); // simulate the extra caret being dropped by something outside undo tracking
        $this->assertFalse($mc->hasMultiple(), 'sanity: cleared');

        $doc->undo();

        $this->assertTrue($mc->hasMultiple(), 'undo must restore the extra caret — only possible if showDocument wired setCaretHooks for THIS MultiCaret');
    }

    public function testShowDocumentInvalidates(): void
    {
        $editor = new CodeEditorWidget();
        $before = $editor->getRenderRevision();

        $editor->showDocument('a.php', new Document('x'));

        $this->assertGreaterThan($before, $editor->getRenderRevision());
    }

    public function testShowDocumentClosesAnyOpenListOverlayFromThePreviousDocument(): void
    {
        $editor = $this->editor();
        $editor->openListOverlay('Recent', [['label' => 'a.php', 'hint' => '', 'value' => 'a.php']], static function (string $v): void {});
        $this->assertTrue($editor->isAnyPickerOpen(), 'sanity: picker open');

        $editor->showDocument('other.php', new Document('y'));

        $this->assertFalse($editor->isAnyPickerOpen(), 'a buffer switch must close a stale list overlay');
    }

    // --- Find-in-Files modal input pass-through --------------------------------

    public function testHandleFindModalInputFeedsTheOpenModal(): void
    {
        $editor = $this->editor();
        $editor->findModal()->open('demo');
        $this->assertTrue($editor->findModal()->isOpen(), 'sanity: modal open');

        $editor->handleFindModalInput('x');

        $this->assertSame('x', Probe::get($editor->findModal(), 'query')->value(), 'the keystroke must actually reach the modal');
    }

    // --- Display toggles --------------------------------------------------------

    public function testEnableVcsGutterTurnsItOn(): void
    {
        $editor = $this->editor();
        $display = Probe::get($editor, 'display');
        $editor->disableVcsGutter();
        $this->assertFalse($display->vcsGutter(), 'sanity: off after disabling');

        $editor->enableVcsGutter();

        $this->assertTrue($display->vcsGutter());
    }

    public function testSetLanguageIndentsInvalidates(): void
    {
        $editor = $this->editor();
        $before = $editor->getRenderRevision();

        $editor->setLanguageIndents([]);

        $this->assertGreaterThan($before, $editor->getRenderRevision());
    }

    // --- applyEffectiveIndent -----------------------------------------------

    public function testApplyEffectiveIndentSpacesArmOverridesAnEditorConfigThatSaysTabs(): void
    {
        $editor = $this->editor(path: 'demo.php');
        $editorConfig = \Porta\Config\EditorConfig\ResolvedEditorConfig::fromProps(['indent_style' => 'tab', 'indent_size' => '8']);
        $editor->showDocument('demo.php', new Document('a'), editorConfig: $editorConfig);

        // If the 'spaces' arm were removed, this would fall through to the
        // default arm and pick up the .editorconfig's usesTabs=true instead.
        $editor->setLanguageIndents(['php' => new \Porta\Config\LanguageIndent(indentStyle: 'spaces', indentSize: 2)]);

        $this->assertNotSame("\t", $editor->getDocument()->indentUnit(), 'explicit spaces must win over an editorconfig that says tabs');
    }

    public function testApplyEffectiveIndentSizeIsCastFromANumericStringConfigValue(): void
    {
        $editor = $this->editor(path: 'demo.php');
        // LanguageIndent's own type is int|string; the widget must (int) cast
        // a string value before handing it to Document::setIndent(int $size),
        // which is a hard int type under strict_types.
        $editor->setLanguageIndents(['php' => new \Porta\Config\LanguageIndent(indentStyle: 'spaces', indentSize: '5')]);

        $this->assertSame(5, $editor->getDocument()->indentSize());
    }

    // --- openPrompt ----------------------------------------------------------

    public function testOpenPromptDefaultsToNonDanger(): void
    {
        $editor = $this->editor();

        $editor->openPrompt('Go to line', '', static function (string $v): void {});

        $overlayHost = Probe::get($editor, 'overlayHost');
        $prompt = Probe::get($overlayHost, 'prompt');
        $this->assertFalse($prompt['danger'], 'openPrompt must default to a non-destructive (non-danger) styled prompt');
    }

    // --- setSignatureHint invalidate-on-change guard --------------------------

    public function testSetSignatureHintInvalidatesOnlyOnAnActualChange(): void
    {
        $editor = $this->editor();
        $editor->setSignatureHint('f(int $x)');
        $revAfterFirstSet = $editor->getRenderRevision();

        $editor->setSignatureHint('f(int $x)'); // same value: the guard should skip invalidate
        $this->assertSame($revAfterFirstSet, $editor->getRenderRevision(), 'no redundant invalidate on an unchanged hint');

        $editor->setSignatureHint('g(int $y)'); // different value: must invalidate
        $this->assertGreaterThan($revAfterFirstSet, $editor->getRenderRevision());
    }

    // --- scopes() ------------------------------------------------------------

    public function testScopesIsCallablePublicly(): void
    {
        $editor = $this->editor();
        // A plain external call — if this were narrowed to protected, this
        // line would be a fatal compile-time visibility error under mago
        // analyze and a runtime Error here.
        $this->assertIsArray($editor->scopes());
    }

    public function testScopesReturnsEmptyForANonPhpFileEvenWithPhpLikeContent(): void
    {
        // Content that WOULD scan non-empty if BreadcrumbScanner ran on it —
        // proves the non-.php guard actually short-circuits rather than just
        // coincidentally producing an empty scan result.
        $editor = $this->editor("<?php\nclass Demo\n{\n    public function foo(): void {}\n}\n", 'notes.txt');

        $this->assertSame([], $editor->scopes(), 'a non-.php file must never scan, regardless of content');
    }

    public function testScopesCacheIsKeyedByDocumentIdentityNotJustVersion(): void
    {
        $editor = $this->editor("<?php\nclass Alpha\n{\n    public function a(): void {}\n}\n");
        $first = $editor->scopes();
        $this->assertNotSame([], $first);

        // A brand-new document at the SAME (fresh) version but different
        // content/identity: if the cache key dropped spl_object_id, this
        // would wrongly return Alpha's stale cached scopes.
        $editor->showDocument('demo.php', new Document("<?php\nclass Beta\n{\n    public function b(): void {}\n}\n"));
        $second = $editor->scopes();

        $this->assertNotSame($first, $second, 'switching documents must recompute scopes even at the same version number');
        $this->assertSame('Beta', $second[0]['label'] ?? null);
    }

    public function testScopesCacheIsKeyedByVersionNotJustDocumentIdentity(): void
    {
        $editor = $this->editor("<?php\nclass Alpha\n{\n    public function a(): void {}\n}\n");
        $first = $editor->scopes();
        $this->assertNotSame([], $first);

        // Same document object, edited (version bumps) — must recompute.
        $editor->getDocument()->setCursor(1, 10);
        $editor->getDocument()->insert('X');
        $second = $editor->scopes();

        $this->assertNotSame($first, $second, 'editing the SAME document must invalidate the memoized scope scan');
    }

    public function testASameDocumentEditDebouncesTheRescanAndStaysStaleUntilItFires(): void
    {
        $scheduler = new FakeScheduler();
        $editor = new CodeEditorWidget(scheduler: $scheduler);
        $editor->openFile('demo.php', "<?php\nclass Alpha\n{\n}\n");

        $first = $editor->scopes();
        $this->assertSame(['Alpha'], array_column($first, 'label'), 'the first look scans in place, scheduler or not');
        $this->assertSame([], $scheduler->scheduled, 'a cache hit arms nothing');

        // Rename the class: the trail must stay stale (no per-keystroke
        // tokenizer pass), with one 150ms rescan armed.
        $editor->getDocument()->setCursor(1, 11);
        $editor->getDocument()->insert('X');
        $this->assertSame($first, $editor->scopes(), 'mid-typing the stale trail is served');
        $this->assertSame([0.15], array_values($scheduler->delays), 'one idle rescan armed');

        // Frames keep calling scopes() at the same version: no re-arm, or an
        // ambient repaint stream would push the rescan out forever.
        $editor->scopes();
        $this->assertCount(1, $scheduler->scheduled, 'a same-version call must not push the timer out');

        $scheduler->fire();
        $this->assertSame(['AlphaX'], array_column($editor->scopes(), 'label'), 'the settled rescan shows the edit');
        $this->assertSame([], $scheduler->scheduled, 'nothing left armed after settling');
    }

    public function testAFurtherEditReArmsInPlaceOfThePendingRescan(): void
    {
        $scheduler = new FakeScheduler();
        $editor = new CodeEditorWidget(scheduler: $scheduler);
        $editor->openFile('demo.php', "<?php\nclass Alpha\n{\n}\n");
        $editor->scopes();

        $editor->getDocument()->setCursor(1, 11);
        $editor->getDocument()->insert('X');
        $editor->scopes();
        $editor->getDocument()->insert('Y');
        $editor->scopes();

        $this->assertSame(['1'], $scheduler->cancelled, 'the first pending rescan was replaced, not stacked');
        $this->assertCount(1, $scheduler->scheduled);

        $scheduler->fire();
        $this->assertSame(['AlphaXY'], array_column($editor->scopes(), 'label'), 'the one rescan sees both edits');
    }

    public function testSwitchingDocumentsRescansInPlaceEvenWithAScheduler(): void
    {
        $scheduler = new FakeScheduler();
        $editor = new CodeEditorWidget(scheduler: $scheduler);
        $editor->openFile('demo.php', "<?php\nclass Alpha\n{\n}\n");
        $editor->scopes();

        // Another buffer must never show Alpha's trail for a debounce window.
        $editor->showDocument('other.php', new Document("<?php\nclass Beta\n{\n}\n"));
        $this->assertSame(['Beta'], array_column($editor->scopes(), 'label'), 'a document switch cannot serve a stale trail');
        $this->assertSame([], $scheduler->scheduled, 'the switch scanned synchronously');
    }

    // --- caretScreenCell / documentLineAt boundary math ------------------------

    public function testCaretScreenCellRowFloorsAtZeroNotNegativeOne(): void
    {
        $lines = "<?php\n";
        for ($i = 0; $i < 50; ++$i) {
            $lines .= "line {$i}\n";
        }
        $editor = $this->editor($lines);
        $editor->getDocument()->setCursor(30, 0);
        $editor->render(new RenderContext(80, 5)); // settles vScroll near line 30

        // Move the cursor ABOVE the settled vScroll without re-rendering:
        // screenRowFor() returns -1 (caret above the viewport). max(0,-1)=0;
        // a max(-1,...) mutant would leave the row at -1 instead.
        $editor->getDocument()->setCursor(0, 0);

        $this->assertSame(0, $editor->caretScreenCell()['row'], 'a caret scrolled above the viewport must clamp its screen row to 0');
    }

    public function testCaretScreenCellRowAddsStickyLineCountNotSubtractsIt(): void
    {
        // A deep class scope so at least one sticky header is pinned once
        // scrolled past its opening line.
        $lines = "<?php\nclass Demo\n{\n";
        for ($i = 0; $i < 40; ++$i) {
            $lines .= "    // padding {$i}\n";
        }
        $lines .= "    public function tail(): void {}\n}\n";
        $editor = $this->editor($lines);
        $editor->getDocument()->setCursor(38, 0);
        $editor->render(new RenderContext(80, 10)); // narrow viewport forces scroll, pinning the class header sticky

        $stickyCount = \count(Probe::get($editor, 'stickyLines'));
        $this->assertGreaterThan(0, $stickyCount, 'sanity: at least one sticky header pinned');

        $scroll = Probe::get($editor, 'scroll');
        $foldModel = Probe::get($editor, 'foldModel');
        $expectedBase = max(0, $foldModel->screenRowFor($scroll->vScroll(), 38));

        $cell = $editor->caretScreenCell();
        // Exact formula: max(0, screenRowFor(...)) + stickyCount. A
        // Plus-&gt;Minus mutant would produce expectedBase - stickyCount instead.
        $this->assertSame($expectedBase + $stickyCount, $cell['row']);
    }

    public function testDocumentLineAtClampsANegativeRelativeRowToZero(): void
    {
        $editor = $this->editor("<?php\nline1\nline2\n");
        $editor->render(new RenderContext(80, 10));

        // No sticky lines here, so relRow=-1 skips the early sticky-line
        // branch (relRow &gt;= 0 is false) and falls through to the
        // max(0, relRow - stickyCount) line with a genuinely negative input.
        $this->assertSame($editor->documentLineAt(0), $editor->documentLineAt(-1), 'a negative row must clamp to row 0, not go further negative');
    }

    public function testDocumentLineAtAtExactlyTheStickyCountFloorsAtZeroNotOne(): void
    {
        $lines = "<?php\nclass Demo\n{\n";
        for ($i = 0; $i < 40; ++$i) {
            $lines .= "    // padding {$i}\n";
        }
        $lines .= "    public function tail(): void {}\n}\n";
        $editor = $this->editor($lines);
        $editor->getDocument()->setCursor(38, 0);
        $editor->render(new RenderContext(80, 10));

        $stickyCount = \count(Probe::get($editor, 'stickyLines'));
        $this->assertGreaterThan(0, $stickyCount);

        $scroll = Probe::get($editor, 'scroll');
        $foldModel = Probe::get($editor, 'foldModel');
        $expected = $foldModel->docLineAtRow($scroll->vScroll(), 0);

        // relRow == stickyCount: not caught by the early sticky-line branch
        // (relRow &lt; stickyCount is false), so relRow-stickyCount == 0 exactly.
        // An IncrementInteger mutant (floor 1) would ask docLineAtRow for row 1
        // instead of row 0, a DecrementInteger mutant for row -1.
        $this->assertSame($expected, $editor->documentLineAt($stickyCount));
    }

    // --- centerOnLine (stripe click) -------------------------------------------

    public function testStripeClickCentersTheViewportOnTheClickedLine(): void
    {
        $lines = "<?php\n";
        for ($i = 0; $i < 100; ++$i) {
            $lines .= "line {$i}\n";
        }
        $editor = $this->editor($lines);
        $editor->render(new RenderContext(40, 10));
        $scroll = Probe::get($editor, 'scroll');
        $before = $scroll->vScroll();

        // The stripe is the rightmost rendered column.
        $editor->clickAt(5, 39);

        $this->assertNotSame($before, $scroll->vScroll(), 'a stripe click must actually move the viewport (centerOnLine wired through)');
    }

    // --- scrollHBy widest-visible-line scan boundary ---------------------------

    public function testScrollHByScansExactlyTheVisibleLinesNotOneShortOrOneOver(): void
    {
        // 3 visible content rows (lines 0-2): "<?php" (5), 20 a's, 50 b's — the
        // widest visible line is 50. A 4th, off-screen line (200 c's) must
        // NOT be counted, and dropping the last visible line from the scan
        // must not either (both would change the exact clamp target).
        $editor = $this->editor("<?php\n".str_repeat('a', times: 20)."\n".str_repeat('b', times: 50)."\n".str_repeat('c', times: 200)."\n");
        $editor->render(new RenderContext(80, 3));

        $editor->scrollHBy(1000); // scroll far right; clamp caps at (widest VISIBLE line - 10)
        $scroll = Probe::get($editor, 'scroll');
        $this->assertSame(40, $scroll->hScroll(), 'the clamp must be driven by exactly the 3 visible lines (widest 50), not a truncated or over-extended scan');
    }

    public function testScrollHByLastVisibleLineBoundaryUsesLineCountNotContentRowsWhenTheDocumentIsShort(): void
    {
        // A short, 2-line document (no trailing newline, so the widest line
        // IS the document's true last line) in a tall viewport: the document
        // itself, not the viewport, is the binding constraint on the scan's
        // upper bound. An off-by-one that shortens the range excludes this
        // widest line entirely.
        $editor = $this->editor("<?php\n".str_repeat('x', times: 100));
        $editor->render(new RenderContext(80, 10));

        $editor->scrollHBy(1000);
        $scroll = Probe::get($editor, 'scroll');
        $this->assertSame(90, $scroll->hScroll(), 'the scan must reach the last real document line (widest 100), not stop one short of it');
    }

    // --- insertText / jumpTo side effects ---------------------------------------

    public function testInsertTextInvalidates(): void
    {
        $editor = $this->editor();
        $before = $editor->getRenderRevision();

        $editor->insertText('x');

        $this->assertGreaterThan($before, $editor->getRenderRevision());
    }

    public function testJumpToCentersScrollsAndClosesCompletion(): void
    {
        $lines = "<?php\n";
        for ($i = 0; $i < 100; ++$i) {
            $lines .= "line {$i}\n";
        }
        $editor = $this->editor($lines);
        $editor->render(new RenderContext(40, 10));
        $editor->showCompletions([new CompletionItem(label: 'foo', insertText: 'foo', kind: 'fn', detail: '')]);
        $this->assertTrue($editor->isCompletionOpen(), 'sanity: completion open');
        $scroll = Probe::get($editor, 'scroll');
        $before = $scroll->vScroll();
        $beforeRev = $editor->getRenderRevision();

        $editor->jumpTo(80, 0);

        $this->assertNotSame($before, $scroll->vScroll(), 'jumpTo must center the target line');
        $this->assertFalse($editor->isCompletionOpen(), 'jumpTo must close any open completion popup');
        $this->assertGreaterThan($beforeRev, $editor->getRenderRevision(), 'jumpTo must invalidate');
    }

    // --- clearExtraCarets / notifyChange pass-throughs -------------------------
    // (closeCompletion() itself is covered by testJumpToCentersScrollsAndClosesCompletion above.)

    public function testClearExtraCaretsActuallyClearsThem(): void
    {
        $editor = $this->editor("<?php\nline1\nline2\n");
        $mc = Probe::get($editor, 'multiCaret');
        $mc->toggleCaretAt(2, 0);
        $this->assertTrue($mc->hasMultiple());

        $editor->clearExtraCarets();

        $this->assertFalse($mc->hasMultiple());
    }

    public function testNotifyChangeInvalidatesAndSchedulesDidChange(): void
    {
        $editor = $this->editor();
        $before = $editor->getRenderRevision();
        $scheduled = false;
        $editor->setCallbacks(new EditorCallbacks(completion: new \Porta\Tests\Support\FakeEditorCompletion(
            scheduleDidChange: static function () use (&$scheduled): void {
                $scheduled = true;
            },
        )));

        $editor->notifyChange();

        $this->assertGreaterThan($before, $editor->getRenderRevision(), 'notifyChange must invalidate');
        $this->assertTrue($scheduled, 'notifyChange must schedule didChange');
    }

    // --- stickyHeadersAt boundary math ------------------------------------------

    public function testStickyHeadersAtUsesTheExactRowsMinusFourBudget(): void
    {
        // A deeply nested set of scopes so MAX_STICKY_ROWS would cap it if the
        // budget were larger than intended; here we want rows-4 itself to be
        // the binding constraint, distinguishing it from rows-3 or rows-5.
        $lines = "<?php\n";
        for ($depth = 0; $depth < 6; ++$depth) {
            $lines .= str_repeat('    ', $depth)."function f{$depth}()\n".str_repeat('    ', $depth)."{\n";
        }
        for ($depth = 5; $depth >= 0; --$depth) {
            $lines .= str_repeat('    ', $depth)."}\n";
        }
        $editor = $this->editor($lines);
        // Deep inside the innermost scope, well past every opening line.
        $doc = $editor->getDocument();
        $innerLine = null;
        for ($i = 0; $i < $doc->getLineCount(); ++$i) {
            if (!str_contains($doc->getLine($i), 'function f5')) {
                continue;
            }
            $innerLine = $i + 1;

            break;
        }
        $this->assertNotNull($innerLine);
        $doc->setCursor($innerLine, 0);

        foreach ([7, 6] as $rows) {
            $editor->render(new RenderContext(80, $rows));
            $scroll = Probe::get($editor, 'scroll');
            $foldModel = Probe::get($editor, 'foldModel');
            $top = $foldModel->docLineAtRow($scroll->vScroll(), 0) ?? 0;
            $budget = \Porta\Support\IntMath::clamp($rows - 4, 0, \Porta\Editor\EditorScroll::MAX_STICKY_ROWS);
            $expected = \Porta\Editor\BreadcrumbScanner::stickyHeaders($editor->scopes(), $top, $budget);

            $actual = Probe::get($editor, 'stickyLines');
            $this->assertSame(array_map(static fn (array $h): int => $h['line'], $expected), $actual, "rows={$rows}");
        }
    }

    // --- matchingBrackets memoization key ---------------------------------------

    public function testMatchingBracketsRecomputesOnCursorLineChangeNotJustColumn(): void
    {
        $editor = $this->editor("<?php\nfunction a() {}\nfunction b() {}\n");
        $editor->setFocused(true);
        $editor->getDocument()->setCursor(1, 13); // inside a()'s braces
        $editor->render(new RenderContext(80, 10));
        $first = Probe::get($editor, 'bracketsMemo');

        $editor->getDocument()->setCursor(2, 13); // same column, different line, inside b()'s braces
        $editor->render(new RenderContext(80, 10));
        $second = Probe::get($editor, 'bracketsMemo');

        $this->assertNotSame($first, $second, 'the memo key must include the cursor LINE, not just column and version');
    }

    public function testMatchingBracketsRecomputesOnVersionChangeAtTheSameCursorPosition(): void
    {
        $editor = $this->editor("<?php\nfunction a() {}\n");
        $editor->setFocused(true);
        $editor->getDocument()->setCursor(1, 13);
        $editor->render(new RenderContext(80, 10));
        $first = Probe::get($editor, 'bracketsMemo');

        $editor->getDocument()->insert(' '); // bumps version; cursor moves to col 14 though — restore it
        $editor->getDocument()->setCursor(1, 13);
        $editor->render(new RenderContext(80, 10));
        $second = Probe::get($editor, 'bracketsMemo');

        $this->assertNotSame($first, $second, 'an edit must invalidate the memo even at the same (line, col)');
    }

    // --- render(): rows floor, empty-state gutter reset, contentRows, lastVisibleDocLine ---

    public function testRenderRowsWithNoRequestedRowsFallsBackToLineCountCappedAtThirty(): void
    {
        // A 50-line document, getRows()=0: rows = max(1, 0&gt;0 ? 0 : min(30,50)).
        // A GreaterThan mutant (&gt;=0) would take the getRows()=0 branch directly,
        // collapsing this to 1; min(29,...)/min(31,...) mutants would collapse
        // the cap to 29 or 31 instead of exactly 30.
        $lines = "<?php\n";
        for ($i = 0; $i < 49; ++$i) {
            $lines .= "line {$i}\n";
        }
        $editor = $this->editor($lines);

        $rendered = $editor->render(new RenderContext(80, 0));

        $this->assertCount(30, $rendered);
    }

    public function testRenderResetsGutterWidthToZeroNotNegativeOneWhenNoFileIsOpen(): void
    {
        $editor = new CodeEditorWidget();
        $editor->render(new RenderContext(80, 10)); // no file open

        $this->assertSame(0, $editor->gutterWidth());
    }

    public function testRenderSetsContentRowsOnTheScroll(): void
    {
        $editor = $this->editor("<?php\nline1\nline2\n");
        $editor->render(new RenderContext(80, 10));

        $this->assertSame($editor->contentRows(), Probe::get($editor, 'scroll')->contentRows());
        $this->assertGreaterThan(0, $editor->contentRows(), 'setContentRows must actually have run, not left the default');
    }

    public function testRenderLastVisibleDocLineFallsBackToLineCountMinusOneAtEndOfDocument(): void
    {
        $editor = $this->editor("<?php\nline1\nline2\nline3\n");
        // A tall viewport that shows the whole (short) document: docLineAtRow
        // for the last content row returns null (past the end), so the ??
        // fallback (lineCount - 1) is exercised.
        $rendered = $editor->render(new RenderContext(80, 50));

        // The bottom rows beyond the document must render as blank gutter
        // rows, not throw / not include a bogus diagnostic for a
        // past-the-end line — a structural smoke check on the fallback path.
        $this->assertCount(50, $rendered);
    }

    // --- computeGeometry boundaries ----------------------------------------

    public function testComputeGeometryNumberWidthIsZeroWhenLineNumbersAreOff(): void
    {
        $editor = $this->editor("<?php\n");
        $editor->setLineNumberMode(LineNumberMode::Off);
        $editor->disableVcsGutter();

        $editor->render(new RenderContext(80, 5));

        // With line numbers off and vcs gutter off, gutterWidth is exactly
        // numberWidth(0) + 2 = 2. An IncrementInteger mutant (numberWidth=1)
        // would make it 3.
        $this->assertSame(2, $editor->gutterWidth());
    }

    public function testComputeGeometryGutterWidthIncludesTheVcsColumnWhenEnabled(): void
    {
        $editor = $this->editor("<?php\n");
        $editor->setLineNumberMode(LineNumberMode::Off);
        $editor->disableVcsGutter();
        $editor->render(new RenderContext(80, 5));
        $without = $editor->gutterWidth();

        $editor->enableVcsGutter();
        $editor->render(new RenderContext(80, 5));
        $with = $editor->gutterWidth();

        $this->assertSame($without + 1, $with, 'the vcs gutter column must add exactly 1, not 0 or 2');
    }

    public function testComputeGeometryContentWidthFloorsAtOneNotZeroOrTwo(): void
    {
        $editor = $this->editor("<?php\n");
        // A pathologically narrow pane so columns - gutterWidth - 1 would go
        // non-positive without the floor. contentWidth itself isn't exposed,
        // but it's exactly the difference between lastStripeCol and
        // gutterWidth (EditorGeometry's stripeCol = gutterWidth + contentWidth).
        $rendered = $editor->render(new RenderContext(1, 5));
        $this->assertGreaterThanOrEqual(1, \count($rendered));

        $contentWidth = Probe::get($editor, 'lastStripeCol') - $editor->gutterWidth();
        $this->assertSame(1, $contentWidth);
    }

    // --- followCaret inlay shift direction --------------------------------------

    public function testFollowCaretAddsTheInlayShiftNotSubtractsIt(): void
    {
        $editor = $this->editor("<?php\n\$x = foo();\n");
        $editor->getDocument()->setCursor(1, 11); // just after "foo()"
        $editor->setInlayHints([1 => [['col' => 11, 'text' => ': int']]]);

        $editor->render(new RenderContext(80, 10));

        // With the shift added, the caret's followed display column sits
        // past the raw column by the inlay hint's width; with it subtracted,
        // it would sit BEFORE the raw column instead. Assert via the
        // horizontal scroll needed to keep a caret placed right at the
        // narrow edge in view.
        $narrow = $this->editor("<?php\n\$x = foo();\n");
        $narrow->getDocument()->setCursor(1, 11);
        $narrow->setInlayHints([1 => [['col' => 11, 'text' => ': int']]]);
        $narrow->render(new RenderContext(15, 10));
        $scroll = Probe::get($narrow, 'scroll');

        $this->assertGreaterThan(0, $scroll->hScroll(), 'the inlay hint widens the followed column, forcing a scroll in a narrow pane');
    }

    // --- renderEmptyState centering math -----------------------------------

    public function testRenderEmptyStateCentersTheHintHorizontallyFlooringAtZero(): void
    {
        $editor = new CodeEditorWidget();
        $translator = new \Porta\I18n\Translator('en');
        $hint = $translator->trans('No file open · press Ctrl+P to open a file');
        $text = AnsiText::truncate($hint, 200);
        $textWidth = AnsiUtils::visibleWidth($text);
        $expectedLeft = max(0, intdiv(200 - $textWidth, num2: 2));

        $rendered = $editor->render(new RenderContext(200, 5));
        $hintRow = null;
        foreach ($rendered as $row) {
            $plain = AnsiUtils::stripAnsiCodes($row);
            if (str_contains($plain, 'No file open')) {
                $hintRow = $plain;
                break;
            }
        }
        $this->assertNotNull($hintRow);
        $leadingSpaces = \strlen($hintRow) - \strlen(ltrim($hintRow, characters: ' '));
        // Exact leading-space count pins the divisor (2, not 1 or 3) and the
        // floor (0, not -1 — unreachable here since truncate() guarantees
        // textWidth &lt;= columns, but pinned for documentation).
        $this->assertSame($expectedLeft, $leadingSpaces);
    }

    public function testRenderEmptyStatePutsTheHintOnTheExactMiddleRow(): void
    {
        $editor = new CodeEditorWidget();

        $rendered = $editor->render(new RenderContext(80, 5)); // middle = intdiv(5,2) = 2
        $middleHasHint = str_contains(AnsiUtils::stripAnsiCodes($rendered[2]), 'No file open');
        $otherRowsBlank =
            !str_contains(AnsiUtils::stripAnsiCodes($rendered[0]), 'No file open')
            && !str_contains(AnsiUtils::stripAnsiCodes($rendered[1]), 'No file open')
            && !str_contains(AnsiUtils::stripAnsiCodes($rendered[3]), 'No file open')
            && !str_contains(AnsiUtils::stripAnsiCodes($rendered[4]), 'No file open');

        $this->assertTrue($middleHasHint, 'the hint must land on row intdiv(rows,2), row 2 of 5');
        $this->assertTrue($otherRowsBlank, 'every other row must be blank, not the hint (rules out the ternary being flipped)');
    }
}
