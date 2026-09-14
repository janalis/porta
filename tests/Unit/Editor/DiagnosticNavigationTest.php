<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\DiagnosticNavigation;
use Porta\Editor\DiagnosticSeverity;
use Porta\I18n\Translator;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class DiagnosticNavigationTest extends TestCase
{
    private BufferManager $buffers;
    private CodeEditorWidget $editor;
    private StatusBarWidget $status;

    /** @param array<int, list<array{severity: DiagnosticSeverity, message: string, col: int}>> $diagnostics */
    private function navigation(array $diagnostics, int $caret = 0, ?string $paneId = null): DiagnosticNavigation
    {
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();
        $this->status = new StatusBarWidget();
        $text = \str_repeat("// line\n", times: 12);

        $buffer = null === $paneId ? $this->buffers->open('/p/a.php', $text) : $this->buffers->openPane('/p/a.php', $paneId, payload: null);
        $this->buffers->setActive('/p/a.php');
        $buffer->diagnostics = $diagnostics;
        $this->editor->showDocument('/p/a.php', $buffer->document);
        $buffer->document->setCursor($caret, 0);

        return new DiagnosticNavigation($this->buffers, $this->editor, new Notifier($this->status, TestTui::create(80, 24)), new Translator('en'));
    }

    /** @return array<int, list<array{severity: DiagnosticSeverity, message: string, col: int}>> */
    private static function threeProblems(): array
    {
        return [
            2 => [['severity' => DiagnosticSeverity::Error, 'message' => 'first boom', 'col' => 0]],
            6 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'middle warn', 'col' => 0]],
            9 => [['severity' => DiagnosticSeverity::Error, 'message' => 'last boom', 'col' => 0]],
        ];
    }

    private function flashed(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode('', $this->status->render(new RenderContext(100, 1))));
    }

    private function caretLine(): int
    {
        return $this->editor->getDocument()->cursorLine;
    }

    public function testNextWalksForwardFromTheCaretAndSaysWhatIsWrong(): void
    {
        $navigation = $this->navigation(self::threeProblems(), caret: 0);

        $navigation->next();
        $this->assertSame(2, $this->caretLine());
        $this->assertStringContainsString('first boom', $this->flashed(), 'the flash carries the diagnostic, not just the line');
        $this->assertStringContainsString('3:', $this->flashed(), 'numbered the way the gutter numbers it');

        $navigation->next();
        $this->assertSame(6, $this->caretLine());
    }

    public function testPreviousWalksBackward(): void
    {
        $navigation = $this->navigation(self::threeProblems(), caret: 8);

        $navigation->previous();
        $this->assertSame(6, $this->caretLine());

        $navigation->previous();
        $this->assertSame(2, $this->caretLine());
    }

    /** Standing ON a problem still moves — otherwise the key does nothing where you use it most. */
    public function testTheCaretsOwnLineIsNotTheNextProblem(): void
    {
        $navigation = $this->navigation(self::threeProblems(), caret: 6);

        $navigation->next();

        $this->assertSame(9, $this->caretLine());
    }

    /** Wrapping is announced: a silent no-op at the last problem reads as a broken key. */
    public function testPastTheLastProblemItWrapsAndSaysSo(): void
    {
        $navigation = $this->navigation(self::threeProblems(), caret: 11);

        $navigation->next();

        $this->assertSame(2, $this->caretLine());
        $this->assertStringContainsString('Wrapped', $this->flashed());
    }

    public function testBeforeTheFirstProblemPreviousWrapsToTheLast(): void
    {
        $navigation = $this->navigation(self::threeProblems(), caret: 0);

        $navigation->previous();

        $this->assertSame(9, $this->caretLine());
        $this->assertStringContainsString('Wrapped', $this->flashed());
    }

    public function testACleanFileSaysSoAndDoesNotMove(): void
    {
        $navigation = $this->navigation([], caret: 4);

        $navigation->next();

        $this->assertSame(4, $this->caretLine());
        $this->assertStringContainsString('No problems in this file', $this->flashed());
    }

    /** A line whose diagnostics were cleared to [] is not a problem to stop on. */
    public function testAnEmptiedLineIsSkipped(): void
    {
        $navigation = $this->navigation([2 => [], 6 => [['severity' => DiagnosticSeverity::Error, 'message' => 'real', 'col' => 0]]], caret: 0);

        $navigation->next();

        $this->assertSame(6, $this->caretLine());
    }

    public function testAViewerTabHasNoProblemsToWalk(): void
    {
        $navigation = $this->navigation(self::threeProblems(), caret: 0, paneId: 'graph');

        $navigation->next();

        $this->assertSame(0, $this->caretLine());
    }
}
