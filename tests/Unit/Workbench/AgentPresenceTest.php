<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Event\AgentActivity;
use Porta\Event\AgentLeft;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\TestAgentPresence;
use Porta\Ui\Glyphs;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\AgentActivityKind;
use Porta\Workbench\AgentPresence;

/** Which agent is at work on which file, until it goes quiet or leaves — and what the editor is told. */
final class AgentPresenceTest extends TestCase
{
    private FakeScheduler $scheduler;

    private CodeEditorWidget $editor;

    private function presence(): AgentPresence
    {
        $this->scheduler = new FakeScheduler();
        $this->editor = new CodeEditorWidget();

        return TestAgentPresence::create($this->scheduler, $this->editor);
    }

    private static function at(string $agent, string $name, string $path, AgentActivityKind $kind = AgentActivityKind::Editing, int $line = 0, int $column = 0, float $fade = 10.0): AgentActivity
    {
        return new AgentActivity($agent, $name, $path, $kind, $line, $column, $fade);
    }

    public function testEachAgentKeepsOneColourAndItsCaretIsPaintedInItsFile(): void
    {
        $presence = $this->presence();

        $presence->onAgentActivity(self::at('a', 'Claude', '/p/a.php', line: 3, column: -2));
        $presence->onAgentActivity(self::at('b', 'Codex', '/p/a.php', AgentActivityKind::Reading, line: 1, column: 4));
        $presence->onAgentActivity(self::at('a', 'Claude', '/p/b.php', AgentActivityKind::Reading));

        $this->assertSame(
            [['name' => 'Claude', 'kind' => AgentActivityKind::Editing, 'slot' => 0], ['name' => 'Codex', 'kind' => AgentActivityKind::Reading, 'slot' => 1]],
            $presence->in('/p/a.php'),
        );
        $this->assertSame([['name' => 'Claude', 'kind' => AgentActivityKind::Reading, 'slot' => 0]], $presence->in('/p/b.php'), 'the same agent, the same colour');
        $this->assertSame(['/p/a.php', '/p/b.php'], $presence->files());
        $this->assertSame(
            [3 => [['col' => 0, 'label' => Glyphs::AGENT.' Claude', 'slot' => 0]], 1 => [['col' => 4, 'label' => Glyphs::AGENT.' Codex', 'slot' => 1]]],
            $this->editor->decorations->remoteCaretsIn('/p/a.php'),
            'a column before the line start is clamped to it',
        );
        $this->assertSame([], $this->editor->decorations->remoteCaretsIn('/p/other.php'));

        $presence->onAgentActivity(self::at('b', 'Codex', '/p/c.php', line: -1));
        $this->assertCount(1, $presence->in('/p/c.php'), 'on the file');
        $this->assertSame([], $this->editor->decorations->remoteCaretsIn('/p/c.php'), 'with no caret in it');
        $this->assertSame([], $this->editor->decorations->remoteCaretsIn(null));
    }

    public function testASelectionAnchorIsHandedToTheEditor(): void
    {
        $presence = $this->presence();

        $presence->onAgentActivity(new AgentActivity('a', 'Ana', '/p/a.php', AgentActivityKind::Editing, 2, 1, 10.0, anchorLine: 0, anchorColumn: -4));
        $presence->onAgentActivity(new AgentActivity('b', 'Bo', '/p/a.php', AgentActivityKind::Editing, 4, 0, 10.0));

        $this->assertSame(
            [
                0 => [['start' => 0, 'end' => \PHP_INT_MAX, 'slot' => 0]],
                1 => [['start' => 0, 'end' => \PHP_INT_MAX, 'slot' => 0]],
                2 => [['start' => 0, 'end' => 1, 'slot' => 0]],
            ],
            $this->editor->decorations->remoteSelectionsIn('/p/a.php'),
            'a column before the line start is clamped to it; no anchor, no selection',
        );
    }

    public function testQuietFadesEachFileOnItsOwnAndAnotherReportReArmsIt(): void
    {
        $presence = $this->presence();
        $presence->onAgentActivity(self::at('a', 'Claude', '/p/a.php', fade: 5.0));
        $presence->onAgentActivity(self::at('a', 'Claude', '/p/b.php'));
        $presence->onAgentActivity(self::at('a', 'Claude', '/p/a.php', fade: 7.0));

        $this->assertSame(['1'], $this->scheduler->cancelled, 'the second report on a.php re-armed its timer');
        $this->assertSame([2, 3], \array_keys($this->scheduler->scheduled), 'numeric ids come back as ints');
        $this->assertSame(7.0, $this->scheduler->delays['3']);

        $this->scheduler->fire('3');
        $this->assertSame([], $presence->in('/p/a.php'), 'a.php faded');
        $this->assertCount(1, $presence->in('/p/b.php'), 'b.php has its own timer');
        $this->assertSame([], $this->editor->decorations->remoteCaretsIn('/p/a.php'));
    }

    public function testAFadeFollowsTheFileWhereARenameMovedIt(): void
    {
        $presence = $this->presence();
        $presence->onAgentActivity(self::at('a', 'Claude', '/p/src/a.php'));
        $presence->onAgentActivity(self::at('a', 'Claude', '/p/b.php'));

        $presence->onFileRenamed(new FileRenamed('/p/src', '/p/lib'));

        $this->assertSame([], $presence->in('/p/src/a.php'));
        $this->assertCount(1, $presence->in('/p/lib/a.php'), 'a directory carries its files');
        $this->assertCount(1, $presence->in('/p/b.php'), 'a file outside it stays');
        $this->scheduler->fire('1');
        $this->assertSame([], $presence->in('/p/lib/a.php'), 'the timer armed for the old path fades the new one');
        $this->assertSame(['/p/b.php'], $presence->files());
    }

    public function testLeavingOrADeleteTakesItAllAtOnce(): void
    {
        $presence = $this->presence();
        $presence->onAgentActivity(self::at('a', 'Claude', '/p/a.php'));
        $presence->onAgentActivity(self::at('a', 'Claude', '/p/b.php'));
        $presence->onAgentActivity(self::at('b', 'Codex', '/p/b.php'));

        $presence->onFileDeleted(new FileDeleted('/p/b.php'));
        $this->assertSame(['/p/a.php'], $presence->files());
        $this->assertSame(['2', '3'], $this->scheduler->cancelled, 'both timers on the deleted file');

        $presence->onAgentActivity(self::at('b', 'Codex', '/p/d.php'));
        $presence->onAgentLeft(new AgentLeft('a'));
        $this->assertSame(['/p/d.php'], $presence->files(), 'another agent\'s file stays');
        $presence->onAgentLeft(new AgentLeft('b'));
        $this->assertSame([], $presence->files());
        $this->assertSame(['2', '3', '1', '4'], $this->scheduler->cancelled);

        $presence->onAgentActivity(self::at('c', 'Third', '/p/c.php'));
        $this->assertSame(0, $presence->in('/p/c.php')[0]['slot'], 'a slot left free is handed out again');
    }

    public function testAFifthAgentSharesAColour(): void
    {
        $presence = $this->presence();
        foreach (['a', 'b', 'c', 'd', 'e'] as $agent) {
            $presence->onAgentActivity(self::at($agent, $agent, '/p/'.$agent.'.php'));
        }

        $this->assertSame([0, 1, 2, 3, 0], \array_map(static fn (string $agent): int => $presence->in('/p/'.$agent.'.php')[0]['slot'], ['a', 'b', 'c', 'd', 'e']));
    }
}
