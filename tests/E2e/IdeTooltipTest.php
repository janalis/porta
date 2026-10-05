<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\CompletionItem;
use Porta\Editor\InlayHintMode;
use Porta\I18n\Translator;
use Porta\Ide;
use Porta\Overlay\State\ToastCenter;
use Porta\Overlay\State\ToastSeverity;
use Porta\Overlay\State\TooltipCenter;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Widgets;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The tooltip end to end: a real move over a real element, the dwell, the
 * card painted beside it with its arrow, and the ways it goes — a key, a
 * press, leaving — plus the card following its element through a resize.
 */
final class IdeTooltipTest extends TestCase
{
    use RunsIde;
    use ScreenReads;

    /**
     * The screen cell of the first cell of $widget whose tooltip has $key —
     * found by asking the widget, so the test does not hard-code a layout.
     *
     * @return array{int, int} [row, col], 0-based
     */
    private function cellOf(Ide $ide, AbstractWidget&TooltipProvider $widget, string $key): array
    {
        $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($widget);
        $this->assertInstanceOf(WidgetRect::class, $rect, 'the widget has been laid out');
        for ($row = 0; $row < $rect->rows; ++$row) {
            for ($col = 0; $col < $rect->columns; ++$col) {
                if ($key === $widget->tooltipAt($row, $col)?->key) {
                    return [$rect->row + $row, $rect->col + $col];
                }
            }
        }
        self::fail("no cell of the widget answers {$key}");
    }

    /**
     * The first screen cell whose answer from $ask has $key — for surfaces
     * that answer in screen coordinates rather than their own.
     *
     * @param \Closure(int, int): ?Tooltip $ask
     *
     * @return array{int, int}
     */
    private static function screenCellOf(\Closure $ask, string $key, int $rows = 24, int $columns = 160): array
    {
        for ($row = 0; $row < $rows; ++$row) {
            for ($col = 0; $col < $columns; ++$col) {
                if (\str_starts_with($ask($row, $col)->key ?? '', $key)) {
                    return [$row, $col];
                }
            }
        }
        self::fail("no screen cell answers {$key}");
    }

    /** A bare move to a 0-based screen cell, as mode 1003 sends it. */
    private static function moveTo(Tui $tui, int $row, int $col): void
    {
        $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $col + 1, $row + 1));
    }

    private function shown(Ide $ide): ?Tooltip
    {
        return Services::resolve($ide, TooltipCenter::class)->shown();
    }

    /** The first row of the screen carrying $needle, after a full frame. */
    private function rowOnScreen(Tui $tui, VirtualTerminal $terminal, string $needle): ?int
    {
        return $this->rowOf($this->screenGrid($tui, $terminal), $needle);
    }

    public function testAFindToggleExplainsItselfAndAKeyTakesTheCard(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\n"]);
        $cell = [0, 0];

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function (Tui $tui): void {
                    $tui->handleInput("\x06"); // ctrl+f
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use (&$cell): void {
                    $this->frame($tui);
                    $cell = $this->cellOf($ide, $tui->getById('find-bar'), 'find:case');
                    self::moveTo($tui, $cell[0], $cell[1]);
                    $this->assertNull($this->shown($ide), 'the dwell first');

                    $this->awaitPainted($tui, $terminal, 'Match case  Alt+C');
                    $grid = $this->screenGrid($tui, $terminal);
                    $card = $this->rowOf($grid, 'Match case');
                    $this->assertNotNull($card);
                    $this->assertInstanceOf(Tooltip::class, $this->shown($ide));
                    // Flush under the toggle: its top border on the next row.
                    $this->assertSame($cell[0] + 1, $card - 1, 'the card hangs right under the toggle');
                },
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use (&$cell): void {
                    self::moveTo($tui, $cell[0], $cell[1] + 1);
                    $this->assertSame('find:case', $this->shown($ide)?->key, 'a move on the same toggle keeps the card');

                    $tui->handleInput('x'); // typed into the find field
                    $this->assertNull($this->shown($ide), 'a key takes the card');
                    $this->assertNull($this->rowOnScreen($tui, $terminal, 'Match case'), 'and it leaves the screen');
                },
            ],
        );
    }

    public function testSweepingAlongTheToolbarNeedsNoSecondDwellAndLeavingTakesTheCard(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function (Tui $tui): void {
                    $tui->handleInput("\x06");
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    [$row, $col] = $this->cellOf($ide, $tui->getById('find-bar'), 'find:regex');
                    self::moveTo($tui, $row, $col);
                    $this->awaitPainted($tui, $terminal, 'Regular expression');

                    [$row, $col] = $this->cellOf($ide, $tui->getById('find-bar'), 'find:word');
                    self::moveTo($tui, $row, $col);
                    $this->assertSame('find:word', $this->shown($ide)?->key, 'at once: a card was up');
                    $this->assertNotNull($this->rowOnScreen($tui, $terminal, 'Whole word  Alt+W'));

                    self::moveTo($tui, $row + 10, 5); // the code
                    $this->assertNull($this->shown($ide));
                    $this->assertNull($this->rowOnScreen($tui, $terminal, 'Whole word'));
                },
            ],
        );
    }

    public function testAPressTakesTheCard(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function (Tui $tui): void {
                    $tui->handleInput("\x06");
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    [$row, $col] = $this->cellOf($ide, $tui->getById('find-bar'), 'find:word');
                    self::moveTo($tui, $row, $col);
                    $this->awaitPainted($tui, $terminal, 'Whole word');

                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1));
                    $this->assertNull($this->shown($ide), 'the press took it — and toggled whole word');
                },
            ],
        );
    }

    /**
     * A resting pointer sends nothing when the window changes, so the frame
     * after a resize asks again at the pointer's cell: an element still under
     * it keeps its card, laid out against the new screen.
     */
    public function testAResizeKeepsTheCardOfTheElementStillUnderThePointer(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function (Tui $tui): void {
                    $tui->handleInput("\x06");
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    [$row, $col] = $this->cellOf($ide, $tui->getById('find-bar'), 'find:case');
                    self::moveTo($tui, $row, $col);
                    $this->awaitPainted($tui, $terminal, 'Match case');

                    $terminal->simulateResize(160, 14); // shorter: the toggle stays where it is
                    $tui->requestRender();
                },
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    $this->frame($tui); // the resized layout, then the frame that asks again
                    $this->assertSame('find:case', $this->shown($ide)?->key);
                    $this->assertNotNull($this->rowOnScreen($tui, $terminal, 'Match case'), 'painted on the new screen');
                },
            ],
        );
    }

    /** An element the resize carried out from under the pointer takes its card with it. */
    public function testAResizeThatMovesTheElementAwayTakesTheCard(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function (Tui $tui): void {
                    $tui->handleInput("\x06");
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    [$row, $col] = $this->cellOf($ide, $tui->getById('find-bar'), 'find:case');
                    self::moveTo($tui, $row, $col);
                    $this->awaitPainted($tui, $terminal, 'Match case');

                    // Narrow enough that the sidebar folds away and the toggle
                    // moves left with the editor.
                    $terminal->simulateResize(60, 24);
                    $tui->requestRender();
                },
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->await(
                        function () use ($tui, $ide): bool {
                            $this->frame($tui);

                            return 'find:case' !== $this->shown($ide)?->key;
                        },
                        what: 'the card to leave with its toggle',
                    );
                    $this->assertNull($this->rowOnScreen($tui, $terminal, 'Match case'));
                },
            ],
        );
    }

    public function testTheStatusBarSaysWhatItsCellsAre(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    [$row, $col] = $this->cellOf($ide, $tui->getById('status'), 'status:goto_line');
                    self::moveTo($tui, $row, $col);
                    $this->awaitPainted($tui, $terminal, 'click to go to a line');
                    $this->assertSame('status:goto_line', $this->shown($ide)?->key);
                    $this->assertLessThan($row, $this->rowOnScreen($tui, $terminal, 'click to go to a line') ?? \PHP_INT_MAX, 'the bottom row has no room below: the card sits above');
                },
            ],
        );
    }

    public function testFindInFilesTagsSpellThemselvesOut(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function (Tui $tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $modal = Widgets::editor($tui)->findModal;
                    $this->await(function () use ($tui, $modal): bool {
                        $this->frame($tui);

                        return null !== $modal->lastBox();
                    }, what: 'the modal to lay out');
                    [$row, $col] = self::screenCellOf($modal->tooltipAt(...), 'find-modal:preserve');
                    self::moveTo($tui, $row, $col);
                    $this->awaitPainted($tui, $terminal, 'Preserve case when replacing');
                    $this->assertSame('find-modal:preserve', $this->shown($ide)?->key);
                },
            ],
        );
    }

    public function testAToastsCloseSaysWhatItDoes(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, Notifier::class)->notify('a note that stays a while', ToastSeverity::Info, 60.0);
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'a note that stays a while');
                    $toasts = Probe::object(Services::resolve($ide, Notifier::class), 'toasts');
                    $this->assertInstanceOf(ToastCenter::class, $toasts);
                    $translator = new Translator('en');
                    [$row, $col] = self::screenCellOf(static fn (int $r, int $c): ?Tooltip => $toasts->tooltipAt($r, $c, $translator), 'toast:');
                    self::moveTo($tui, $row, $col);
                    $this->awaitPainted($tui, $terminal, 'Dismiss');

                    self::moveTo($tui, $row + 1, $col - 5); // the card's words
                    $this->assertNull($this->shown($ide), 'a card says itself, and hides what is under it');
                },
            ],
        );
    }

    /** A modal surface owns the pointer: nothing beneath it answers, however long the pointer rests. */
    public function testModalSurfacesSilenceTheWorkspace(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\n"]);
        $cell = [0, 0];

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, TooltipCenter::class)->setDelayMs(0);
                    $tui->handleInput("\x06"); // ctrl+f
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use (&$cell): void {
                    $this->frame($tui);
                    $cell = $this->cellOf($ide, $tui->getById('find-bar'), 'find:regex');
                    self::moveTo($tui, $cell[0], $cell[1]);
                    $this->assertSame('find:regex', $this->shown($ide)?->key, 'the control: with no dwell, at once');
                    self::moveTo($tui, 20, 100);

                    Services::resolve($ide, CommandRegistry::class)->run('goto_file');
                },
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use (&$cell): void {
                    self::moveTo($tui, $cell[0], $cell[1]);
                    $this->assertNull($this->shown($ide), 'a picker is open');
                    $tui->handleInput("\x1b"); // close it
                },
                400 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use (&$cell): void {
                    $tui->handleInput("\x1b[<2;60;10M"); // a right-click in the code: the context menu
                    $tui->handleInput("\x1b[<2;60;10m");
                    self::moveTo($tui, $cell[0], $cell[1]);
                    $this->assertNull($this->shown($ide), 'a context menu is open');
                    $tui->handleInput("\x1b");
                },
                500 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use (&$cell): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_welcome');
                    $this->frame($tui);
                    self::moveTo($tui, $cell[0], $cell[1]);
                    $this->assertNull($this->shown($ide), 'the Welcome modal is open');
                },
            ],
        );
    }

    /** An open view owns the pointer; its panes answer for themselves, a changed setting among them. */
    public function testASettingsRowSaysWhatItsMarkMeans(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", '.porta.json' => '{"settings": {"autoPairs": false}}']);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    [$row, $col] = $this->cellOf($ide, $tui->getById('settings-form'), 'settings:autoPairs');
                    self::moveTo($tui, $row, $col);
                    $this->awaitPainted($tui, $terminal, 'Changed from its default');
                },
            ],
        );
    }

    /**
     * With inlay hints shown as cards, resting on the word a hint annotates
     * says what the hint would have — and in the line's own mode the same
     * cell asks for no card, the hint being right there.
     */
    public function testAnInlayHintIsACardOnlyInTooltipMode(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['status.php' => "<?php\nenum StatusTone: string\n{\n}\n"]);

        $this->runIde(
            $project,
            $project->path('status.php'),
            [
                100 => static function (Tui $tui): void {
                    $editor = Widgets::editor($tui);
                    $editor->display->setInlayHints(InlayHintMode::Inline);
                    $editor->decorations->setInlayHints([1 => [['col' => 15, 'text' => ' 46 references']]]);
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'StatusTone 46 references');
                    $editor = Widgets::editor($tui);
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($editor);
                    $this->assertInstanceOf(WidgetRect::class, $rect);
                    $cell = [$rect->row + 1, $rect->col + $editor->gutterWidth() + 7]; // on `StatusTone`
                    self::moveTo($tui, ...$cell);
                    $this->assertNull(Probe::get(Services::resolve($ide, TooltipCenter::class), 'pending'), 'inline: the hint is in the line, no card is asked for');

                    $editor->display->setInlayHints(InlayHintMode::Tooltip);
                    self::moveTo($tui, $cell[0], $cell[1] + 1);
                    // The inline frame already carried the words: await the
                    // card itself, then read that it is painted.
                    $this->await(fn (): bool => 'inlay:1:5' === $this->shown($ide)?->key, what: 'the inlay card');
                    $this->awaitPainted($tui, $terminal, 'StatusTone: string');
                    $this->assertNotNull($this->rowOnScreen($tui, $terminal, '46 references'), 'the card says it');
                    $this->assertNull($this->rowOnScreen($tui, $terminal, 'StatusTone 46'), 'and the line itself no longer does');
                },
            ],
        );
    }

    public function testACompletionKindSpellsItselfOut(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function (Tui $tui): void {
                    $editor = Widgets::editor($tui);
                    $editor->getDocument()->setCursor(1, 5);
                    $editor->showCompletions([new CompletionItem(label: 'Countable', insertText: 'Countable', kind: 'iface', detail: '')]);
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    $editor = Widgets::editor($tui);
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($editor);
                    $this->assertInstanceOf(WidgetRect::class, $rect);
                    $translator = new Translator('en');
                    [$row, $col] = self::screenCellOf(static fn (int $r, int $c): ?Tooltip => $editor->completion->tooltipAt($r - $rect->row, $c - $rect->col, $translator), 'completion-kind:');
                    self::moveTo($tui, $row, $col);
                    $this->awaitPainted($tui, $terminal, 'Interface');
                },
            ],
        );
    }
}
