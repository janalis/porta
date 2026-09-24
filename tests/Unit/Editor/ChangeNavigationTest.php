<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\ChangeNavigation;
use Porta\I18n\Translator;
use Porta\Tests\Support\StatusReads;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class ChangeNavigationTest extends TestCase
{
    use StatusReads;

    public function testChangeStartsAreTheFirstLineOfEachRun(): void
    {
        $this->assertSame([1, 4, 9], ChangeNavigation::changeStarts([5 => 'added', 1 => 'modified', 4 => 'modified', 2 => 'modified', 9 => 'deleted']));
        $this->assertSame([], ChangeNavigation::changeStarts([]));
    }

    /** The walk steps between the runs the strip shows, wrapping past either end, and says so when there are none. */
    public function testTheWalkStepsBetweenTheMarkedRunsAndWraps(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('/p/a.php', "a\nb\nc\nd\ne\nf\ng\n");
        $status = new StatusBarWidget();
        $navigation = new ChangeNavigation($editor, new Notifier($status, new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24))), new Translator('en'));

        $navigation->next();
        $this->assertStringContainsString('No changes in this file', $this->statusRow($status));

        $editor->decorations->setVcsMarks([1 => ChangeMarkKind::Modified, 2 => ChangeMarkKind::Modified, 5 => ChangeMarkKind::Added]);
        $line = static fn (): int => $editor->getDocument()->cursorLine;
        $navigation->next();
        $this->assertSame(1, $line());
        $navigation->next();
        $this->assertSame(5, $line(), 'the run is one change: past line 2');
        $navigation->next();
        $this->assertSame(1, $line(), 'wrapping');
        $navigation->previous();
        $this->assertSame(5, $line(), 'and back');
        $navigation->previous();
        $this->assertSame(1, $line());
    }
}
