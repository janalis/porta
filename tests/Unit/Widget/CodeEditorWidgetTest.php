<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\CompletionItem;
use Porta\Editor\DiagnosticMessageMode;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\Document;
use Porta\Editor\FindMode;
use Porta\Editor\LineNumberMode;
use Porta\Editor\OccurrenceHighlights;
use Porta\Editor\SelectionMode;
use Porta\Lsp\LspMapper;
use Porta\Tests\Support\FakeBufferNavigation;
use Porta\Tests\Support\FakeEditorCompletion;
use Porta\Tests\Support\FakeEditorFileActions;
use Porta\Tests\Support\FakeEditorNavigation;
use Porta\Tests\Support\FakeEditorRefactor;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersExactWidth;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Glyphs;
use Porta\Ui\Sgr;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorCallbacks;
use Porta\Widget\Render\LineDecorator;
use Porta\Workbench\Menu\ViewMenuSource;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

final class CodeEditorWidgetTest extends TestCase
{
    use RendersWidget;

    use RendersExactWidth;

    private function editor(string $content = "<?php\n\necho 'hi';\n", string $path = 'demo.php', ?OccurrenceHighlights $occurrences = null): CodeEditorWidget
    {
        $editor = new CodeEditorWidget(occurrences: $occurrences ?? new OccurrenceHighlights());
        $editor->openFile($path, $content);
        $editor->setFocused(true);

        return $editor;
    }

    public function testCaretScreenCellTracksCursorScrollAndGutter(): void
    {
        $editor = $this->editor("<?php\n\$a = 1;\n\$bb = 22;\n");
        $editor->render(new RenderContext(80, 10));

        $editor->getDocument()->setCursor(0, 0);
        $cell = $editor->caretScreenCell();
        $this->assertSame(0, $cell['row']);
        $this->assertSame($editor->gutterWidth(), $cell['col'], 'column 0 sits right after the gutter');

        $editor->getDocument()->setCursor(2, 4);
        $cell = $editor->caretScreenCell();
        $this->assertSame(2, $cell['row']);
        $this->assertSame($editor->gutterWidth() + 4, $cell['col']);
    }

    public function testCaretScreenCellClampsToTheGutterWhenScrolledPastTheCaret(): void
    {
        $long = "<?php\n\$x = '".\str_repeat('a', times: 120)."';\n";
        $editor = $this->editor($long);
        $editor->getDocument()->setCursor(1, 100);
        $editor->render(new RenderContext(40, 10)); // narrow: forces hScroll to follow the caret

        $editor->getDocument()->setCursor(1, 0); // move back without re-rendering
        $this->assertSame($editor->gutterWidth(), $editor->caretScreenCell()['col'], 'a caret left of the viewport clamps to the gutter edge');
    }

    public function testRenderContract(): void
    {
        $editor = $this->editor();
        $rendered = $editor->render(new RenderContext(80, 20));

        $this->assertCount(20, $rendered);
        $this->assertExactWidth($rendered, 80);
        $this->assertStringContainsString('1', AnsiUtils::stripAnsiCodes($rendered[0]), 'gutter shows line number');
        $this->assertStringContainsString("\x1b_pi:c;", $rendered[0], 'cursor marker present when focused');
    }

    private function stickyEditor(): CodeEditorWidget
    {
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n".\str_repeat("        \$x = 1;\n", times: 20)."    }\n}\n";

        return $this->editor($code, 'Service.php');
    }

    public function testStickyScrollPinsEnclosingHeaders(): void
    {
        $editor = $this->stickyEditor();
        $editor->getDocument()->setCursor(24, 8); // deep inside handle(), headers scrolled off
        $rendered = $editor->render(new RenderContext(60, 12));

        $this->assertExactWidth($rendered, 60);
        $top = AnsiUtils::stripAnsiCodes($rendered[0]);
        $second = AnsiUtils::stripAnsiCodes($rendered[1]);
        $this->assertStringContainsString('class Service', $top, 'class header pinned at row 0');
        $this->assertStringContainsString('function handle', $second, 'method header pinned at row 1');
        $this->assertStringContainsString("\x1b[48;5;237m", $rendered[0], 'pinned band background');
        $this->assertStringNotContainsString('namespace', $top.$second, 'the namespace is not pinned');
        $this->assertStringContainsString("\x1b_pi:c;", \implode('', $rendered), 'the caret is still visible below the band');
    }

    public function testStickyHeaderClickJumpsToDeclaration(): void
    {
        $editor = $this->stickyEditor();
        $editor->getDocument()->setCursor(24, 8);
        $editor->render(new RenderContext(60, 12)); // pins class@line4, handle@line6

        $editor->clickAt(0, 10); // click the pinned class header
        $this->assertSame(4, $editor->getDocument()->cursorLine, 'jumped to the class declaration');

        $editor->getDocument()->setCursor(24, 8);
        $editor->render(new RenderContext(60, 12));
        $editor->clickAt(1, 10); // click the pinned method header
        $this->assertSame(6, $editor->getDocument()->cursorLine, 'jumped to the method declaration');
    }

    public function testClickOnContentRowResolvesTheScrolledLine(): void
    {
        $editor = $this->stickyEditor();
        $editor->getDocument()->setCursor(24, 8);
        $editor->render(new RenderContext(60, 12)); // 2 sticky rows; content starts at row 2

        $editor->clickAt(2, 10); // first content row, below the pinned band
        $this->assertGreaterThan(6, $editor->getDocument()->cursorLine, 'a content-row click resolves a scrolled line, not a header');
    }

    public function testDisablingStickyScrollRemovesThePinnedBand(): void
    {
        $editor = $this->stickyEditor();
        $editor->display->setStickyScroll(false);
        $editor->getDocument()->setCursor(24, 8); // deep inside handle()
        $rendered = $editor->render(new RenderContext(60, 12));

        $top = AnsiUtils::stripAnsiCodes($rendered[0]);
        $this->assertStringNotContainsString('class Service', $top, 'the Interface setting off → no pinned header');
        $this->assertStringNotContainsString("\x1b[48;5;237m", $rendered[0], 'no pinned band background');
    }

    public function testLineNumbersOffHidesTheGutterNumbersButKeepsTheMarkerColumn(): void
    {
        $editor = $this->stickyEditor();
        $editor->getDocument()->setCursor(24, 8);

        $absolute = $editor->render(new RenderContext(60, 12));
        $this->assertMatchesRegularExpression('/^\s*\d+/', AnsiUtils::stripAnsiCodes($absolute[2]), 'a line number by default');

        $editor->display->setLineNumbers(LineNumberMode::Off);
        $rendered = $editor->render(new RenderContext(60, 12));

        $this->assertExactWidth($rendered, 60);
        $body = AnsiUtils::stripAnsiCodes($rendered[2]);
        $this->assertDoesNotMatchRegularExpression('/^\s*\d/', $body, 'no digit in the body gutter');
        $sticky = AnsiUtils::stripAnsiCodes($rendered[1]);
        $this->assertStringContainsString('function handle', $sticky, 'the sticky header still pins with the number gone');
        $this->assertDoesNotMatchRegularExpression('/^\s*\d/', $sticky, 'and shows no number');
        $this->assertStringContainsString("\x1b_pi:c;", \implode('', $rendered), 'the caret marker column survives');
    }

    public function testDisablingIndentGuidesRemovesTheGuides(): void
    {
        $editor = $this->editor("<?php\nif (true) {\n        deep();\n");
        $editor->display->setIndentGuides(false);
        $line2 = AnsiUtils::stripAnsiCodes($editor->render(new RenderContext(80, 10))[2]);

        $this->assertStringNotContainsString('│', $line2, 'the Interface setting off → no indent guides');
        $this->assertStringContainsString('deep();', $line2);
    }

    public function testShowWhitespaceTellsATabIndentFromASpaceIndent(): void
    {
        // The question the feature exists to answer, asked through the widget
        // so the setting has to reach the render path — and asked with indent
        // guides ON, which is the default and which takes the first cell of
        // every indent level. The arrow loses that cell; the PADDING is what
        // still distinguishes them, which is why a tab is drawn blank.
        $editor = $this->editor("<?php\n\t\$tab = 1;\n    \$spaces = 2;\n", 'mixed.php');

        $off = $editor->render(new RenderContext(80, 8));
        $editor->display->setShowWhitespace(true);
        $rows = $editor->render(new RenderContext(80, 8));

        $this->assertStringNotContainsString(
            Glyphs::WHITESPACE_SPACE,
            AnsiUtils::stripAnsiCodes($off[2]),
            'nothing is marked while the setting is off',
        );

        $tabbed = AnsiUtils::stripAnsiCodes($rows[1]);
        $spaced = AnsiUtils::stripAnsiCodes($rows[2]);
        $this->assertStringContainsString(Glyphs::INDENT_GUIDE.'   $tab = 1;', $tabbed, 'a tab pads with blank');
        $this->assertStringContainsString(Glyphs::INDENT_GUIDE.'···$spaces = 2;', $spaced, 'four spaces are four dots');

        // The rule this decoration lives or dies by.
        $this->assertExactWidth($rows, 80);
    }

    public function testShowWhitespaceMarksTRAILINGSpaceButNotInnerOnes(): void
    {
        $editor = $this->editor("<?php\n\$x = 1;   \n", 'trail.php');
        $editor->display->setShowWhitespace(true);

        $line = AnsiUtils::stripAnsiCodes($editor->render(new RenderContext(80, 8))[1]);

        $this->assertStringContainsString('$x = 1;···', $line, 'the junk after the semicolon shows');
        $this->assertStringNotContainsString('$x·=·1;', $line, 'the spaces between words do not');
    }

    public function testMethodSeparatorsRuleTheEndOfEachMemberWhenTheSettingIsOn(): void
    {
        // Through the widget, not the collaborator: the setting has to reach
        // the render path, which is the half that unit-testing the line source
        // can never show.
        $source = "<?php\n\nclass A\n{\n    public function one(): void\n    {\n    }\n\n    public function two(): void\n    {\n    }\n}\n";
        $editor = $this->editor($source, 'members.php');

        $off = $editor->render(new RenderContext(80, 14));
        $editor->display->setMethodSeparators(true);
        $on = $editor->render(new RenderContext(80, 14));

        // one() closes on document line 6, two() on line 10.
        $this->assertStringContainsString(Sgr::UNDERLINE, $on[6], 'one() is closed off by a rule');
        $this->assertStringContainsString(Sgr::UNDERLINE, $on[10], 'and so is two()');
        $this->assertStringNotContainsString(Sgr::UNDERLINE, $off[6], 'the setting off → no rule');

        // The blank line between the two members is NOT the ruled one: the
        // rule sits under the closing brace, the way a graphical editor draws
        // a bottom border, so it clears two()'s docblock space.
        $this->assertStringNotContainsString(Sgr::UNDERLINE, $on[7], 'the blank line between members is untouched');
        $this->assertStringNotContainsString(Sgr::UNDERLINE, $on[2], 'and so is the class declaration');

        $this->assertExactWidth($on, 80);
    }

    /**
     * The crash in `.porta/error.log`, twenty-one times: `RenderException:
     * rendered line 0 with width 8, exceeding the available 1 columns`.
     *
     * A RenderException escapes the render loop, so this is not a cosmetic
     * fault — it takes the IDE down. The tree, the tab bar and the status bar
     * all render exactly one column when given one; the editor did not.
     */
    public function testTheEditorNeverRendersWIDERThanItsPane(): void
    {
        // Both gutter inputs are present: the vcs strip and a line count wide
        // enough to push the number field past its floor of three. A fixture
        // with neither would pass a clamp that only half works.
        $editor = $this->editor("<?php\n".\str_repeat("// a line\n", times: 120), 'narrow.php');
        $editor->display->setVcsGutter(true);

        foreach (\range(start: 1, end: 20) as $columns) {
            $rows = $editor->render(new RenderContext($columns, 6));
            $this->assertExactWidth($rows, $columns, "at {$columns} columns");
        }
    }

    public function testTheWidthClampHoldsForEVERYLineNumberMode(): void
    {
        // `Off` collapses the number column to zero, which is a different
        // arithmetic path through the gutter width than Absolute or Relative.
        $editor = $this->editor("<?php\n".\str_repeat("// a line\n", times: 120), 'narrow.php');

        foreach ([LineNumberMode::Off, LineNumberMode::Absolute, LineNumberMode::Relative] as $mode) {
            $editor->display->setLineNumbers($mode);
            foreach ([1, 2, 3, 6, 9, 11] as $columns) {
                $rows = $editor->render(new RenderContext($columns, 4));
                $this->assertExactWidth($rows, $columns, "{$mode->name} at {$columns} columns");
            }
        }
    }

    public function testEveryDecorationATONCEKeepsTheRowsExact(): void
    {
        // Whitespace marks, member separators, soft wrap and indent guides all
        // paint into the same row, and each was added on its own. Garble — a
        // row whose visible width is not the pane's — is what a decoration
        // that miscounts produces, and a wide character is where miscounting
        // shows first.
        $long = \str_repeat('y', times: 60);
        $editor = $this->editor(
            "<?php\n\nclass A\n{\n    public function one(): void\n    {\n\t\$s = \"日本語{$long}\";   \n    }\n}\n",
            'everything.php',
        );
        $editor->display->setShowWhitespace(true);
        $editor->display->setMethodSeparators(true);
        $editor->display->setSoftWrap(true);

        $rows = $editor->render(new RenderContext(46, 14));

        $this->assertExactWidth($rows, 46);
        foreach ($rows as $index => $row) {
            $this->assertTrue(
                \mb_check_encoding(AnsiUtils::stripAnsiCodes($row), encoding: 'UTF-8'),
                "row {$index} is still valid UTF-8",
            );
        }

        // And the rule is still one rule, on the member's closing line.
        $ruled = \array_keys(\array_filter($rows, static fn (string $row): bool => \str_contains($row, Sgr::UNDERLINE)));
        $this->assertCount(1, $ruled);
        $this->assertStringContainsString('}', AnsiUtils::stripAnsiCodes($rows[$ruled[0]]));
    }

    public function testAWrappedMembersRuleIsDrawnONCE_UnderItsLastRow(): void
    {
        // The defect this pins: spans are in the LINE's display columns, and a
        // rule anchored at column 0 was inherited by every row a soft-wrapped
        // line occupied — three stacked underlines instead of one rule closing
        // off the member. Only reachable with wrap ON and a member whose last
        // line is long enough to wrap, which is why every gate passed it.
        $long = \str_repeat('y', times: 70);
        $editor = $this->editor(
            "<?php\n\nclass A\n{\n    public function one(): void { \$x = \"{$long}\"; }\n}\n",
            'wrapped.php',
        );
        $editor->display->setMethodSeparators(true);
        $editor->display->setSoftWrap(true);

        $rows = $editor->render(new RenderContext(50, 12));
        $ruled = \array_keys(\array_filter($rows, static fn (string $row): bool => \str_contains($row, Sgr::UNDERLINE)));

        $this->assertCount(1, $ruled, 'one rule, however many rows the member takes');
        $this->assertStringContainsString('"; }', AnsiUtils::stripAnsiCodes($rows[$ruled[0]]), 'and it is the row the member ENDS on');
        $this->assertExactWidth($rows, 50);
    }

    public function testAPinnedHeadersGutterHasTheSameCOLUMNSAsAnOrdinaryRow(): void
    {
        // `renderStickyRow()` builds its own gutter and its comment claims the
        // columns line up with an ordinary row's, "including the fold marker".
        // Nothing asserted it, so the fold cell and the trailing pad could swap
        // — same width, marker one column out, and the pinned band no longer
        // reading as the lines below it.
        $editor = $this->editor(
            "<?php\n\nclass A\n{\n    public function one(): void\n    {\n".\str_repeat("        \$x = 1;\n", times: 30)."    }\n}\n",
            'sticky.php',
        );
        // Server-shaped fold regions, which start at the DECLARATION rather
        // than at the brace — that is what makes a pinned header a fold start,
        // and it is the configuration the comment's claim is about. With the
        // offline brace scan the fold column of a header is blank, and the
        // column order cannot be observed at all.
        $editor->setFoldRegions([[2, 38], [4, 37]]);
        $editor->getDocument()->setCursor(25, 4); // deep inside one(): its header pins

        $rows = $editor->render(new RenderContext(60, 10));
        $pinned = AnsiUtils::stripAnsiCodes($rows[0]);
        $gutterWidth = $editor->gutterWidth();

        $this->assertStringContainsString('class A', $pinned, 'the class header is the pinned row');
        $this->assertContains(
            \mb_substr($pinned, $gutterWidth - 2, length: 1),
            [Glyphs::EXPANDED, Glyphs::COLLAPSED],
            'the fold marker sits in the fold column, one before the pad',
        );
        $this->assertSame(' ', \mb_substr($pinned, $gutterWidth - 1, length: 1), 'and the gutter ends on its pad');
    }

    public function testNoStickyRowsForStructurelessFile(): void
    {
        $editor = $this->editor("<?php\n".\str_repeat("echo 1;\n", times: 30), 'flat.php');
        $editor->getDocument()->setCursor(25, 0);
        $rendered = $editor->render(new RenderContext(60, 12));

        $this->assertStringNotContainsString("\x1b[48;5;237m", $rendered[0], 'no scopes → no pinned band');
    }

    public function testNoFileOpenShowsCenteredHintNotAnEmptyFile(): void
    {
        $editor = new CodeEditorWidget(); // no file opened → filePath is null
        $editor->setFocused(true);

        $rendered = $editor->render(new RenderContext(80, 20));
        $this->assertCount(20, $rendered);
        $this->assertExactWidth($rendered, 80);

        $plain = AnsiUtils::stripAnsiCodes(\implode("\n", $rendered));
        $this->assertStringContainsString('No file open', $plain, 'placeholder hint is shown');
        $this->assertStringContainsString('Ctrl+Shift+N', $plain, 'the primary Go to File chord, as the menu and the keymap page print it');

        // And it FOLLOWS the map rather than spelling a chord out: the one hint
        // a new user reads must not point at a key they have rebound.
        $editor->setKeybindings(new Keybindings(['goto_file' => ['f4']]));
        $rebound = AnsiUtils::stripAnsiCodes(\implode("\n", $editor->render(new RenderContext(80, 20))));
        $this->assertStringContainsString('press F4 to open a file', $rebound);

        // And with the action unbound entirely it names the action instead of
        // printing an empty chord — the state a keymap file can leave behind.
        // An explicit empty list, which is what a keymap file naming the
        // action with no keys leaves behind — the merge replaces per action.
        $editor->setKeybindings(new Keybindings(['goto_file' => []]));
        $unbound = AnsiUtils::stripAnsiCodes(\implode("\n", $editor->render(new RenderContext(80, 20))));
        $this->assertStringContainsString('use Go to File to open one', $unbound);
        $joined = \implode('', $rendered);
        $this->assertStringNotContainsString("\x1b_pi:c;", $joined, 'no cursor marker when no file is open');

        // Opening a file restores the normal document render (gutter + cursor).
        $editor->openFile('demo.php', "<?php\n");
        $withFile = $editor->render(new RenderContext(80, 20));
        $this->assertStringContainsString("\x1b_pi:c;", $withFile[0], 'cursor returns once a file is open');
        $this->assertStringNotContainsString('No file open', AnsiUtils::stripAnsiCodes(\implode("\n", $withFile)));
    }

    public function testMenuOverlayCompositesOverEditorRows(): void
    {
        $editor = $this->editor();
        $editor->setMenuOverlay([
            ['row' => 0, 'col' => 3, 'width' => 10, 'segment' => "\x1b[7m Paste    \x1b[0m"],
            ['row' => 2, 'col' => 3, 'width' => 10, 'segment' => "\x1b[7m Find     \x1b[0m"],
        ]);

        $rendered = $this->painted($editor, new RenderContext(80, 20));

        $this->assertCount(20, $rendered);
        $this->assertExactWidth($rendered, 80);
        $plain = AnsiUtils::stripAnsiCodes(\implode("\n", $rendered));
        $this->assertStringContainsString('Paste', $plain, 'dropdown item floats over the editor');
        $this->assertStringContainsString('Find', $plain);
    }

    public function testEmptyMenuOverlayLeavesEditorUnchanged(): void
    {
        $editor = $this->editor();
        $baseline = $editor->render(new RenderContext(80, 20));

        $editor->setMenuOverlay([]);
        $this->assertSame($baseline, $editor->render(new RenderContext(80, 20)), 'no overlay → identical output');
    }

    public function testTypingAndBackspace(): void
    {
        $editor = $this->editor();

        $editor->handleInput('X');
        $this->assertStringStartsWith('X<?php', $editor->getDocument()->getLine(0));

        $editor->handleInput("\x7f"); // backspace
        $this->assertStringStartsWith('<?php', $editor->getDocument()->getLine(0));
    }

    public function testArrowMovement(): void
    {
        $editor = $this->editor();
        $editor->handleInput("\x1b[B"); // down

        $this->assertSame(1, $editor->getDocument()->cursorLine);
    }

    public function testCompletionPopupLifecycle(): void
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 5);
        $editor->showCompletions([
            new CompletionItem(label: 'echoFirst', insertText: 'echoFirst', kind: 'fn', detail: ''),
            new CompletionItem(label: 'echoSecond', insertText: 'echoSecond', kind: 'fn', detail: ''),
        ]);

        $this->assertTrue($editor->isCompletionOpen());

        $rendered = $editor->render(new RenderContext(80, 20));
        $this->assertStringContainsString('echoFirst', \implode("\n", $rendered));
        $this->assertExactWidth($rendered, 80);

        $editor->handleInput("\x1b[B"); // select second
        $editor->handleInput("\r"); // accept

        $this->assertStringContainsString('echoSecond', $editor->getDocument()->getLine(2));
        $this->assertFalse($editor->isCompletionOpen());
    }

    public function testCompletionAppliesInlineAdditionalTextEdits(): void
    {
        // <?php / (blank) / echo 'hi'; — complete on the last line, import lands on line 1.
        $editor = $this->editor();
        $doc = $editor->getDocument();
        $doc->setCursor(2, 4); // after "echo"

        $editor->showCompletions([new CompletionItem(
            label: 'str_replace',
            insertText: 'str_replace',
            kind: 'fn',
            detail: '',
            additionalTextEdits: [[
                'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
                'newText' => "use function str_replace;\n",
            ]],
        )]);
        $editor->handleInput("\r"); // accept

        $this->assertSame('use function str_replace;', $doc->getLine(1), 'auto-import inserted above');
        $this->assertStringContainsString('str_replace', $doc->getLine(3), 'completion applied at caret');
        $this->assertFalse($editor->isCompletionOpen());

        // The whole accept (insert + import) is a single undo step.
        $editor->handleInput("\x1a"); // ctrl+z
        $this->assertStringNotContainsString('use function str_replace;', $doc->getText());
    }

    public function testCompletionWithoutInlineEditsRequestsResolve(): void
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(2, 4);

        $resolved = null;
        $editor->setCallbacks(new EditorCallbacks(
            completion: new FakeEditorCompletion(
                resolveCompletion: static function (array $item) use (&$resolved): void {
                    $resolved = $item;
                },
            ),
        ));

        $editor->showCompletions([new CompletionItem(
            label: 'strlen',
            insertText: 'strlen',
            kind: 'fn',
            detail: '',
            raw: ['label' => 'strlen', 'data' => 'strlen'],
        )]);
        $editor->handleInput("\r"); // accept

        $this->assertNotNull($resolved, 'resolve callback fired for deferred imports');
        $this->assertSame('strlen', $resolved['data']);
    }

    public function testCompletionEscapeCloses(): void
    {
        $editor = $this->editor();
        $editor->showCompletions([new CompletionItem(label: 'foo', insertText: 'foo', kind: 'fn', detail: '')]);
        $this->assertTrue($editor->isCompletionOpen());

        $editor->handleInput("\x1b");
        $this->assertFalse($editor->isCompletionOpen());
    }

    public function testIdentifierOccurrencesHighlighted(): void
    {
        $editor = $this->editor("<?php\n\$total = 1;\necho \$total;\n");
        $doc = $editor->getDocument();
        $doc->setCursor(1, 3); // inside $total on line 1

        $rendered = $editor->render(new RenderContext(80, 10));
        // Both occurrences of $total pick up the occurrence background.
        $this->assertStringContainsString("\x1b[48;5;23m", $rendered[1]);
        $this->assertStringContainsString("\x1b[48;5;23m", $rendered[2]);

        // A selection suppresses the highlight.
        $doc->setCursor(1, 1);
        $doc->beginSelect();
        $doc->setCursor(1, 6, selection: SelectionMode::Keep);
        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringNotContainsString("\x1b[48;5;23m", $rendered[2]);

        // Dropping the selection brings the highlight back WITHOUT a caret
        // move — the occurrence memo must key on the selection state, or this
        // frame would serve the suppressed (empty) result forever.
        $doc->clearSelection();
        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringContainsString("\x1b[48;5;23m", $rendered[2], 'clearing the selection restores the occurrence shade');

        // Same for focus: blur suppresses, refocus restores, caret unmoved.
        $editor->setFocused(false);
        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringNotContainsString("\x1b[48;5;23m", $rendered[2], 'a blurred pane lights nothing');
        $editor->setFocused(true);
        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringContainsString("\x1b[48;5;23m", $rendered[2], 'refocusing restores the occurrence shade');
    }

    /**
     * The server's answer wins over the textual scan, and its WRITE spans paint
     * with the write role — the visible half of the feature.
     */
    public function testAServerAnswerReplacesTheTextualScanAndColoursWritesApart(): void
    {
        $occurrences = new OccurrenceHighlights();
        $editor = $this->editor("<?php\n\$total = 1;\necho \$total;\n", occurrences: $occurrences);
        $doc = $editor->getDocument();
        $doc->setCursor(1, 3);

        $occurrences->publish('demo.php', $doc->version, 1, $doc->getCursorColUtf16(), [
            [1, 0, 6, true], // the assignment: a write
            [2, 5, 11, false], // the use: a read
        ]);
        $rendered = $editor->render(new RenderContext(80, 10));

        $this->assertStringContainsString("\x1b[48;5;52m", $rendered[1], 'the assignment wears the write background');
        $this->assertStringContainsString("\x1b[48;5;23m", $rendered[2], 'the use wears the read background');
        $this->assertStringNotContainsString("\x1b[48;5;52m", $rendered[2], 'a read must not wear the write colour');
    }

    /**
     * An answer that arrives after the caret has moved on describes a different
     * symbol; painting it would light up the wrong identifier. The stamp makes
     * that impossible, and the scan simply keeps showing.
     */
    public function testAnAnswerStampedForAnotherCaretIsNeverPainted(): void
    {
        $occurrences = new OccurrenceHighlights();
        $editor = $this->editor("<?php\n\$total = 1;\necho \$total;\n", occurrences: $occurrences);
        $doc = $editor->getDocument();
        $doc->setCursor(1, 3);

        // Stamped for a caret that is NOT where the caret is now.
        $occurrences->publish('demo.php', $doc->version, 2, 6, [[1, 0, 6, true]]);
        $rendered = $editor->render(new RenderContext(80, 10));

        $this->assertStringNotContainsString("\x1b[48;5;52m", $rendered[1], 'a stale answer is dropped, not painted');
        $this->assertStringContainsString("\x1b[48;5;23m", $rendered[1], 'and the textual scan keeps showing meanwhile');
    }

    /** Likewise an answer for the document as it was BEFORE an edit. */
    public function testAnAnswerStampedForAnOlderDocumentVersionIsNeverPainted(): void
    {
        $occurrences = new OccurrenceHighlights();
        $editor = $this->editor("<?php\n\$total = 1;\necho \$total;\n", occurrences: $occurrences);
        $doc = $editor->getDocument();
        $doc->setCursor(1, 3);

        $occurrences->publish('demo.php', $doc->version - 1, 1, $doc->getCursorColUtf16(), [[1, 0, 6, true]]);
        $rendered = $editor->render(new RenderContext(80, 10));

        $this->assertStringNotContainsString("\x1b[48;5;52m", $rendered[1]);
    }

    /**
     * An EMPTY server answer is still an answer: this symbol occurs once here.
     * Falling back to the scan would re-light same-named symbols from other
     * scopes, which is the wrongness the server is being asked to fix.
     */
    public function testAnEmptyServerAnswerSuppressesTheScanRatherThanFallingBackToIt(): void
    {
        $occurrences = new OccurrenceHighlights();
        $editor = $this->editor("<?php\n\$total = 1;\necho \$total;\n", occurrences: $occurrences);
        $doc = $editor->getDocument();
        $doc->setCursor(1, 3);

        $occurrences->publish('demo.php', $doc->version, 1, $doc->getCursorColUtf16(), []);
        $rendered = $editor->render(new RenderContext(80, 10));

        $this->assertStringNotContainsString("\x1b[48;5;23m", $rendered[2], 'the scan must not override the server saying "no other occurrences"');
    }

    /**
     * A project's own templates reach the editor through the completion handler,
     * which is the only thing here that can see the config — a bare widget falls
     * back to the built-in set.
     */
    public function testAProjectTemplateFromTheHandlerExpandsInsteadOfTheBuiltIn(): void
    {
        $editor = $this->editor("<?php\n", path: 'demo.php');
        $editor->setCallbacks(new EditorCallbacks(completion: new FakeEditorCompletion(
            templatesFor: static fn (string $language): array => ['zz' => 'PROJECT_TEMPLATE(${1:x});$0'],
        )));
        $editor->getDocument()->setCursor(1, 0);
        $editor->handleInput('zz');

        $editor->handleInput("\t");

        $this->assertStringContainsString('PROJECT_TEMPLATE(x);', $editor->getDocument()->getText());
    }

    public function testTabExpandsALiveTemplateIntoATabStopSession(): void
    {
        $editor = $this->editor("<?php\n", path: 'demo.php');
        $editor->getDocument()->setCursor(1, 0);
        $editor->handleInput('fore');

        $editor->handleInput("\t");

        $text = $editor->getDocument()->getText();
        $this->assertStringContainsString('foreach ($items as $item) {', $text, 'PHP variables in a template stay literal — no escaping needed');
        $this->assertStringNotContainsString('fore'."\n", $text, 'the abbreviation itself was consumed');
        // The first stop is selected, so typing over it replaces the default.
        $editor->handleInput('$rows');
        $this->assertStringContainsString('foreach ($rows as $item) {', $editor->getDocument()->getText());
        // And Tab now walks to the next stop rather than expanding again.
        $editor->handleInput("\t");
        $editor->handleInput('$row');
        $this->assertStringContainsString('foreach ($rows as $row) {', $editor->getDocument()->getText());
    }

    public function testTabAfterAnExpressionExpandsAPostfixTemplateAroundIt(): void
    {
        $editor = $this->editor("<?php\n", path: 'demo.php');
        $editor->getDocument()->setCursor(1, 0);
        $editor->handleInput('$this->rows.fore');

        $editor->handleInput("\t");

        $this->assertStringContainsString('foreach ($this->rows as $item) {', $editor->getDocument()->getText());
    }

    /** Tab is still Tab: with no template at the caret it indents, as it always did. */
    public function testTabWithNoTemplateAtTheCaretStillIndents(): void
    {
        $editor = $this->editor("<?php\n", path: 'demo.php');
        $editor->getDocument()->setCursor(1, 0);
        $editor->handleInput('notatemplate');

        $editor->handleInput("\t");

        $this->assertStringContainsString('notatemplate    ', $editor->getDocument()->getText());
    }

    /** A language with no templates of its own keeps Tab as pure indentation. */
    public function testTabInAFileOfAnotherLanguageDoesNotExpandPhpTemplates(): void
    {
        $editor = $this->editor("# title\n", path: 'notes.md');
        $editor->getDocument()->setCursor(1, 0);
        $editor->handleInput('fore');

        $editor->handleInput("\t");

        $this->assertStringContainsString('fore    ', $editor->getDocument()->getText(), 'markdown has no `fore`, so Tab indented');
    }

    /**
     * The expansion is ONE undo step — the abbreviation's removal and the
     * snippet's insertion never come apart, so a single undo can never leave a
     * half-expanded line behind. (It coalesces with the typing run that
     * produced the abbreviation, exactly as accepting a completion does.)
     */
    public function testOneUndoLeavesNoHalfExpandedTemplateBehind(): void
    {
        $editor = $this->editor("<?php\n", path: 'demo.php');
        $editor->getDocument()->setCursor(1, 0);
        $editor->handleInput('fore');
        $editor->handleInput("\t");
        $this->assertStringContainsString('foreach', $editor->getDocument()->getText());

        $editor->getDocument()->undo();

        $this->assertSame("<?php\n", $editor->getDocument()->getText(), 'one undo, and nothing of the expansion is left');
    }

    public function testTheGutterMarksFoldableLinesAndFlipsTheTriangleWhenCollapsed(): void
    {
        $editor = $this->editor("<?php\nfunction a()\n{\n    return 1;\n}\n");

        $rows = AnsiUtils::stripAnsiCodes(\implode("\n", $editor->render(new RenderContext(80, 10))));
        $this->assertMatchesRegularExpression('/^\s+3\s*'.Glyphs::EXPANDED.' \{/m', $rows, 'the fold start wears an open triangle');
        $this->assertStringNotContainsString(Glyphs::COLLAPSED, $rows, 'nothing is collapsed yet');

        $editor->getDocument()->setCursor(2, 0);
        $editor->runAction('toggle_fold');
        $rows = AnsiUtils::stripAnsiCodes(\implode("\n", $editor->render(new RenderContext(80, 10))));

        $this->assertStringContainsString(Glyphs::COLLAPSED, $rows, 'the collapsed fold flips the triangle');
        $this->assertStringNotContainsString(Glyphs::EXPANDED, $rows);
    }

    /** A line no region starts at gets a blank there, not a triangle. */
    public function testALineWithNoFoldRegionCarriesNoTriangle(): void
    {
        $editor = $this->editor("<?php\n\$a = 1;\n\$b = 2;\n");

        $rows = AnsiUtils::stripAnsiCodes(\implode("\n", $editor->render(new RenderContext(80, 10))));

        $this->assertStringNotContainsString(Glyphs::EXPANDED, $rows);
        $this->assertStringNotContainsString(Glyphs::COLLAPSED, $rows);
    }

    /**
     * The fold column is unconditional: a file with no foldable region at all
     * still reserves it, or the text would shift sideways as you switch buffers.
     */
    public function testTheFoldColumnIsReservedEvenWithNothingToFold(): void
    {
        $foldable = $this->editor("<?php\nfunction a()\n{\n}\n");
        $plainFile = $this->editor("<?php\n\$a = 1;\n");

        $this->assertSame($foldable->gutterWidth(), $plainFile->gutterWidth());
    }

    public function testSingleOccurrenceNotHighlighted(): void
    {
        $editor = $this->editor("<?php\n\$only = 1;\necho 2;\n");
        $editor->getDocument()->setCursor(1, 3); // inside $only (appears once)
        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringNotContainsString("\x1b[48;5;23m", \implode('', $rendered));
    }

    public function testOccurrenceHighlightSkipsShortAndNumericTokens(): void
    {
        // Single-letter variable "$i" (too short) is not highlighted even though
        // it repeats; nor is the numeric token "11".
        $editor = $this->editor("<?php\n\$i = 11;\necho \$i + 11;\n");

        $editor->getDocument()->setCursor(1, 1); // on $i
        $short = $editor->render(new RenderContext(80, 10));
        $this->assertStringNotContainsString("\x1b[48;5;23m", \implode('', $short));

        $editor->getDocument()->setCursor(1, 6); // on 11
        $numeric = $editor->render(new RenderContext(80, 10));
        $this->assertStringNotContainsString("\x1b[48;5;23m", \implode('', $numeric));
    }

    public function testIndentGuidesDrawnAtEachLevel(): void
    {
        // 8-space (two-level) indentation gets guides at columns 0 and 4. The
        // 4-space line is what makes the file's step readable as 4: a lone
        // 8-space jump is one level of eight as far as any reader can tell.
        $editor = $this->editor("<?php\nif (true) {\n    mid();\n    if (x) {\n        deep();\n");
        $rendered = $editor->render(new RenderContext(80, 10));

        $line1 = AnsiUtils::stripAnsiCodes($rendered[1]); // "if (true) {" — no indent
        $line2 = AnsiUtils::stripAnsiCodes($rendered[4]); // "        deep();" — 2 levels

        $this->assertStringNotContainsString('│', $line1, 'unindented line has no guide');
        $this->assertSame(2, \substr_count($line2, needle: '│'), 'a guide per indent level');
        $this->assertStringContainsString('deep();', $line2);
    }

    public function testInlayHintsRenderInlineAndShiftCaret(): void
    {
        $editor = $this->editor("<?php\n\$x = f();\n");
        $doc = $editor->getDocument();
        // Hints at the very start (col 0), mid-line (col 2) and end (append).
        $editor->decorations
            ->setInlayHints([
                1 => [
                    ['col' => 0, 'text' => '»'],
                    ['col' => 2, 'text' => ': int'],
                    ['col' => 9, 'text' => '⟵end'],
                ],
            ]);
        $doc->setCursor(1, 9); // end of line, after the mid hint

        $plain = AnsiUtils::stripAnsiCodes($editor->render(new RenderContext(80, 6))[1]);
        $this->assertStringContainsString('»', $plain, 'hint at column 0');
        $this->assertStringContainsString(': int', $plain, 'mid-line type hint');
        $this->assertStringContainsString('⟵end', $plain, 'appended hint at end of line');
        // The real code is still present around the hints.
        $this->assertStringContainsString('f();', $plain);
    }

    public function testDiagnostics(): void
    {
        $editor = $this->editor();
        $editor->decorations->setDiagnostics([2 => [['severity' => DiagnosticSeverity::Error, 'message' => 'Boom', 'col' => 0]]]);

        $counts = $editor->getDiagnosticCounts();
        $this->assertSame(1, $counts['errors']);
        $this->assertSame(0, $counts['warnings']);

        $rendered = $editor->render(new RenderContext(80, 20));
        // The left gutter shows the severity glyph in the marker column and the
        // line number colored red; the stripe repeats it as a density block.
        $this->assertStringContainsString('✗', AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($rendered[2], 0, 10)), 'error glyph in the left gutter');
        $this->assertStringContainsString("\x1b[38;5;203m", $rendered[2], 'line number colored for the error');
        $stripe = '';
        foreach ($rendered as $row) {
            $stripe .= AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($row, 79, 1));
        }
        $this->assertStringContainsString('█', $stripe, 'error block in the right stripe');

        $editor->getDocument()->setCursor(2, 0);
        $this->assertSame('Boom', $editor->getCursorDiagnostic()['message'] ?? null);
    }

    public function testTabIndentsAndShiftTabOutdentsSelection(): void
    {
        $editor = $this->editor("aaa\nbbb\nccc\n", 'indent.php');
        $doc = $editor->getDocument();
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(2, 0, selection: SelectionMode::Keep); // selection spans lines 0..1

        $editor->handleInput("\t"); // Tab indents the block instead of replacing it
        $this->assertSame('    aaa', $doc->getLine(0));
        $this->assertSame('    bbb', $doc->getLine(1));
        $this->assertSame('ccc', $doc->getLine(2), 'the line past the selection end is untouched');

        $editor->handleInput("\x1b[Z"); // Shift+Tab outdents
        $this->assertSame('aaa', $doc->getLine(0));
        $this->assertSame('bbb', $doc->getLine(1));
    }

    public function testTabWithoutSelectionStillInsertsIndentUnit(): void
    {
        $editor = $this->editor("x\n", 'plain.php');
        $editor->getDocument()->setCursor(0, 0);
        $editor->handleInput("\t");
        $this->assertSame('    x', $editor->getDocument()->getLine(0), 'no selection → Tab inserts a unit as before');
    }

    public function testSelectionCopyPasteDuplicateDelete(): void
    {
        $editor = $this->editor("<?php\necho 'hi';\n", 'clip.php');
        $editor->getDocument()->setCursor(1, 0);

        for ($i = 0; $i < 4; ++$i) {
            $editor->handleInput("\x1b[1;2C"); // shift+right
        }
        $this->assertSame('echo', $editor->getDocument()->getSelectedText());

        $rendered = $editor->render(new RenderContext(80, 20));
        $this->assertStringContainsString("\x1b[48;5;24m", $rendered[1], 'selection background rendered');

        $copied = null;
        $editor->setCallbacks(new EditorCallbacks(
            file: new FakeEditorFileActions(
                copy: static function (string $text) use (&$copied): void {
                    $copied = $text;
                },
            ),
        ));
        $editor->handleInput("\x03"); // ctrl+c
        $this->assertSame('echo', $copied);

        $editor->getDocument()->setCursor(2, 0);
        $editor->handleInput("\x16"); // ctrl+v
        $this->assertSame('echo', $editor->getDocument()->getLine(2));

        $editor->handleInput("\x04"); // ctrl+d duplicate
        $this->assertSame('echo', $editor->getDocument()->getLine(3));

        $editor->handleInput("\x19"); // ctrl+y delete line
        $this->assertSame(3, $editor->getDocument()->getLineCount());
    }

    public function testCurrentLineAndBracketDecorations(): void
    {
        $editor = $this->editor("<?php\nfunction f(array \$a): void\n{\n    echo \$a[0];\n}\n", 'deco.php');
        $editor->getDocument()->setCursor(2, 0); // on the opening brace

        $rendered = $editor->render(new RenderContext(80, 10));

        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[2]);
        $bracketRows = $rendered[2]."\n".$rendered[4];
        $this->assertSame(2, \substr_count($bracketRows, LineDecorator::BRACKET_SGR), 'both brackets highlighted');
        $this->assertExactWidth($rendered, 80);
    }

    public function testDiagnosticRangeUnderline(): void
    {
        $editor = $this->editor("<?php\nfunction f(array \$a): void\n{\n}\n", 'diag.php');
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'Bad type', 'col' => 11, 'endCol' => 16]]]);

        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringContainsString("\x1b[4:3m", $rendered[1], 'curly underline');
        $this->assertStringContainsString("\x1b[58;5;203m", $rendered[1], 'red underline color for the error');
        $this->assertExactWidth($rendered, 80);
    }

    public function testAnUNUSEDSymbolIsDrawnFaintAndADEPRECATEDOneStruckThrough(): void
    {
        // Severity cannot say either thing: phpactor reports an unused import
        // at severity 2, the same severity as a warning about code that runs.
        // Measured from it: `tags: [1]` on `worse.unused_import`.
        $editor = $this->editor("<?php\nuse App\\Unused;\nold_function();\n", 'tags.php');
        $editor->decorations
            ->setDiagnostics([
                1 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'Unused import', 'col' => 4, 'endCol' => 14, 'tags' => [1]]],
                2 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'Deprecated', 'col' => 0, 'endCol' => 12, 'tags' => [2]]],
            ]);

        $rendered = $editor->render(new RenderContext(80, 10));

        $this->assertStringContainsString(Sgr::FAINT, $rendered[1], 'the unused import is dimmed');
        $this->assertStringContainsString(Sgr::NORMAL_INTENSITY, $rendered[1], 'and the dimming stops at the end of the range');
        $this->assertStringNotContainsString(Sgr::STRIKETHROUGH, $rendered[1], 'unused is not deprecated');
        $this->assertStringContainsString(Sgr::STRIKETHROUGH, $rendered[2], 'the deprecated call is struck through');
        $this->assertStringContainsString(Sgr::NO_STRIKETHROUGH, $rendered[2]);
        $this->assertStringContainsString("\x1b[4:5m", $rendered[1], 'and both keep the severity underline they always had');
        $this->assertExactWidth($rendered, 80);
    }

    public function testASymbolThatIsBOTHUnusedAndDeprecatedGetsBothAttributes(): void
    {
        // The tags are a list, not a choice: an unused import OF a deprecated
        // class carries both, and each attribute has to be added to the other
        // rather than replace it — at both ends, because a close that drops one
        // leaves it bleeding down the rest of the line.
        $editor = $this->editor("<?php\nuse App\\Old;\n", 'both.php');
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'Unused deprecated import', 'col' => 4, 'endCol' => 11, 'tags' => [1, 2]]]]);

        $row = $editor->render(new RenderContext(80, 10))[1];

        $this->assertStringContainsString(Sgr::FAINT, $row);
        $this->assertStringContainsString(Sgr::STRIKETHROUGH, $row);
        $this->assertStringContainsString(Sgr::NORMAL_INTENSITY, $row);
        $this->assertStringContainsString(Sgr::NO_STRIKETHROUGH, $row);
    }

    public function testADiagnosticWithNoTagsDrawsExactlyWhatItAlwaysDid(): void
    {
        // The regression that matters: every diagnostic anyone has ever seen
        // carries no tags, and a close for an attribute that was never opened
        // bleeds it across the rest of the line.
        $editor = $this->editor("<?php
\$a = f();
", 'plain.php');
        $editor->decorations->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'Boom', 'col' => 0, 'endCol' => 2]]]);

        $rendered = $editor->render(new RenderContext(80, 10));

        $this->assertStringNotContainsString(Sgr::FAINT, $rendered[1]);
        $this->assertStringNotContainsString(Sgr::NORMAL_INTENSITY, $rendered[1]);
        $this->assertStringNotContainsString(Sgr::NO_STRIKETHROUGH, $rendered[1]);
    }

    public function testAServersOwnPAYLOADReachesTheRowStillTagged(): void
    {
        // The join, not the pieces: the mapper's output goes straight into the
        // decorations with no step in between, so a key added at one end and
        // read at the other is exactly the shape that passes two unit tests and
        // still draws nothing. This payload is phpactor's, as measured — an
        // unused import at severity 2 with `tags: [1]`.
        $editor = $this->editor("<?php\nuse App\\Thing;\n", 'wire.php');
        $editor->decorations
            ->setDiagnostics(LspMapper::diagnosticsByLine([
                'diagnostics' => [[
                    'severity' => 2, // on the wire, a Warning is its number
                    'message' => 'Name "Thing" is imported but not used',
                    'code' => 'worse.unused_import',
                    'source' => 'phpactor',
                    'tags' => [1],
                    'range' => ['start' => ['line' => 1, 'character' => 4], 'end' => ['line' => 1, 'character' => 13]],
                ]],
            ]));

        $this->assertStringContainsString(Sgr::FAINT, $editor->render(new RenderContext(80, 10))[1], 'the import the server called unused is drawn as unused');
    }

    public function testDiagnosticSeverityStylingWorstFirst(): void
    {
        // line 1: an error and a warning share the line → error (worst) wins the
        // gutter/inline; line 2: a lone info; line 3: a lone hint.
        $editor = $this->editor("<?php\n\$a = f();\n\$b = g();\n\$c = h();\n", 'sev.php');
        $editor->decorations
            ->setDiagnostics([
                1 => [
                    ['severity' => DiagnosticSeverity::Warning, 'message' => 'maybe null', 'col' => 5, 'endCol' => 8],
                    ['severity' => DiagnosticSeverity::Error, 'message' => 'undefined f', 'col' => 5, 'endCol' => 8],
                ],
                2 => [['severity' => DiagnosticSeverity::Information, 'message' => 'unused', 'col' => 0, 'endCol' => 2]],
                3 => [['severity' => DiagnosticSeverity::Hint, 'message' => 'style', 'col' => 0, 'endCol' => 2]],
            ]);

        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertExactWidth($rendered, 80);

        // Line-number color by worst severity per line (no icon in the left gutter).
        $this->assertStringContainsString("\x1b[38;5;203m", $rendered[1], 'error color (worst of error+warning)');
        $this->assertStringContainsString("\x1b[38;5;220m", $rendered[2], 'info color');
        $this->assertStringContainsString("\x1b[38;5;244m", $rendered[3], 'hint color');

        // Each diagnostic underlined in its own severity color.
        $this->assertStringContainsString("\x1b[58;5;203m", $rendered[1], 'error underline color');
        $this->assertStringContainsString("\x1b[58;5;208m", $rendered[1], 'warning underline color');
        $this->assertStringContainsString("\x1b[58;5;220m", $rendered[2], 'info underline color');

        // The stripe carries a block whose density encodes the worst severity,
        // mapped to its proportional row.
        $this->assertStringContainsString("\x1b[38;5;203m█", \implode('', $rendered), 'error: full block, colored red, in the stripe');
        $this->assertStringContainsString("\x1b[38;5;220m▒", \implode('', $rendered), 'info: medium-shade block in the stripe');
        $this->assertStringContainsString("\x1b[38;5;244m░", \implode('', $rendered), 'hint: light-shade block in the stripe');

        // The gutter marker column repeats the severity as a glyph.
        $this->assertStringContainsString('✗', AnsiUtils::stripAnsiCodes($rendered[1]), 'error glyph in the gutter');
        $this->assertStringContainsString('◦', AnsiUtils::stripAnsiCodes($rendered[2]), 'info glyph in the gutter');
        $this->assertStringContainsString('·', AnsiUtils::stripAnsiCodes($rendered[3]), 'hint glyph in the gutter');

        // Inline message on line 1 shows the worst (error) message + a "+1" count.
        $plain1 = AnsiUtils::stripAnsiCodes($rendered[1]);
        $this->assertStringContainsString('undefined f', $plain1, 'worst-severity message inline');
        $this->assertStringContainsString('+1', $plain1, 'count of the other diagnostic on the line');
    }

    public function testErrorStripeShowsWholeFileAndClickJumps(): void
    {
        $src = "<?php\n".\str_repeat("\$x = 1;\n", times: 60);
        $editor = $this->editor($src, 'stripe.php');
        $editor->decorations
            ->setDiagnostics([
                5 => [['severity' => DiagnosticSeverity::Error, 'message' => 'err', 'col' => 0, 'endCol' => 2]],
                55 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'warn', 'col' => 0, 'endCol' => 2]],
                30 => [], // an empty list marks nothing (defensive)
            ]);

        $rendered = $editor->render(new RenderContext(80, 20));
        $this->assertExactWidth($rendered, 80);

        // The stripe (last column) marks both problems even though line 55 is off-screen.
        $all = \implode('', $rendered);
        $this->assertStringContainsString("\x1b[38;5;203m\x1b[48;5;236m█", $all, 'error block in the stripe, over the viewport thumb');
        $this->assertStringContainsString("\x1b[38;5;208m▓", $all, 'off-screen warning shown as a dashed orange block');
        $this->assertSame(1, \substr_count($all, needle: '█'), 'the empty list draws no stripe mark');
        $this->assertSame(1, \substr_count($all, needle: '▓'), 'one warning mark');

        // Clicking low in the stripe jumps deep into the file (near the warning).
        $editor->clickAt(18, 79);
        $this->assertGreaterThan(40, $editor->getDocument()->cursorLine, 'stripe click jumps to a proportional file line');

        // A press (drag start) in the stripe also jumps instead of starting a selection.
        $editor->beginSelectionAt(1, 79);
        $this->assertLessThan(10, $editor->getDocument()->cursorLine, 'stripe press near the top jumps up');
        $this->assertFalse($editor->getDocument()->hasSelection(), 'no selection started from a stripe press');
    }

    public function testSearchMatchHighlights(): void
    {
        $editor = $this->editor("<?php\necho 'needle';\n", 'search.php');
        $editor->decorations->setSearchMatches([1 => [['start' => 6, 'end' => 12, 'current' => true]]]);

        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringContainsString("\x1b[48;5;130m", $rendered[1], 'current match background');
        $this->assertExactWidth($rendered, 80);
    }

    public function testGotoLineViaKeystroke(): void
    {
        $editor = $this->editor("a\nb\nc\nd\n");
        $editor->handleInput("\x07"); // ctrl+g
        $editor->handleInput('3');
        $editor->handleInput("\r");

        $this->assertSame(2, $editor->getDocument()->cursorLine);
    }

    public function testSignatureHintRenderingAndClearing(): void
    {
        $editor = $this->editor("<?php\n\ngreet\n");
        $editor->getDocument()->setCursor(2, 5);
        $editor->setSignatureHint('greet(string $name): string');

        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringContainsString('greet(string $name): string', AnsiUtils::stripAnsiCodes(\implode("\n", $rendered)));
        $this->assertExactWidth($rendered, 80);

        $editor->handleInput("\x1b"); // escape clears the hint
        $rendered = $editor->render(new RenderContext(80, 10));
        $this->assertStringNotContainsString('$name', AnsiUtils::stripAnsiCodes(\implode("\n", $rendered)));
    }

    public function testAutoTriggerOnArrowAndSignatureOnParen(): void
    {
        $editor = $this->editor("<?php\n\$x\n");
        $completionRequests = [];
        $signatureRequests = [];
        $editor->setCallbacks(new EditorCallbacks(
            completion: new FakeEditorCompletion(
                requestCompletion: static function (int $l, int $c) use (&$completionRequests): void {
                    $completionRequests[] = [$l, $c];
                },
                requestSignatureHelp: static function (int $l, int $c) use (&$signatureRequests): void {
                    $signatureRequests[] = [$l, $c];
                },
            ),
        ));

        $editor->getDocument()->setCursor(1, 2);
        $editor->handleInput('-');
        $editor->handleInput('>');
        $this->assertCount(1, $completionRequests, '-> auto-triggers completion');

        $editor->handleInput('(');
        $this->assertCount(1, $signatureRequests, '( triggers signature help');

        $editor->handleInput("\x00"); // ctrl+space manual trigger
        $this->assertCount(2, $completionRequests);
    }

    public function testDefinitionAndNavigateBackCallbacks(): void
    {
        $editor = $this->editor();
        $definitionAt = null;
        $navigatedBack = false;
        $editor->setCallbacks(new EditorCallbacks(
            navigation: new FakeEditorNavigation(
                requestDefinition: static function (int $l, int $c) use (&$definitionAt): void {
                    $definitionAt = [$l, $c];
                },
                navigateBack: static function () use (&$navigatedBack): void {
                    $navigatedBack = true;
                },
            ),
        ));

        $editor->getDocument()->setCursor(2, 3);
        $editor->handleInput("\x1b[24~"); // F12
        $this->assertSame([2, 3], $definitionAt);

        $editor->handleInput("\x1b[1;7D"); // ctrl+alt+left
        $this->assertTrue($navigatedBack);
    }

    public function testJumpToCentersAndConverts(): void
    {
        $lines = \implode("\n", \array_map(static fn (int $i): string => "line {$i}", \range(0, end: 99)));
        $editor = $this->editor($lines);
        $editor->jumpTo(50, 3);

        $this->assertSame(50, $editor->getDocument()->cursorLine);
        $this->assertSame(3, $editor->getDocument()->cursorCol);

        $rendered = $editor->render(new RenderContext(80, 20));
        $plain = AnsiUtils::stripAnsiCodes(\implode("\n", $rendered));
        $this->assertStringContainsString('line 50', $plain, 'target line visible after jump');
    }

    public function testBracketedPaste(): void
    {
        $editor = $this->editor("<?php\n\n");
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1b[200~pasted text\x1b[201~");
        $this->assertSame('pasted text', $editor->getDocument()->getLine(1));

        // Chunked paste across handleInput calls
        $editor->handleInput("\x1b[200~part1 ");
        $editor->handleInput('part2');
        $editor->handleInput("\x1b[201~");
        $this->assertStringContainsString('part1 part2', $editor->getDocument()->getLine(1));
    }

    public function testToggleCommentViaKeystroke(): void
    {
        $editor = $this->editor("<?php\necho 1;\n", 'c.php');
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1f"); // ctrl+/ (legacy ctrl+- byte)
        $this->assertSame('// echo 1;', $editor->getDocument()->getLine(1));
    }

    public function testMoveLineKeystrokes(): void
    {
        $editor = $this->editor("<?php\nAAA\nBBB\n");
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1b[1;4B"); // alt+shift+down
        $this->assertSame('BBB', $editor->getDocument()->getLine(1));
        $this->assertSame('AAA', $editor->getDocument()->getLine(2));

        $editor->handleInput("\x1b[1;4A"); // alt+shift+up
        $this->assertSame('AAA', $editor->getDocument()->getLine(1));
    }

    public function testBufferNavigationCallbacks(): void
    {
        $editor = $this->editor();
        $events = [];
        $editor->setCallbacks(new EditorCallbacks(
            file: new FakeEditorFileActions(
                openFind: static function (FindMode $r) use (&$events): void {
                    $events[] = 'find:'.\var_export(FindMode::Replace === $r, return: true);
                },
                openGotoFile: static function () use (&$events): void {
                    $events[] = 'goto';
                },
            ),
            navigation: new FakeEditorNavigation(
                requestHover: static function () use (&$events): void {
                    $events[] = 'doc';
                },
                requestReferences: static function () use (&$events): void {
                    $events[] = 'usages';
                },
            ),
            buffer: new FakeBufferNavigation(
                cycleBuffer: static function (int $d) use (&$events): void {
                    $events[] = "cycle:{$d}";
                },
                openRecentFiles: static function () use (&$events): void {
                    $events[] = 'recent';
                },
                closeBuffer: static function () use (&$events): void {
                    $events[] = 'close';
                },
            ),
        ));

        $editor->handleInput("\x1b[1;5C"); // ctrl+right
        $editor->handleInput("\x1b[1;5D"); // ctrl+left
        $editor->handleInput("\x05"); // ctrl+e
        $editor->handleInput("\x1bw"); // alt+w
        $editor->handleInput("\x06"); // ctrl+f
        $editor->handleInput("\x12"); // ctrl+r
        $editor->handleInput("\x10"); // ctrl+p
        $editor->handleInput("\x1b[1;2P"); // f1
        $editor->handleInput("\x1b[18;3~"); // alt+f7

        $this->assertSame(
            ['cycle:1', 'cycle:-1', 'recent', 'close', 'find:false', 'find:true', 'goto', 'doc', 'usages'],
            $events,
        );
    }

    public function testSaveAndUndoRedoKeystrokes(): void
    {
        $editor = $this->editor();
        $saved = false;
        $editor->setCallbacks(new EditorCallbacks(
            file: new FakeEditorFileActions(
                save: static function () use (&$saved): void {
                    $saved = true;
                },
            ),
        ));

        $editor->handleInput("\x13"); // ctrl+s
        $this->assertTrue($saved);

        $editor->handleInput('Q');
        $editor->handleInput("\x1a"); // ctrl+z undo
        $this->assertStringStartsWith('<?php', $editor->getDocument()->getLine(0));
    }

    public function testHorizontalScrollKeepsCursorVisible(): void
    {
        $long = \str_repeat('abcdefghij', times: 20);
        $editor = $this->editor("<?php\n{$long}\n");
        $editor->getDocument()->setCursor(1, 150);

        $rendered = $editor->render(new RenderContext(40, 5));
        $this->assertExactWidth($rendered, 40);
        $this->assertStringContainsString("\x1b_pi:c;", \implode('', $rendered), 'cursor marker still rendered');
    }

    public function testRenameKeystrokeOpensPrefilledPrompt(): void
    {
        $editor = $this->editor("<?php\n\$subject\n");
        $renamed = null;
        $editor->setCallbacks(new EditorCallbacks(
            refactor: new FakeEditorRefactor(
                // Mirror RefactorController's flow: prompt for the new name, then rename.
                requestPrepareRename: static function (int $l, int $c, string $word) use ($editor, &$renamed): void {
                    $editor->openPrompt('Rename to', $word, static function (string $value) use ($l, $c, &$renamed): void {
                        $renamed = [\trim($value), $l, $c];
                    });
                },
            ),
        ));
        $editor->getDocument()->setCursor(1, 8);

        $editor->handleInput("\x1b[17;2~"); // shift+f6
        $editor->handleInput("\r"); // accept prefilled word

        $this->assertSame(['subject', 1, 8], $renamed);
    }

    public function testRunActionEditVerbsMutateDocument(): void
    {
        $editor = $this->editor("<?php\necho 1;\n");
        $doc = $editor->getDocument();
        $doc->setCursor(0, 0);

        $editor->runAction('duplicate_line');
        $this->assertSame('<?php', $doc->getLine(0));
        $this->assertSame('<?php', $doc->getLine(1), 'line duplicated');

        $editor->runAction('undo');
        $this->assertSame('echo 1;', $doc->getLine(1), 'undo reverted the duplicate');

        $editor->runAction('redo');
        $this->assertSame('<?php', $doc->getLine(1), 'redo re-applied it');

        $editor->runAction('select_all');
        $this->assertTrue($doc->hasSelection());
    }

    public function testRunActionMatchesKeypressForDuplicateLine(): void
    {
        $viaKey = $this->editor("<?php\n");
        $viaKey->getDocument()->setCursor(0, 0);
        $viaKey->handleInput("\x1b[100;5u"); // ctrl+d (kitty) — duplicate_line

        $viaMenu = $this->editor("<?php\n");
        $viaMenu->getDocument()->setCursor(0, 0);
        $viaMenu->runAction('duplicate_line');

        $this->assertSame($viaKey->getDocument()->getText(), $viaMenu->getDocument()->getText());
    }

    public function testRunActionFiresCallbackVerbs(): void
    {
        $editor = $this->editor();
        $reformatted = false;
        $usagesAt = null;
        $editor->setCallbacks(new EditorCallbacks(
            navigation: new FakeEditorNavigation(
                requestReferences: static function (int $l, int $c) use (&$usagesAt): void {
                    $usagesAt = [$l, $c];
                },
            ),
            refactor: new FakeEditorRefactor(
                requestReformat: static function () use (&$reformatted): void {
                    $reformatted = true;
                },
            ),
        ));
        $editor->getDocument()->setCursor(2, 3);

        $editor->runAction('reformat');
        $this->assertTrue($reformatted, 'no-arg callback fired');

        $editor->runAction('find_usages');
        $this->assertSame([2, 3], $usagesAt, 'positional callback fired with caret');
    }

    public function testRunActionUnknownIdIsNoOp(): void
    {
        $editor = $this->editor("<?php\n");
        $before = $editor->getDocument()->getText();
        $editor->runAction('does_not_exist');
        $this->assertSame($before, $editor->getDocument()->getText());
    }

    public function testInsertTextGoesThroughTheFullChangePipeline(): void
    {
        $editor = $this->editor("ab\ncd\n");
        $editor->getDocument()->setCursor(1, 1);
        $changed = 0;
        $editor->setCallbacks(new EditorCallbacks(
            completion: new FakeEditorCompletion(
                scheduleDidChange: static function () use (&$changed): void {
                    ++$changed;
                },
            ),
        ));

        $editor->insertText('XY');

        $this->assertSame("ab\ncXYd\n", $editor->getDocument()->getText(), 'inserted at the caret');
        $this->assertSame(1, $changed, 'the change notification (LSP didChange path) fired');
        $editor->getDocument()->undo();
        $this->assertSame("ab\ncd\n", $editor->getDocument()->getText(), 'undoable like typed input');
    }

    public function testLineAnnotationsRenderAndYieldToDiagnostics(): void
    {
        $editor = $this->editor("alpha;\nbeta;\n", '/tmp/a.php');
        $editor->decorations->setLineAnnotations([0 => 'abc12345 tester 2026-01-01', 1 => 'abc12345 tester 2026-01-01']);

        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(80, 6))));
        $this->assertSame(2, \substr_count($screen, needle: 'abc12345 tester'), 'both annotations render in the trailing space');

        // A diagnostic on line 0 wins the trailing slot; line 1 keeps its blame.
        $editor->display->setDiagnosticMessages(DiagnosticMessageMode::Inline);
        $editor->decorations->setDiagnostics([0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'Boom', 'col' => 0]]]);
        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(80, 6))));
        $this->assertStringContainsString('Boom', $screen);
        $this->assertSame(1, \substr_count($screen, needle: 'abc12345 tester'), 'the diagnosed line dropped its annotation');

        // Too narrow: the annotation is dropped rather than truncated to noise.
        $editor->decorations->setDiagnostics([]);
        $narrow = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(16, 6))));
        $this->assertStringNotContainsString('abc12345', $narrow);

        // A document switch clears the annotations with the other decorations.
        $editor->openFile('/tmp/b.php', "gamma;\n");
        $screen = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $editor->render(new RenderContext(80, 6))));
        $this->assertStringNotContainsString('abc12345', $screen);
    }

    public function testShowDocumentRebuildsMultiCaretBoundToTheNewDocument(): void
    {
        $editor = $this->editor();
        $before = Probe::get($editor, 'multiCaret');

        $newDoc = new Document("<?php\n");
        $editor->showDocument('other.php', $newDoc);

        $after = Probe::get($editor, 'multiCaret');
        $this->assertNotSame($before, $after, 'the old buffer\'s carets must not leak into the new one');
        $this->assertSame($newDoc, Probe::get($after, 'document'));
    }

    public function testCloseSiblingFloatersClosesTheWelcomeView(): void
    {
        $welcome = new class implements ContributedView {
            private bool $open = false;

            public function viewId(): string
            {
                return 'welcome';
            }

            public function hostsWindow(): bool
            {
                return false;
            }

            public function title(): string
            {
                return '';
            }

            public function isOpen(): bool
            {
                return $this->open;
            }

            public function open(): void
            {
                $this->open = true;
            }

            public function close(): void
            {
                $this->open = false;
            }

            public function toggle(): void
            {
                $this->open = !$this->open;
            }

            public function pointer(): ?ViewPointer
            {
                return null;
            }

            public function contextMenus(): ?ViewMenuSource
            {
                return null;
            }

            public function focusTarget(): AbstractWidget&FocusableInterface
            {
                throw new \LogicException('not exercised by this test');
            }

            public function cycleFocus(int $direction): bool
            {
                return false;
            }
        };
        $views = new ViewRegistry();
        $views->register($welcome);
        $views->open('welcome');
        $this->assertTrue($views->isOpen('welcome'), 'sanity: the welcome view is actually open before the picker opens');

        $editor = new CodeEditorWidget(views: $views);
        $editor->openFile('a.php', "<?php\n");
        $editor->openListOverlay('Recent', [], static function (string $v): void {});

        $this->assertFalse($views->isOpen('welcome'), 'opening the picker must close the welcome view sharing its span slot');
    }

    public function testHandleInputSwallowsTheEmptyRemainderWhileBufferingAPaste(): void
    {
        // Mid-paste (start marker seen, no end marker yet), a subsequent
        // empty chunk must be swallowed, not fall through to keybinding
        // dispatch as if it were a real (empty) keystroke.
        $editor = $this->editor("<?php\n\n");
        $editor->getDocument()->setCursor(1, 0);

        $editor->handleInput("\x1b[200~partial"); // starts buffering, no end marker
        $before = $editor->getDocument()->getLine(1);

        $editor->handleInput(''); // the empty remainder this guard exists for

        $this->assertSame($before, $editor->getDocument()->getLine(1), 'an empty chunk mid-paste must be a no-op');
    }

    /**
     * The join: a REAL edit moves the gutter marks, without anything reporting
     * where it happened.
     *
     * `notifyChange()` is the one place core hears about every edit and it
     * carries no position, so the widget derives it — the caret is on the edit,
     * and the lines it added say how far the caret has already moved past the
     * line the edit started on. A unit test of the store alone would pin the
     * arithmetic and say nothing about that derivation, which is the half that
     * can be wrong.
     */
    public function testAnEditCarriesTheGitGutterMarksWithIt(): void
    {
        $widget = new CodeEditorWidget();
        $document = new Document("a\nb\nc\nd\ne\n");
        $widget->showDocument('/tmp/x.php', $document);
        $widget->decorations->setVcsMarks([3 => ChangeMarkKind::Modified]);

        $document->setCursor(1, 1);
        $document->insertNewLine();
        $widget->notifyChange();

        $this->assertSame(
            [1 => ChangeMarkKind::Modified, 2 => ChangeMarkKind::Added, 4 => ChangeMarkKind::Modified],
            $widget->decorations->vcsMarks,
            'the split line is Modified, the line it made is Added, and the mark below moved down one',
        );
    }

    /**
     * And a new buffer starts from its OWN line count. Carrying the previous
     * document's over would read as an edit of the difference on the first
     * keystroke, moving every mark in the new file by that many lines.
     */
    public function testShowingAnotherDocumentRebasesTheRemap(): void
    {
        $widget = new CodeEditorWidget();
        $widget->showDocument('/tmp/long.php', new Document(\str_repeat("x\n", times: 40)));

        $short = new Document("a\nb\nc\n");
        $widget->showDocument('/tmp/short.php', $short);
        $widget->decorations->setVcsMarks([2 => ChangeMarkKind::Added]);

        $short->setCursor(0, 1);
        $short->insert('!');
        $widget->notifyChange();

        $this->assertSame(
            [0 => ChangeMarkKind::Modified, 2 => ChangeMarkKind::Added],
            $widget->decorations->vcsMarks,
            'the 37-line difference between the two buffers is not an edit',
        );
    }
}
