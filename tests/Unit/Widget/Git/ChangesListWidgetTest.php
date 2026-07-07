<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Git;

use PHPUnit\Framework\TestCase;
use Porta\Git\ChangeEntry;
use Porta\Widget\Git\ChangesListWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class ChangesListWidgetTest extends TestCase
{
    /** @return list<ChangeEntry> */
    private function entries(): array
    {
        return [
            new ChangeEntry('src/a.php', ' ', 'M'),
            new ChangeEntry('src/b.php', 'M', ' '),
            new ChangeEntry('c.txt', '?', '?'),
            new ChangeEntry('renamed.php', 'R', ' ', 'old.php'),
        ];
    }

    private function plain(array $lines): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }

    public function testRendersHeaderCheckboxesAndStatuses(): void
    {
        $widget = new ChangesListWidget();
        $widget->setEntries($this->entries());

        $screen = $this->plain($widget->render(new RenderContext(44, 8)));

        $this->assertStringContainsString('4 changed files', $screen);
        $this->assertStringContainsString('[ ] M src/a.php', $screen);
        $this->assertStringContainsString('[x] M src/b.php', $screen);
        $this->assertStringContainsString('[ ] A c.txt', $screen, 'untracked shows as an unstaged addition');
        $this->assertStringContainsString('[x] R renamed.php ← old.php', $screen);
    }

    public function testPartialStagingShowsTheMixedCheckbox(): void
    {
        $widget = new ChangesListWidget();
        $widget->setEntries([new ChangeEntry('x.php', 'M', 'M')]);

        $this->assertStringContainsString('[~] M x.php', $this->plain($widget->render(new RenderContext(44, 4))));
    }

    public function testNavigationFiresOnSelect(): void
    {
        $widget = new ChangesListWidget();
        $widget->setFocused(true);
        $widget->setEntries($this->entries());
        $selected = [];
        $widget->onSelect(function (ChangeEntry $entry) use (&$selected): void {
            $selected[] = $entry->path;
        });

        $widget->handleInput("\x1b[B"); // down
        $widget->handleInput("\x1b[B");

        $this->assertSame(['src/b.php', 'c.txt'], $selected);
        $this->assertSame('c.txt', $widget->selectedEntry()?->path);
    }

    public function testSpaceTogglesStagingOnTheSelectedEntry(): void
    {
        $widget = new ChangesListWidget();
        $widget->setFocused(true);
        $widget->setEntries($this->entries());
        $toggled = null;
        $widget->onToggleStage(function (ChangeEntry $entry) use (&$toggled): void {
            $toggled = $entry->path;
        });

        $widget->handleInput(' ');

        $this->assertSame('src/a.php', $toggled);
    }

    public function testDiscardNeedsInlineConfirmation(): void
    {
        $widget = new ChangesListWidget();
        $widget->setFocused(true);
        $widget->setEntries($this->entries());
        $discarded = null;
        $widget->onDiscard(function (ChangeEntry $entry) use (&$discarded): void {
            $discarded = $entry->path;
        });

        $widget->handleInput('d');
        $this->assertStringContainsString('Discard changes?', $this->plain($widget->render(new RenderContext(44, 8))));

        $widget->handleInput('n'); // anything but y cancels
        $this->assertNull($discarded);
        $this->assertStringNotContainsString('Discard changes?', $this->plain($widget->render(new RenderContext(44, 8))));

        $widget->handleInput('d');
        $widget->handleInput('y');
        $this->assertSame('src/a.php', $discarded);
    }

    public function testSetEntriesKeepsSelectionByPath(): void
    {
        $widget = new ChangesListWidget();
        $widget->setFocused(true);
        $widget->setEntries($this->entries());
        $widget->handleInput("\x1b[B"); // select src/b.php

        // b.php became fully staged and the list shrank by one entry.
        $widget->setEntries([
            new ChangeEntry('src/b.php', 'M', ' '),
            new ChangeEntry('c.txt', '?', '?'),
        ]);

        $this->assertSame('src/b.php', $widget->selectedEntry()?->path);

        // The selected path vanished entirely: selection clamps.
        $widget->setEntries([new ChangeEntry('c.txt', '?', '?')]);
        $this->assertSame('c.txt', $widget->selectedEntry()?->path);
    }

    public function testClickTogglesInTheCheckboxColumnAndSelectsElsewhere(): void
    {
        $widget = new ChangesListWidget();
        $widget->setEntries($this->entries());
        $toggled = $selected = null;
        $widget->onToggleStage(function (ChangeEntry $entry) use (&$toggled): void {
            $toggled = $entry->path;
        });
        $widget->onSelect(function (ChangeEntry $entry) use (&$selected): void {
            $selected = $entry->path;
        });

        $widget->handleClick(2, 2); // row of src/b.php, inside [x]
        $this->assertSame('src/b.php', $toggled);

        $widget->handleClick(3, 10); // row of c.txt, on the path
        $this->assertSame('c.txt', $selected);

        $widget->handleClick(0, 2); // header row: ignored
        $widget->handleClick(7, 2); // past the list: ignored
        $this->assertSame('src/b.php', $toggled);
    }

    public function testHoverTintsWithoutTouchingSelectionOrFiringSelect(): void
    {
        $widget = new ChangesListWidget();
        $widget->setEntries($this->entries());
        $selectFired = 0;
        $widget->onSelect(function () use (&$selectFired): void {
            ++$selectFired;
        });

        $this->assertTrue($widget->hoverRow(2));
        $this->assertFalse($widget->hoverRow(2), 'same row: no repaint needed');
        $this->assertSame('src/a.php', $widget->selectedEntry()?->path, 'selection stays where it was');
        $this->assertSame(0, $selectFired, 'hover never loads a diff');

        $rendered = $widget->render(new RenderContext(44, 8));
        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[2], 'the hovered row is tinted');

        $this->assertTrue($widget->hoverRow(0), 'the header row clears the tint');
        $this->assertFalse($widget->clearHover(), 'already clear');
        $this->assertTrue($widget->hoverRow(3));
        $this->assertTrue($widget->clearHover(), 'pointer moved off the list');

        $widget->hoverRow(3);
        $widget->setEntries($this->entries());
        $rendered = $widget->render(new RenderContext(44, 8));
        $this->assertStringNotContainsString("\x1b[48;5;236m", implode('', $rendered), 'setEntries drops a stale hover tint');
    }

    public function testInputHookConsumesFirstAndEntryCountCounts(): void
    {
        $widget = new ChangesListWidget();
        $widget->setFocused(true);
        $widget->setEntries($this->entries());
        $widget->onInput(static fn (string $data): bool => true);

        $widget->handleInput("\x1b[B"); // swallowed by the hook
        $this->assertSame('src/a.php', $widget->selectedEntry()?->path);
        $this->assertSame(4, $widget->entryCount());
    }

    public function testAlwaysFillsItsPane(): void
    {
        $widget = new ChangesListWidget();
        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded());
    }

    public function testTabAndEscapeFireTheirHooks(): void
    {
        $widget = new ChangesListWidget();
        $widget->setFocused(true);
        $widget->setEntries($this->entries());
        $events = [];
        $widget->onFocusNext(function () use (&$events): void {
            $events[] = 'next';
        });
        $widget->onExit(function () use (&$events): void {
            $events[] = 'exit';
        });

        $widget->handleInput("\t");
        $widget->handleInput("\x1b");

        $this->assertSame(['next', 'exit'], $events);
    }
}
