<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Editor\PreviewMode;
use Porta\History\SessionRestorer;
use Porta\Ide;
use Porta\Layout\PreviewSplit;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Widgets;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\MarkdownPreviewWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A markdown file beside its live preview: the split it opens in, the preview
 * following the TEXT as it is typed, the Editor / Split / Preview toggles and
 * their key, the divider's drag and nudge and the layout file that remembers
 * them, and the editor-hosted modals a lone preview hands back to the text.
 */
final class IdePreviewSplitTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    private const string README = "# Porta\n\nA terminal IDE.\n\n- one\n- two\n";

    private function project(): TempProject
    {
        \putenv('PATH=/nonexistent');

        return new TempProject(['README.md' => self::README, 'a.php' => "<?php\n"]);
    }

    /** A left click on the toggle captioned $caption, found on the bar's own row. */
    private function clickToggle(Tui $tui, Ide $ide, string $caption): void
    {
        $bar = $tui->getById('breadcrumbs');
        $this->assertInstanceOf(BreadcrumbsWidget::class, $bar);
        $rect = Widgets::rectIn($ide, $bar);
        foreach (\range(0, $rect->columns - 1) as $col) {
            $mode = $bar->previewModeAt($col);
            if (null !== $mode && $caption === $mode->name) {
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $col + 1, $rect->row + 1));

                return;
            }
        }
        self::fail('no toggle captioned '.$caption);
    }

    /** The active tab's arrangement — the model the toggles and the key write. */
    private static function mode(Ide $ide): ?PreviewMode
    {
        return Services::resolve($ide, BufferManager::class)->active()?->previewArrangement();
    }

    public function testAMarkdownFileOpensBesideItsPreviewAndThePreviewFollowsTheText(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                    // The heading's `#` is the editor's; the preview draws the
                    // words alone, a column of margin past the divider.
                    $this->await(static fn (): bool => \str_contains($grid(), '│ Porta'), what: 'the rendered heading beside the text');
                    $this->assertStringContainsString('# Porta', $grid(), 'the text is on screen too');
                    $this->assertStringContainsString('[Split]', $grid(), 'the toggle says which arrangement this is');
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'the text has the keys');

                    // Type at the end of the file: the preview follows once the
                    // typing pauses — and the file on disk is untouched.
                    $editor = Services::resolve($ide, CodeEditorWidget::class);
                    $editor->jumpTo(6, 0);
                    foreach (\str_split('## Added') as $key) {
                        $tui->handleInput($key);
                    }
                    $this->await(static fn (): bool => \str_contains($grid(), '│ Added'), what: 'the preview following the typed heading');
                    $this->assertSame(self::README, \file_get_contents($project->path('README.md')), 'nothing was saved to get there');
                },
            ],
            columns: 120,
        );
    }

    public function testTheTogglesTheKeyAndTheMenuRearrangeTheTab(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->await(static fn (): bool => \str_contains($grid(), '│ Porta'), what: 'the split');

                    $this->clickToggle($tui, $ide, 'Preview');
                    $this->await(static fn (): bool => \str_contains($grid(), '[Preview]'), what: 'the preview alone');
                    $this->assertSame(PreviewMode::Preview, self::mode($ide));
                    $this->assertStringNotContainsString('# Porta', $grid(), 'the text stepped aside');
                    $this->assertSame('markdown-preview', $tui->getFocus()?->getId(), 'the preview alone has the keys');

                    // The key steps on, wrapping: Preview → Editor.
                    $tui->handleInput("\x1b\x16"); // Ctrl+Alt+V
                    $this->await(static fn (): bool => \str_contains($grid(), '[Editor]'), what: 'the text alone');
                    $this->assertSame(PreviewMode::Editor, self::mode($ide));
                    $this->assertStringNotContainsString('│ Porta', $grid(), 'no preview beside it');
                    $this->assertSame('editor', $tui->getFocus()?->getId());

                    $this->clickToggle($tui, $ide, 'Split');
                    $this->await(static fn (): bool => \str_contains($grid(), '│ Porta') && \str_contains($grid(), '# Porta'), what: 'both again');

                    // The menu's items are checked by the arrangement on screen, and
                    // run like the toggles.
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $this->assertTrue(($commands->checkedState('preview_split') ?? static fn (): bool => false)());
                    $this->assertFalse(($commands->checkedState('preview_only') ?? static fn (): bool => true)());
                    $commands->run('preview_only');
                    $this->assertSame(PreviewMode::Preview, self::mode($ide));
                    $commands->run('preview_editor');
                    $this->assertSame(PreviewMode::Editor, self::mode($ide));

                    // A file with no preview has no toggles, and the verbs refuse.
                    Services::resolve($ide, BufferService::class)->openFile(
                        (string) \realpath(\dirname((string) Services::resolve($ide, BufferManager::class)->active()?->path).'/a.php'),
                        OpenFocus::Editor,
                    );
                    $this->assertFalse($commands->isEnabled('toggle_preview'), 'nothing to toggle on a PHP file');
                    $commands->run('preview_only');
                    $this->assertNull(self::mode($ide));
                    $this->assertStringNotContainsString('[Split]', $grid());
                },
            ],
            columns: 120,
        );
    }

    public function testTheDividerDragsNudgesAndIsRememberedForTheNextSession(): void
    {
        $project = $this->project();
        $editorColumns = 0;

        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use (&$editorColumns, $project): void {
                    $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->await(static fn (): bool => \str_contains($grid(), '│ Porta'), what: 'the split');
                    $split = Services::resolve($ide, PreviewSplit::class);
                    $editor = Services::resolve($ide, CodeEditorWidget::class);
                    $before = Widgets::rectIn($ide, $editor)->columns;
                    $divider = Widgets::rectIn($ide, $split->separator);

                    // Under the pointer the divider says it is a grab.
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $divider->col + 1, $divider->row + 3));
                    $this->await(static fn (): bool => \str_contains($grid(), '┃'), what: 'the hovered divider');
                    $this->awaitPainted($tui, $terminal, 'Drag to resize'); // and, resting there, says so

                    // Press on it, drag twelve columns left, let go.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $divider->col + 1, $divider->row + 3));
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $divider->col - 11, $divider->row + 3));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $divider->col - 11, $divider->row + 3));
                    $this->frame($tui);
                    $after = Widgets::rectIn($ide, $editor)->columns;
                    $this->assertLessThan($before - 8, $after, 'the editor gave the preview its columns');
                    $persisted = \json_decode((string) \file_get_contents($project->path('.porta/layout.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
                    $this->assertIsArray($persisted);
                    $this->assertSame($split->percent(), $persisted['previewSplitPercent'] ?? null, 'the proportion is written down');

                    // Shift+F12 from the editor grows it back two columns a press.
                    $tui->handleInput("\x1b[24;2~");
                    $this->frame($tui);
                    $this->assertGreaterThan($after, Widgets::rectIn($ide, $editor)->columns, 'the keyboard nudge moves the divider too');
                    $editorColumns = Widgets::rectIn($ide, $editor)->columns;
                },
            ],
            columns: 120,
        );

        // A new session on the same project: the split comes back as it was left.
        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($editorColumns): void {
                    $this->await(fn (): bool => \str_contains(\implode("\n", $this->screenGrid($tui, $terminal)), '│ Porta'), what: 'the split, again');
                    $this->assertEqualsWithDelta($editorColumns, Widgets::rectIn($ide, Services::resolve($ide, CodeEditorWidget::class))->columns, 1.0, 'the remembered proportion');
                },
            ],
            columns: 120,
        );
    }

    public function testTheFocusRingStopsAtBothHalvesAndAClickHandsTheKeysOver(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->await(static fn (): bool => \str_contains($grid(), '│ Porta'), what: 'the split');
                    $preview = Services::resolve($ide, MarkdownPreviewWidget::class);

                    $tui->handleInput("\x1b[17~"); // F6: editor → preview
                    $this->assertSame($preview, $tui->getFocus());
                    $tui->handleInput("\x1b[17~"); // F6: preview → tree
                    $this->assertSame('tree', $tui->getFocus()?->getId());

                    // A click on the navigation bar gives the preview the keys (one
                    // on its text takes the reader to the text instead).
                    $rect = Widgets::rectIn($ide, $preview);
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 3, $rect->row + 1));
                    $this->assertSame($preview, $tui->getFocus());
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 3, $rect->row + 1));

                    // Ctrl+T leaves the area from EITHER half, and comes back to the text.
                    $tui->handleInput("\x14");
                    $this->assertSame('tree', $tui->getFocus()?->getId());
                    $tui->handleInput("\x14");
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },
            ],
            columns: 120,
        );
    }

    public function testALonePreviewHandsTheTextBackToTheModalsThatNeedIt(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->await(static fn (): bool => \str_contains($grid(), '│ Porta'), what: 'the split');
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $editor = Services::resolve($ide, CodeEditorWidget::class);

                    // Search Everywhere composites over the EDITOR: from a lone
                    // preview it brings the text back first, or it would open on
                    // a widget nothing draws.
                    $commands->run('preview_only');
                    $commands->run('ide_search_everywhere');
                    $this->assertSame(PreviewMode::Split, self::mode($ide));
                    $this->assertTrue($editor->isAnyPickerOpen(), 'the picker opened, over the text');
                    $tui->handleInput("\x1b");

                    // So does the unsaved-changes question on close.
                    $commands->run('preview_only');
                    $active = Services::resolve($ide, BufferManager::class)->active();
                    $this->assertInstanceOf(Buffer::class, $active);
                    $active->document->insert('x');
                    Services::resolve($ide, BufferService::class)->closeActiveBuffer();
                    $this->awaitPainted($tui, $terminal, 'Unsaved changes in README.md');
                    $this->assertSame(PreviewMode::Split, self::mode($ide));
                },
            ],
            columns: 120,
        );
    }

    public function testASplitTooNarrowForTwoSidesShowsTheTextAlone(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->await(static fn (): bool => \str_contains($grid(), '# Porta'), what: 'the text');
                    $this->frame($tui);
                    $this->assertSame(PreviewMode::Split, self::mode($ide), 'the arrangement is still Split');
                    $this->assertStringNotContainsString('│ Porta', $grid(), 'but there is no room for the preview beside it');
                    $this->assertNull(Services::resolve($ide, PreviewSplit::class)->separator->getContext(), 'nor for its divider');
                },
            ],
            columns: 40,
        );
    }

    public function testAFileTooLargeToEditOrClaimedForAPaneNobodyServesOpensWithoutAPreview(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'big.md' => '# Big'."\n".\str_repeat("A line of prose.\n", times: 150_000),
            'notes.ghost' => "just text\n",
        ]);
        // A plugin claiming a preview for a pane id that no pane answers to:
        // the claim is honoured only when there is something to show.
        $dir = $project->path('.porta/plugins/ghost');
        \mkdir($dir, permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/porta.php', data: <<<'PHP'
            <?php

            declare(strict_types=1);

            use Porta\Plugin\Api\AbstractPlugin;
            use Porta\Plugin\Api\PluginApi;

            return new class('ghost') extends AbstractPlugin {
                public function activate(PluginApi $api): void
                {
                    $api->registerPreview('ghost', ['ghost']);
                }
            };
            PHP);

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $service = Services::resolve($ide, BufferService::class);
                    $buffers = Services::resolve($ide, BufferManager::class);

                    $service->openFile($project->path('big.md'));
                    $this->awaitPainted($tui, $terminal, 'Read-only');
                    $this->assertTrue($buffers->active()?->isViewer(), 'too large to edit: the read-only look, as for any file');
                    $this->assertNull($buffers->active()?->previewArrangement());

                    $service->openFile($project->path('notes.ghost'));
                    $this->awaitPainted($tui, $terminal, 'just text');
                    $this->assertNull($buffers->active()?->previewId, 'no pane answers the claim: plain text');

                    // The read-only look hands tab verbs to the IDE too: it had no handler, so
                    // Ctrl+Right there did nothing.
                    $service->openFile($project->path('big.md'));
                    $tui->setFocus($tui->getById('text-preview'));
                    $tui->handleInput("\x1b[1;5C"); // Ctrl+Right
                    $this->assertSame('notes.ghost', \basename((string) $buffers->active()?->path));
                },
            ],
            columns: 120,
        );
    }

    public function testSwitchingFilesShowsTheNewPreviewAtOnceAndGivesTheTextTheKeys(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.md' => "# Alpha\n", 'b.md' => "# Bravo\n"]);

        $this->runIde(
            $project,
            $project->path('a.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->await(static fn (): bool => \str_contains($grid(), '│ Alpha'), what: 'the first preview');
                    $preview = Services::resolve($ide, MarkdownPreviewWidget::class);
                    $tui->setFocus($preview);

                    // The same frame, no pause: the preview is handed the new file
                    // as it comes on screen, not after the typing pause.
                    Services::resolve($ide, BufferService::class)->openFile($project->path('b.md'), OpenFocus::Editor);
                    $this->assertStringContainsString('│ Bravo', $grid(), 'the new file\'s preview, at once');
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'a file picked lands on its text, whoever had the keys');
                },
            ],
            columns: 120,
        );
    }

    public function testARightToLeftWorkspaceSeatsTheTextOnTheRight(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['settings' => ['language' => 'he']], \JSON_THROW_ON_ERROR),
            'README.md' => self::README,
        ]);

        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->await(fn (): bool => \str_contains(\implode("\n", $this->screenGrid($tui, $terminal)), 'Porta'), what: 'the split');
                    $editor = Widgets::rectIn($ide, Services::resolve($ide, CodeEditorWidget::class));
                    $preview = Widgets::rectIn($ide, Services::resolve($ide, MarkdownPreviewWidget::class));
                    $divider = Widgets::rectIn($ide, Services::resolve($ide, PreviewSplit::class)->separator);

                    $this->assertLessThan($divider->col, $preview->col, 'the preview on the left');
                    $this->assertGreaterThan($divider->col, $editor->col, 'the text on the reading side, the right');
                },
            ],
            columns: 120,
        );
    }

    public function testAMenuDroppedOverTheDividerCoversIt(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->await(fn (): bool => \str_contains(\implode("\n", $this->screenGrid($tui, $terminal)), '│ Porta'), what: 'the split');
                    $divider = Widgets::rectIn($ide, Services::resolve($ide, PreviewSplit::class)->separator)->col;

                    $tui->handleInput("\x1b[21~"); // F10 arms the bar
                    for ($i = 0; $i < 5; ++$i) {
                        $tui->handleInput("\x1b[C"); // → View (no Plugins menu without plugins)
                    }
                    $tui->handleInput("\r");
                    $this->awaitPainted($tui, $terminal, 'Preview Only');

                    $crossed = 0;
                    foreach ($this->screenGrid($tui, $terminal) as $row) {
                        $cells = \mb_str_split($row);
                        $left = \array_search('┃', $cells, strict: true);
                        $right = \array_search('┃', \array_reverse($cells, preserve_keys: true), strict: true);
                        if (false === $left || false === $right || ($left >= $divider || $divider >= $right)) {
                            continue;
                        }
                        ++$crossed;
                        $this->assertNotSame('│', $cells[$divider] ?? null, 'the divider shows through the menu: '.$row);
                    }
                    $this->assertGreaterThan(3, $crossed, 'the dropdown crosses the divider');
                },
            ],
            columns: 76,
        );
    }

    public function testThePreviewReadsLikeTheDocumentationAndItsLinksLeadToTheirFiles(): void
    {
        $project = new TempProject([
            'README.md' => "# Project\n\n[Guide](docs/guide.md) · [Code](a.php) · [Gone](missing.md) · [Site](https://example.com/x)\n",
            'docs/guide.md' => "# The guide\n",
            'a.php' => "<?php\n",
        ]);
        $captureFile = $project->path('opened-urls.txt');
        foreach (['open', 'xdg-open'] as $name) {
            $shim = $project->write('shim-bin/'.$name, "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> ".\escapeshellarg($captureFile)."\n");
            \chmod($shim, permissions: 0o755);
        }
        \putenv('PATH='.$project->path('shim-bin'));

        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->await(static fn (): bool => \str_contains($grid(), '│ Project'), what: 'the page');
                    $this->assertStringContainsString(\basename($project->dir), $grid(), 'the navigation bar names the project, the tree the page lives in');
                    $preview = Services::resolve($ide, MarkdownPreviewWidget::class);
                    $buffers = Services::resolve($ide, BufferManager::class);
                    $service = Services::resolve($ide, BufferService::class);
                    // A click on the link drawn with $label, where the preview drew it.
                    $follow = function (string $label) use ($tui, $terminal, $ide, $preview): void {
                        $rect = Widgets::rectIn($ide, $preview);
                        foreach ($this->screenGrid($tui, $terminal) as $row => $line) {
                            $col = \mb_strpos(\mb_substr($line, $rect->col), $label);
                            if (false !== $col && $row > $rect->row) {
                                $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $col + 1, $row + 1));
                                $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + $col + 1, $row + 1));

                                return;
                            }
                        }
                        self::fail('no link drawn as '.$label);
                    };

                    // A markdown link opens that file's tab, and the page keeps the keys.
                    $follow('Guide');
                    $this->assertSame('guide.md', \basename((string) $buffers->active()?->path));
                    $this->await(static fn (): bool => \str_contains($grid(), '│ The guide'), what: 'the linked page');
                    $this->assertSame($preview, $tui->getFocus());

                    // Backspace walks back to the page it was followed from — and no further.
                    $tui->handleInput("\x7f");
                    $this->assertSame('README.md', \basename((string) $buffers->active()?->path));
                    $tui->handleInput("\x7f");
                    $this->awaitPainted($tui, $terminal, 'Nowhere to go back to');

                    // Anything else opens in the editor; a dangling link says so.
                    $follow('Code');
                    $this->assertSame('a.php', \basename((string) $buffers->active()?->path));
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $service->openFile($project->path('README.md'));
                    $follow('Gone');
                    $this->awaitPainted($tui, $terminal, 'Cannot read missing.md');

                    // Ctrl+F finds in the rendered page.
                    $tui->setFocus($preview);
                    $tui->handleInput("\x06");
                    $this->assertTrue(Services::resolve($ide, FindBarWidget::class)->isOpen(), 'the find bar, over the page');
                    $tui->handleInput("\x1b");

                    // A web link goes to the browser (once the error card that
                    // floats over the page's top rows is dismissed).
                    $tui->handleInput("\x1bd");
                    $follow('Site');
                    $this->awaitPainted($tui, $terminal, 'Opened https://example.com/x');

                    // The tab verbs reach the IDE from the page, as from any pane.
                    $before = $buffers->active()?->path;
                    $tui->setFocus($preview);
                    $tui->handleInput("\x1b[1;5C"); // Ctrl+Right
                    $this->assertNotSame($before, $buffers->active()?->path, 'Ctrl+Right moved to the next tab');
                },
            ],
            columns: 140,
        );
    }

    public function testAClickOnThePreviewPutsTheCaretOnTheWordInTheText(): void
    {
        \putenv('PATH=/nonexistent');
        $body = \implode("\n", \array_map(static fn (int $n): string => 'Filler paragraph '.$n.'.', \range(1, end: 30)));
        $project = new TempProject(['README.md' => "# Porta\n\nA paragraph that wraps\nonto a second target line, **bold**.\n\n".\str_replace("\n", replace: "\n\n", subject: $body)."\n"]);

        $this->onThePreviewOf($project, function (Tui $tui, VirtualTerminal $terminal, Ide $ide, \Closure $grid, MarkdownPreviewWidget $preview, CodeEditorWidget $editor): void {
            $click = fn (string $needle) => $this->clickDrawn($tui, $ide, $preview, $grid(), $needle);

            // Beside the text: the caret goes to the very byte clicked — the
            // click lands on the word's second letter — and the text takes the keys.
            $click('target');
            $this->assertSame([3, 15], [$editor->getDocument()->cursorLine, $editor->getDocument()->cursorCol], 'on "target", in the paragraph\'s second source line');
            $this->assertSame($editor, $tui->getFocus());

            // Markup the page does not draw is skipped: the source spells
            // "bold." as "**bold**.", and the click lands on its "o".
            $click('bold.');
            $this->assertSame([3, 30], [$editor->getDocument()->cursorLine, $editor->getDocument()->cursorCol], 'on "bold", inside its markup');

            // Far down the page, the editor scrolls to it.
            $tui->setFocus($preview);
            $tui->handleInput("\x1b[F"); // End
            $this->frame($tui);
            $click('Filler paragraph 30');
            $this->assertSame(63, $editor->getDocument()->cursorLine);
            $this->assertStringContainsString('Filler paragraph 30.', \implode("\n", $grid()), 'the text scrolled to the caret');

            // The preview alone keeps the keys; the caret waits in the text.
            Services::resolve($ide, CommandRegistry::class)->run('preview_only');
            $this->frame($tui);
            $tui->handleInput("\x1b[H"); // Home
            $this->frame($tui);
            $click('Porta');
            $this->assertSame(0, $editor->getDocument()->cursorLine);
            $this->assertSame($preview, $tui->getFocus());
        });
    }

    /**
     * Click the second character of the first $needle the preview draws below
     * its top row — wherever the current layout put it (it moves when the
     * preview is shown alone) — on the screen $grid.
     *
     * @param array<int, string> $grid
     */
    private function clickDrawn(Tui $tui, Ide $ide, MarkdownPreviewWidget $preview, array $grid, string $needle): void
    {
        $rect = Widgets::rectIn($ide, $preview);
        foreach ($grid as $row => $line) {
            $col = \mb_strpos(\mb_substr($line, $rect->col), $needle);
            if (false !== $col && $row > $rect->row) {
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $col + 2, $row + 1));
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + $col + 2, $row + 1));

                return;
            }
        }
        self::fail('nothing drawn as '.$needle);
    }

    /**
     * The project's README open beside its preview on a 120-column screen,
     * the page drawn — then $then, with how to read the screen, the preview
     * and the text.
     *
     * @param \Closure(Tui, VirtualTerminal, Ide, \Closure(): array<int, string>, MarkdownPreviewWidget, CodeEditorWidget): void $then
     */
    private function onThePreviewOf(TempProject $project, \Closure $then): void
    {
        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($then): void {
                    $grid = fn (): array => $this->screenGrid($tui, $terminal);
                    $this->await(static fn (): bool => \str_contains(\implode("\n", $grid()), '│ Porta'), what: 'the page');
                    $then($tui, $terminal, $ide, $grid, Services::resolve($ide, MarkdownPreviewWidget::class), Services::resolve($ide, CodeEditorWidget::class));
                },
            ],
            columns: 120,
        );
    }

    public function testTheClickMapFollowsTheSplitAndTheTerminalAsTheyResize(): void
    {
        \putenv('PATH=/nonexistent');
        $words = \implode(' ', \array_map(static fn (int $n): string => 'w'.$n, \range(1, end: 60)));
        $project = new TempProject(['README.md' => "# Porta\n\n".$words."\n"]);

        $this->onThePreviewOf($project, function (Tui $tui, VirtualTerminal $terminal, Ide $ide, \Closure $grid, MarkdownPreviewWidget $preview, CodeEditorWidget $editor): void {
            // Click the drawn word $word, wherever the current layout put it,
            // and say where the caret landed.
            $clickWord = function (string $word) use ($tui, $grid, $ide, $preview, $editor): int {
                $this->clickDrawn($tui, $ide, $preview, $grid(), ' '.$word.' ');

                return $editor->getDocument()->cursorCol;
            };
            $column = static fn (string $word): int => (int) \strpos($editor->getDocument()->getLine(2), $word.' ');

            $this->assertSame($column('w40'), $clickWord('w40'), 'as first laid out');

            // The divider dragged far left: the page rewraps wider, and a click
            // still lands on the word it drew.
            $divider = Widgets::rectIn($ide, Services::resolve($ide, PreviewSplit::class)->separator);
            $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $divider->col + 1, $divider->row + 3));
            $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $divider->col - 20, $divider->row + 3));
            $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $divider->col - 20, $divider->row + 3));
            $this->frame($tui);
            $this->assertSame($column('w47'), $clickWord('w47'), 'after the drag');

            // And after the terminal itself is resized.
            $terminal->simulateResize(90, 24);
            $this->frame($tui);
            $this->assertSame($column('w25'), $clickWord('w25'), 'after a terminal resize');
        });
    }

    public function testMovingTheCaretThroughTheTextKeepsThePreviewOnIt(): void
    {
        \putenv('PATH=/nonexistent');
        $body = \implode("\n\n", \array_map(static fn (int $n): string => 'Filler paragraph '.$n.'.', \range(1, end: 40)));
        $project = new TempProject(['README.md' => "# Porta\n\n".$body."\n"]);

        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->await(static fn (): bool => \str_contains($grid(), '│ Porta'), what: 'the page');
                    $this->assertStringNotContainsString('│ Filler paragraph 40.', $grid(), 'the end of the page is below the fold');

                    $tui->handleInput("\x1b[1;5F"); // Ctrl+End: the caret to the last line
                    $this->await(static fn (): bool => \str_contains($grid(), '│ Filler paragraph 40.'), what: 'the preview following the caret down');

                    $tui->handleInput("\x1b[1;5H"); // Ctrl+Home
                    $this->await(static fn (): bool => \str_contains($grid(), '│ Porta'), what: 'and back up');
                },
            ],
            columns: 120,
        );
    }

    public function testATabComesBackInTheArrangementItWasLeftIn(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('README.md'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->await(fn (): bool => \str_contains(\implode("\n", $this->screenGrid($tui, $terminal)), '│ Porta'), what: 'the split');
                    Services::resolve($ide, CommandRegistry::class)->run('preview_only');
                    Services::resolve($ide, SessionRestorer::class)->save();
                },
            ],
            columns: 120,
        );

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->await(static fn (): bool => PreviewMode::Preview === self::mode($ide), what: 'the restored tab, still showing its preview alone');
                    $this->assertSame('markdown-preview', $tui->getFocus()?->getId(), 'and the keys with it');
                },
            ],
            columns: 120,
        );
    }
}
