<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Config\IndentStyle;
use Porta\Config\LanguageIndent;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\CompletionItem;
use Porta\Editor\CoverageMark;
use Porta\Editor\DiagnosticMessageMode;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\Document;
use Porta\Editor\InlayHint;
use Porta\Editor\LineNumberMode;
use Porta\Editor\PromptTone;
use Porta\Highlight\CodeHighlighter;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\FakeEditorCompletion;
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
        $this->assertSame(0, $editor->getDocument()->cursorLine);
        $this->assertFalse($editor->getDocument()->hasSelection());

        $editor->handleInput("\x1b[1;5F"); // ctrl+end
        $this->assertSame(3, $editor->getDocument()->cursorLine);

        $editor->handleInput("\x1b[5~"); // page up
        $this->assertSame(0, $editor->getDocument()->cursorLine);
        $editor->handleInput("\x1b[6~"); // page down
        $this->assertGreaterThan(0, $editor->getDocument()->cursorLine);
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
        $rendered = \implode("\n", $editor->render(new RenderContext(80, 10)));
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

        $plain = AnsiUtils::stripAnsiCodes(\implode("\n", $editor->render(new RenderContext(80, 10))));
        $this->assertStringContainsString('getMessage', $plain);
        $this->assertStringContainsString('genericmoment', $plain);
        $this->assertLessThan(
            \strpos($plain, needle: 'genericmoment'),
            \strpos($plain, needle: 'getMessage'),
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

    private const string CTRL_DOT = "\x1b[46;5u"; // toggle_fold

    private function foldFixture(): CodeEditorWidget
    {
        $editor = $this->editor("<?php\nfunction f() {\n    a();\n    b();\n}\nafter();\n");
        $editor->setFocused(true);

        return $editor;
    }

    /** @return string[] visible screen rows, ANSI stripped */
    private function screenRows(CodeEditorWidget $editor, int $w = 60, int $h = 10): array
    {
        return \array_map(
            static fn (string $r): string => \rtrim(AnsiUtils::stripAnsiCodes($r)),
            $editor->render(new RenderContext($w, $h)),
        );
    }

    public function testFoldCollapsesRegionAndUnfolds(): void
    {
        $editor = $this->foldFixture();
        $editor->getDocument()->setCursor(1, 0); // on "function f() {"

        $editor->handleInput(self::CTRL_DOT); // fold
        $joined = \implode("\n", $this->screenRows($editor));
        $this->assertStringContainsString('⋯', $joined, 'fold marker shown');
        $this->assertStringNotContainsString('a();', $joined, 'interior hidden');
        $this->assertStringContainsString('after();', $joined, 'code after the fold still visible');

        $editor->handleInput(self::CTRL_DOT); // unfold
        $joined = \implode("\n", $this->screenRows($editor));
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
        $this->assertSame(5, $editor->getDocument()->cursorLine);
    }

    public function testMovingIntoFoldedRegionAutoUnfolds(): void
    {
        $editor = $this->foldFixture();
        $editor->getDocument()->setCursor(1, 0);
        $editor->handleInput(self::CTRL_DOT);
        $this->assertStringNotContainsString('a();', \implode("\n", $this->screenRows($editor)));

        // Placing the caret inside the collapsed region reveals it on render.
        $editor->getDocument()->setCursor(3, 0);
        $this->assertStringContainsString('a();', \implode("\n", $this->screenRows($editor)));
    }

    public function testToggleFoldWithNoRegionDoesNothing(): void
    {
        $editor = $this->foldFixture();
        $editor->getDocument()->setCursor(0, 0); // "<?php" — no enclosing block
        $editor->handleInput(self::CTRL_DOT);
        $joined = \implode("\n", $this->screenRows($editor));
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
        $this->assertStringContainsString('line15();', \implode("\n", $rows), 'caret line is scrolled into view');
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
        $joined = \implode("\n", $rows);
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
        $plain = AnsiUtils::stripAnsiCodes(\implode("\n", $editor->render(new RenderContext(80, 20))));
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
        // A printable key is no longer ignored — it filters. `q` matches
        // neither row, and the surface stays open saying so rather than
        // picking something on the next Enter.
        $editor->handleInput('q');
        $editor->handleInput("\r");
        $this->assertNull($picked, 'Enter over an empty result picks nothing');

        $editor->handleInput("\x1b"); // Esc clears the filter, keeping the list
        $this->assertTrue(EditorState::pickerOpen($editor));
        $editor->handleInput("\r");
        $this->assertSame('1', $picked);

        // Empty overlay refuses to open
        $editor->openListOverlay('Empty', [], static fn (): null => null);
        $this->assertFalse(EditorState::pickerOpen($editor));
    }

    public function testSignatureHintClearedOnCloseParenAndVerticalMove(): void
    {
        $editor = $this->editor("<?php\nf(\n");
        $editor->getDocument()->setCursor(1, 2);
        $editor->setSignatureHint('f(int $x)');

        $editor->handleInput(')');
        $rendered = AnsiUtils::stripAnsiCodes(\implode("\n", $editor->render(new RenderContext(80, 10))));
        $this->assertStringNotContainsString('f(int $x)', $rendered, 'closing paren clears the hint');

        $editor->setSignatureHint('f(int $x)');
        $editor->handleInput("\x1b[A"); // cursor up clears
        $rendered = AnsiUtils::stripAnsiCodes(\implode("\n", $editor->render(new RenderContext(80, 10))));
        $this->assertStringNotContainsString('f(int $x)', $rendered);
    }

    public function testSignatureHintSkippedWhenNoRoom(): void
    {
        $editor = $this->editor("a\n");
        $editor->setSignatureHint('very long signature text');

        // Tiny width: hint area (columns - gutter - 1) < 4 -> skipped
        $lines = $editor->render(new RenderContext(9, 3));
        $this->assertStringNotContainsString('very', AnsiUtils::stripAnsiCodes(\implode("\n", $lines)));
    }

    public function testCommentPrefixVariants(): void
    {
        $cases = [
            ['script.sh', 'echo hi',    '# echo hi'],
            ['query.sql', 'SELECT 1;',  '-- SELECT 1;'],
            // Twig's only comment form is a block, so the LINE verb wraps the
            // line in it. This case read `no change` for as long as the prefix
            // was a bare string with nothing to say but `//`, `#` or `--`.
            ['page.twig', '{{ x }}',    '{# {{ x }} #}'],
            // Same for CSS — and `//` was not merely missing here, it was
            // WRONG: it comments out nothing and breaks the next rule.
            ['theme.css', 'a { b: c }', '/* a { b: c } */'],
            ['page.html', '<p>hi</p>',  '<!-- <p>hi</p> -->'],
            ['notes.xyz', 'plain',      'plain'], // unknown language: no change
        ];
        foreach ($cases as [$file, $before, $after]) {
            $editor = $this->editor($before."\n", $file);
            $editor->getDocument()->setCursor(0, 0);
            $editor->handleInput("\x1f"); // toggle_comment
            $this->assertSame($after, $editor->getDocument()->getLine(0), $file);
        }
    }

    /**
     * The chord, through a running widget. `Ctrl+/` reaching the document was
     * never in doubt; `Ctrl+Shift+/` is a NEW binding on the editor's own
     * keymap channel, and round 6's lesson was that a verb can be correct at
     * both ends while nothing drives the key to it.
     *
     * CSI-u, because a modified `/` is not something a terminal reports
     * without the Kitty protocol — the same reason the doc warns about
     * `Alt+Shift+↑`.
     */
    public function testCtrlShiftSlashReachesTheBlockCommentVerb(): void
    {
        $editor = $this->editor("    return 1;\n", 'demo.php');
        $editor->getDocument()->setCursor(0, 8);

        $editor->handleInput("\x1b[47;6u"); // ctrl+shift+/
        $this->assertSame('    /* return 1; */', $editor->getDocument()->getLine(0));

        $editor->handleInput("\x1b[47;6u");
        $this->assertSame('    return 1;', $editor->getDocument()->getLine(0), 'the same chord takes it off again');
    }

    /**
     * And the fallback, at the same seam: Python has no block form, so the
     * block chord line-comments rather than doing nothing.
     */
    public function testCtrlShiftSlashFallsBackToTheLineFormWhereThereIsNoBlock(): void
    {
        $editor = $this->editor("print(1)\n", 'demo.py');
        $editor->getDocument()->setCursor(0, 0);

        $editor->handleInput("\x1b[47;6u");
        $this->assertSame('# print(1)', $editor->getDocument()->getLine(0));
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
        $lines = \implode("\n", \array_map(static fn (int $i): string => "row {$i}", \range(0, end: 99)));
        $editor = $this->editor($lines);
        $editor->getDocument()->setCursor(80, 0);
        $editor->render(new RenderContext(40, 10)); // scrolls down

        $editor->getDocument()->setCursor(0, 0);
        $rendered = $editor->render(new RenderContext(40, 10)); // vScroll snaps back up
        $this->assertStringContainsString('row 0', AnsiUtils::stripAnsiCodes($rendered[0]));
    }

    public function testHorizontalScrollSnapsLeftward(): void
    {
        $long = \str_repeat('x', times: 200);
        $editor = $this->editor("<?php\n{$long}\n");
        $doc = $editor->getDocument();
        $doc->setCursor(1, 150);
        $editor->render(new RenderContext(40, 5)); // hScroll follows right

        $doc->setCursor(1, 2);
        $rendered = $editor->render(new RenderContext(40, 5)); // hScroll snaps back left
        foreach ($rendered as $line) {
            $this->assertSame(40, AnsiUtils::visibleWidth($line));
        }
        $this->assertStringContainsString("\x1b_pi:c;", \implode('', $rendered));
    }

    public function testBracketScanGivesUpAtCapAndOnUnmatched(): void
    {
        // Unmatched opening brace with > 20k characters of scan distance
        $filler = \str_repeat(\str_repeat('x', times: 100)."\n", times: 250);
        $editor = $this->editor("{\n".$filler);
        $editor->getDocument()->setCursor(0, 0);
        $rendered = $editor->render(new RenderContext(120, 5));
        $this->assertSame(0, \substr_count($rendered[0], needle: "\x1b[1m\x1b[7m"), 'no highlight for unmatched bracket');

        // No bracket near the cursor at all
        $editor2 = $this->editor("plain text\n");
        $editor2->getDocument()->setCursor(0, 5);
        $editor2->render(new RenderContext(40, 3));

        // Closing bracket scans backwards
        $editor3 = $this->editor("(a)\n");
        $editor3->getDocument()->setCursor(0, 3); // just after ')'
        $rendered = $editor3->render(new RenderContext(40, 3));
        $this->assertSame(2, \substr_count($rendered[0], needle: "\x1b[1m\x1b[7m"));
    }

    public function testDiagnosticSpanningToLineEnd(): void
    {
        $editor = $this->editor("<?php\nbroken line here\n");
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'multi-line issue', 'col' => 2, 'endCol' => \PHP_INT_MAX]]]);

        $rendered = $editor->render(new RenderContext(60, 5));
        $this->assertStringContainsString("\x1b[4:5m\x1b[58;5;208m", $rendered[1], 'dashed orange underline to line end');
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
        $editor->openListOverlay('Big', $items, static fn (): null => null);

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
        $this->assertStringNotContainsString('strlen', AnsiUtils::stripAnsiCodes(\implode("\n", $rendered)));
    }

    public function testInlineDiagnosticRendersAndCanBeDisabled(): void
    {
        $editor = $this->editor("<?php\n\$x = 1;\n");
        $editor->display->setDiagnosticMessages(DiagnosticMessageMode::Inline); // the message after the code is what this is about
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'undefined variable', 'col' => 0, 'endCol' => 2]]]);

        $rendered = $editor->render(new RenderContext(80, 5));
        $this->assertStringContainsString('undefined variable', AnsiUtils::stripAnsiCodes($rendered[1]));
        $this->assertSame(80, AnsiUtils::visibleWidth($rendered[1]));

        $editor->display->setDiagnosticMessages(DiagnosticMessageMode::None);
        $rendered = $editor->render(new RenderContext(80, 5));
        $this->assertStringNotContainsString('undefined variable', AnsiUtils::stripAnsiCodes($rendered[1]));
    }

    public function testDiagnosticAndRelativeNumberTogglesAreIdempotentBothWays(): void
    {
        $editor = $this->editor("<?php\n\$x = 1;\n");
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'undefined variable', 'col' => 0, 'endCol' => 2]]]);

        // Diagnostic messages default to none: inline (set), inline again (skip), none (set), none again (skip).
        $editor->display->setDiagnosticMessages(DiagnosticMessageMode::Inline);
        $this->assertStringContainsString('undefined variable', AnsiUtils::stripAnsiCodes(\implode("\n", $editor->render(new RenderContext(80, 5)))));
        $editor->display->setDiagnosticMessages(DiagnosticMessageMode::Inline);
        $editor->display->setDiagnosticMessages(DiagnosticMessageMode::None);
        $this->assertStringNotContainsString('undefined variable', AnsiUtils::stripAnsiCodes(\implode("\n", $editor->render(new RenderContext(80, 5)))));
        $editor->display->setDiagnosticMessages(DiagnosticMessageMode::None);
        $this->assertStringNotContainsString('undefined variable', AnsiUtils::stripAnsiCodes(\implode("\n", $editor->render(new RenderContext(80, 5)))));

        // lineNumbers defaults to absolute: change to relative, set relative again (no-op), then absolute, then off.
        $editor->display->setLineNumbers(LineNumberMode::Relative);
        $this->assertSame(LineNumberMode::Relative, $editor->display->lineNumbers);
        $editor->display->setLineNumbers(LineNumberMode::Relative); // same → guard skips
        $this->assertSame(LineNumberMode::Relative, $editor->display->lineNumbers);
        $editor->display->setLineNumbers(LineNumberMode::Off);
        $this->assertSame(LineNumberMode::Off, $editor->display->lineNumbers);

        // Sticky scroll and indent guides default on: disable (set), disable again (skip), enable (set), enable again (skip).
        foreach ([
            [
                static fn (): bool => $editor->display->setStickyScroll(false),
                static fn (): bool => $editor->display->setStickyScroll(true),
                static fn (): bool => $editor->display->stickyScroll,
            ],
            [
                static fn (): bool => $editor->display->setIndentGuides(false),
                static fn (): bool => $editor->display->setIndentGuides(true),
                static fn (): bool => $editor->display->indentGuides,
            ],
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
        $editor->display->setDiagnosticMessages(DiagnosticMessageMode::Inline); // the message after the code is what this is about
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'undefined variable', 'col' => 0]]]);

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
        $editor->display->setDiagnosticMessages(DiagnosticMessageMode::Inline); // the message after the code is what this is about
        $editor->decorations->setInlayHints([1 => [new InlayHint(7, $hint)]]);
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => $message, 'col' => 0]]]);

        $plain = AnsiUtils::stripAnsiCodes($editor->render(new RenderContext(80, 5))[1]);
        $this->assertStringContainsString('$x = 1;', $plain, 'the code itself is intact');
        $this->assertStringContainsString($hint, $plain, 'the inlay hint is not overprinted by the message');
        // The hint precedes the message rather than being sliced by it.
        $hintPos = (int) \strpos($plain, $hint);
        $messagePos = (int) \strpos($plain, $message);
        $this->assertLessThan($messagePos, $hintPos, 'the inlay hint stays left of the diagnostic message');
    }

    public function testInlineDiagnosticSkippedWhenNoRoom(): void
    {
        // A long line leaves no trailing room for the message.
        $long = \str_repeat('x', times: 70);
        $editor = $this->editor("<?php\n{$long}\n");
        $editor->display->setDiagnosticMessages(DiagnosticMessageMode::Inline); // the message after the code is what this is about
        $editor->getDocument()->setCursor(1, 0);
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'a warning here', 'col' => 0]]]);

        $rendered = $editor->render(new RenderContext(40, 5));
        $this->assertStringNotContainsString('a warning here', AnsiUtils::stripAnsiCodes($rendered[1]));
        $this->assertSame(40, AnsiUtils::visibleWidth($rendered[1]));
    }

    public function testInlineDiagnosticWithEmptyMessageRendersNothingExtra(): void
    {
        $editor = $this->editor("<?php\n\$x = 1;\n");
        $editor->display->setDiagnosticMessages(DiagnosticMessageMode::Inline); // the message after the code is what this is about
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => '', 'col' => 0]]]);

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
        $editor->decorations->setVcsMarks([0 => ChangeMarkKind::Modified, 1 => ChangeMarkKind::Added, 2 => ChangeMarkKind::Deleted]);

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

    /**
     * Coverage takes the strip's place while a file has any: a bar per line
     * the run saw, nothing on a line it did not report, and no git mark
     * through it — the strip is coverage's until it is cleared.
     */
    public function testCoverageTakesTheChangeStripsPlace(): void
    {
        $editor = $this->editor("<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n");
        $editor->decorations->setVcsMarks([0 => ChangeMarkKind::Modified, 3 => ChangeMarkKind::Added]);
        $editor->decorations->setCoverage([1 => CoverageMark::Covered, 2 => CoverageMark::Uncovered]);

        $rendered = $editor->render(new RenderContext(60, 6));
        $this->assertSame(' ', \mb_substr(AnsiUtils::stripAnsiCodes($rendered[0]), start: 0, length: 1), 'a git mark does not show through');
        $this->assertStringContainsString("\x1b[38;5;114m▎", $rendered[1], 'covered: the green bar');
        $this->assertStringContainsString("\x1b[38;5;203m▌", $rendered[2], 'never ran: the heavier red one');
        $this->assertSame(' ', \mb_substr(AnsiUtils::stripAnsiCodes($rendered[3]), start: 0, length: 1), 'a line the run did not report shows nothing');
        $this->assertFalse($editor->changeMarkAt(0, 3), 'and a click there opens no change it cannot see');
        foreach ($rendered as $line) {
            $this->assertSame(60, AnsiUtils::visibleWidth($line));
        }

        $editor->decorations->setCoverage([]);
        $rendered = $editor->render(new RenderContext(60, 6));
        $this->assertStringContainsString("\x1b[38;5;75m┆", $rendered[0], 'cleared, the strip is the change marks\' again');
        $this->assertTrue($editor->changeMarkAt(0, 3));

        $editor->setVcsGutter(false);
        $editor->decorations->setCoverage([1 => CoverageMark::Covered]);
        $this->assertStringNotContainsString('▎', \implode('', $editor->render(new RenderContext(60, 6))), 'with no strip, nowhere to show it');
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
        $this->assertStringNotContainsString('╭', AnsiUtils::stripAnsiCodes(\implode('', $rendered)), 'no frame on a pane too small for one');
    }

    public function testSignatureHintWithSingleRowViewportIsDropped(): void
    {
        $editor = $this->editor("f(x\n");
        $editor->getDocument()->setCursor(0, 3);
        $editor->setSignatureHint('f(int $x): void');

        $rendered = $editor->render(new RenderContext(60, 1));
        $this->assertStringNotContainsString('f(int', AnsiUtils::stripAnsiCodes(\implode('', $rendered)));
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
        $editor->findModal->open('demo');
        $this->assertTrue($editor->findModal->isOpen(), 'sanity: modal open');

        $editor->handleFindModalInput('x');

        $this->assertSame('x', Probe::get($editor->findModal, 'query')->value(), 'the keystroke must actually reach the modal');
    }

    // --- Display toggles --------------------------------------------------------

    public function testEnableVcsGutterTurnsItOn(): void
    {
        $editor = $this->editor();
        $display = $editor->display;
        $editor->setVcsGutter(false);
        $this->assertFalse($display->vcsGutter, 'sanity: off after disabling');

        $editor->setVcsGutter(true);

        $this->assertTrue($display->vcsGutter);
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
        $editorConfig = ResolvedEditorConfig::fromProps(['indent_style' => 'tab', 'indent_size' => '8']);
        $editor->showDocument('demo.php', new Document('a'), editorConfig: $editorConfig);

        // If the 'spaces' arm were removed, this would fall through to the
        // default arm and pick up the .editorconfig's usesTabs=true instead.
        $editor->setLanguageIndents(['php' => new LanguageIndent(indentStyle: IndentStyle::Spaces, indentSize: 2)]);

        $this->assertNotSame("\t", $editor->getDocument()->indentUnit(), 'explicit spaces must win over an editorconfig that says tabs');
    }

    public function testApplyEffectiveIndentSizeIsCastFromANumericStringConfigValue(): void
    {
        $editor = $this->editor(path: 'demo.php');
        // LanguageIndent's own type is int|string; the widget must (int) cast
        // a string value before handing it to Document::setIndent(int $size),
        // which is a hard int type under strict_types.
        $editor->setLanguageIndents(['php' => new LanguageIndent(indentStyle: IndentStyle::Spaces, indentSize: '5')]);

        $this->assertSame(5, $editor->getDocument()->indentSize());
    }

    // --- openPrompt ----------------------------------------------------------

    public function testOpenPromptDefaultsToNonDanger(): void
    {
        $editor = $this->editor();

        $editor->openPrompt('Go to line', '', static function (string $v): void {});

        $overlayHost = Probe::get($editor, 'overlayHost');
        $prompt = Probe::get($overlayHost, 'prompt');
        $this->assertSame(PromptTone::Plain, $prompt['tone'], 'openPrompt must default to a non-destructive (non-danger) styled prompt');
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

    public function testAWheelScrollNotifiesTheCompletionHandler(): void
    {
        $editor = $this->editor(\str_repeat("line\n", times: 100));
        $scrolled = 0;
        $editor->setCallbacks(new EditorCallbacks(completion: new FakeEditorCompletion(
            viewportScrolled: static function () use (&$scrolled): void {
                ++$scrolled;
            },
        )));

        $editor->scrollBy(3);

        $this->assertSame(1, $scrolled, 'viewport-scoped LSP features hear about the detach-scroll');
    }

    public function testASameDocumentEditDebouncesTheRescanAndStaysStaleUntilItFires(): void
    {
        $scheduler = new FakeScheduler();
        $editor = new CodeEditorWidget(scheduler: $scheduler);
        $editor->openFile('demo.php', "<?php\nclass Alpha\n{\n}\n");

        $first = $editor->scopes();
        $this->assertSame(['Alpha'], \array_column($first, 'label'), 'the first look scans in place, scheduler or not');
        $this->assertSame([], $scheduler->scheduled, 'a cache hit arms nothing');

        // Rename the class: the trail must stay stale (no per-keystroke
        // tokenizer pass), with one 150ms rescan armed.
        $editor->getDocument()->setCursor(1, 11);
        $editor->getDocument()->insert('X');
        $this->assertSame($first, $editor->scopes(), 'mid-typing the stale trail is served');
        $this->assertSame([0.15], \array_values($scheduler->delays), 'one idle rescan armed');

        // Frames keep calling scopes() at the same version: no re-arm, or an
        // ambient repaint stream would push the rescan out forever.
        $editor->scopes();
        $this->assertCount(1, $scheduler->scheduled, 'a same-version call must not push the timer out');

        $scheduler->fire();
        $this->assertSame(['AlphaX'], \array_column($editor->scopes(), 'label'), 'the settled rescan shows the edit');
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
        $this->assertSame(['AlphaXY'], \array_column($editor->scopes(), 'label'), 'the one rescan sees both edits');
    }

    public function testSwitchingDocumentsRescansInPlaceEvenWithAScheduler(): void
    {
        $scheduler = new FakeScheduler();
        $editor = new CodeEditorWidget(scheduler: $scheduler);
        $editor->openFile('demo.php', "<?php\nclass Alpha\n{\n}\n");
        $editor->scopes();

        // Another buffer must never show Alpha's trail for a debounce window.
        $editor->showDocument('other.php', new Document("<?php\nclass Beta\n{\n}\n"));
        $this->assertSame(['Beta'], \array_column($editor->scopes(), 'label'), 'a document switch cannot serve a stale trail');
        $this->assertSame([], $scheduler->scheduled, 'the switch scanned synchronously');
    }

    // --- caretScreenCell / documentLineAt boundary math ------------------------
}
