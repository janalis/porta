<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\MemberNavigation;
use Porta\I18n\Translator;
use Porta\Overlay\State\ToastCenter;
use Porta\Tests\Support\FakeScheduler;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Stepping between declarations, over the scope spans the breadcrumbs bar has
 * memoized all along.
 *
 * Every assertion names the LINE the caret lands on rather than that it moved,
 * because an off-by-one against the scanner's 1-based lines still moves.
 */
final class MemberNavigationTest extends TestCase
{
    private const string CODE = "<?php\n\nnamespace App;\n\nclass Svc\n{\n    public function one(): void\n    {\n    }\n\n    public function two(): void\n    {\n    }\n}\n";

    private CodeEditorWidget $editor;
    private ToastCenter $toasts;

    private function navigation(string $code = self::CODE, string $path = '/p/Svc.php'): MemberNavigation
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->toasts = new ToastCenter(new FakeScheduler(), $tui);
        $this->editor = new CodeEditorWidget();
        $this->editor->showDocument($path, new Document($code));

        return new MemberNavigation(
            $this->editor,
            new Notifier(new StatusBarWidget(), $tui, null, $this->toasts),
            new Translator('en'),
        );
    }

    private function caretLine(): int
    {
        return $this->editor->getDocument()->cursorLine;
    }

    private function lastToast(): string
    {
        $toasts = $this->toasts->toasts();

        return [] === $toasts ? '' : $toasts[0]->message;
    }

    public function testNextStepsToTheClassThenEachMethodInTurn(): void
    {
        // 0-based: class Svc on 4, one() on 6, two() on 10.
        $navigation = $this->navigation();
        $this->editor->getDocument()->setCursor(0, 0);

        $navigation->next();
        $this->assertSame(4, $this->caretLine());

        $navigation->next();
        $this->assertSame(6, $this->caretLine());

        $navigation->next();
        $this->assertSame(10, $this->caretLine());
    }

    public function testPreviousWalksBackTheSameWay(): void
    {
        $navigation = $this->navigation();
        $this->editor->getDocument()->setCursor(12, 0);

        $navigation->previous();
        $this->assertSame(10, $this->caretLine());

        $navigation->previous();
        $this->assertSame(6, $this->caretLine());
    }

    public function testTheDeclarationNameIsWhatTheFlashSays(): void
    {
        // "you have arrived" is not the useful part; which member is.
        $navigation = $this->navigation();
        $this->editor->getDocument()->setCursor(0, 0);

        $navigation->next();

        $this->assertSame('Svc', $this->lastToast());
        // Landing mid-line would put the caret wherever the previous column
        // happened to be, on a line whose text is unrelated to it.
        $this->assertSame(0, $this->editor->getDocument()->cursorCol);
    }

    public function testPastTheLastDeclarationItWrapsAndSaysSo(): void
    {
        // A silent no-op at the last method reads as a broken key.
        $navigation = $this->navigation();
        $this->editor->getDocument()->setCursor(12, 0);

        $navigation->next();

        $this->assertSame(4, $this->caretLine());
        $this->assertSame('Wrapped to Svc', $this->lastToast());
    }

    public function testBeforeTheFirstDeclarationPreviousWrapsToTheLast(): void
    {
        $navigation = $this->navigation();
        $this->editor->getDocument()->setCursor(0, 0);

        $navigation->previous();

        $this->assertSame(10, $this->caretLine());
        $this->assertSame('Wrapped to two', $this->lastToast());
    }

    public function testTheNAMESPACEIsNotADeclarationWorthStoppingOn(): void
    {
        // It spans the whole file, so stepping to it is stepping nowhere. It is
        // on line 2, ahead of the class, so it would be the first stop.
        $navigation = $this->navigation();
        $this->editor->getDocument()->setCursor(0, 0);

        $navigation->next();

        $this->assertNotSame(2, $this->caretLine());
    }

    public function testAFileWithNoDeclarationsSaysSoRatherThanMovingTheCaret(): void
    {
        $navigation = $this->navigation("<?php\n\necho 1;\n");
        $this->editor->getDocument()->setCursor(2, 0);

        $navigation->next();

        $this->assertSame(2, $this->caretLine());
        $this->assertSame('No declarations in this file', $this->lastToast());
    }

    public function testANonPhpBufferHasNothingToStepThrough(): void
    {
        // scopes() is empty for these, so the two cases share one answer.
        $navigation = $this->navigation("# Title\n\nsome prose\n", '/p/README.md');
        $this->editor->getDocument()->setCursor(1, 0);

        $navigation->next();

        $this->assertSame(1, $this->caretLine());
        $this->assertSame('No declarations in this file', $this->lastToast());
    }

    public function testAFreeFunctionCountsAsADeclaration(): void
    {
        $navigation = $this->navigation("<?php\n\nfunction helper(): void\n{\n}\n");
        $this->editor->getDocument()->setCursor(0, 0);

        $navigation->next();

        $this->assertSame(2, $this->caretLine());
        $this->assertSame('helper', $this->lastToast());
    }
}
