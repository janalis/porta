<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Presence;

use PHPUnit\Framework\TestCase;
use Porta\Event\ParticipantJoined;
use Porta\Event\ParticipantLeft;
use Porta\Event\StatusCellsChanged;
use Porta\Presence\Identity;
use Porta\Presence\Participant;
use Porta\Presence\Participants;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\RecordingEvents;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestParticipants;
use Porta\Ui\Glyphs;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\StatusTone;

/** Everyone else at work on the project, source by source — and a lost link, kept for a while and then let go. */
final class ParticipantsTest extends TestCase
{
    private RecordingEvents $events;

    private FakeScheduler $scheduler;

    private RecordingFileOpener $opener;

    private CodeEditorWidget $editor;

    private Identity $identity;

    private function participants(string $rootDir = '/project'): Participants
    {
        $this->events = new RecordingEvents();
        $this->scheduler = new FakeScheduler();
        $this->opener = new RecordingFileOpener();
        $this->editor = new CodeEditorWidget();
        $this->identity = new Identity();
        $this->identity->rename('Me');

        return TestParticipants::create($this->events, $this->scheduler, $this->opener, $this->editor, $rootDir, $this->identity);
    }

    /** @return list<string> */
    private function facts(): array
    {
        return \array_map(
            static fn (object $e): string => ($e instanceof ParticipantJoined ? '+' : '-').$e->id.' '.$e->name.' '.$e->via,
            \array_values(\array_filter($this->events->dispatched, static fn (object $e): bool => $e instanceof ParticipantJoined || $e instanceof ParticipantLeft)),
        );
    }

    public function testEachSourceReplacesItsOwnListAndTheDifferenceIsWhatFires(): void
    {
        $participants = $this->participants();
        $this->assertSame([], $participants->statusCells(), 'nobody else: nothing to say');

        $participants->set('presence', [new Participant('a', 'Ada', 'local'), new Participant('b', 'Bo', 'ssh')]);
        $participants->set('plugin:assist', [new Participant('g1', 'Cy', 'assist')]);
        $participants->set('presence', [new Participant('b', 'Bo', 'ssh'), new Participant('c', 'Di', 'ftp')]);

        $this->assertSame(['+presence/a Ada local', '+presence/b Bo ssh', '+plugin:assist/g1 Cy assist', '-presence/a Ada local', '+presence/c Di ftp'], $this->facts());
        $this->assertSame(['Bo', 'Di', 'Cy'], \array_map(static fn (Participant $p): string => $p->name, $participants->all()));
        $cell = $participants->statusCells()[0];
        $this->assertSame(Glyphs::PARTICIPANTS.' 3', $cell->text);
        $this->assertSame(StatusTone::Subtle, $cell->tone);
        $this->assertSame("Also at work here: Bo, Di, Cy\nThey see you as Me", $cell->tooltip);

        $participants->set('presence', []);
        $participants->set('plugin:assist', []);
        $this->assertSame([], $participants->all());
        $this->assertSame([], $participants->statusCells());
        $this->assertSame('plugin:assist', Participants::pluginSource('assist'));
    }

    public function testTheStatusBarIsToldOnlyWhenWhatItShowsChanged(): void
    {
        $participants = $this->participants();
        $changes = fn (): int => \count($this->events->take(StatusCellsChanged::class));

        $participants->set('presence', [new Participant('a', 'Ada', 'local')]);
        $this->assertSame(1, $changes(), 'someone came');
        $participants->set('presence', [new Participant('a', 'Ada', 'local')]);
        $this->assertSame(0, $changes(), 'the same list said again, every look: nothing to repaint');
        $participants->set('presence', [new Participant('a', 'Ada', 'local', '/project/a.php', 3)]);
        $this->assertSame(1, $changes(), 'she opened a file: the menu says where');
        $participants->outOfTouch('presence');
        $this->assertSame(1, $changes(), 'lost: the cell is marked');
        $participants->set('presence', [new Participant('a', 'Ada', 'local', '/project/a.php', 3)]);
        $this->assertSame(1, $changes(), 'found again with the same people: the mark goes');
        $participants->set('presence', []);
        $this->assertSame(1, $changes());
    }

    public function testALostLinkKeepsItsPeopleAsLastSeenThenLetsThemGo(): void
    {
        $participants = $this->participants();
        $participants->outOfTouch('presence');
        $this->assertSame([], $this->scheduler->scheduled, 'nobody to keep: nothing to time');

        $participants->set('presence', [new Participant('a', 'Ada', 'ssh')]);
        $participants->set('plugin:assist', [new Participant('g1', 'Cy', 'assist')]);
        $participants->outOfTouch('presence');
        $participants->outOfTouch('presence');
        $this->assertCount(1, $this->scheduler->scheduled, 'one timer, however often it is said');
        $this->assertSame([Participants::GRACE], \array_values($this->scheduler->delays));
        $this->assertTrue($participants->isOutOfTouch('presence'));
        $this->assertFalse($participants->isOutOfTouch('plugin:assist'));
        $cell = $participants->statusCells()[0];
        $this->assertSame(Glyphs::PARTICIPANTS.' 2 '.Glyphs::OUT_OF_TOUCH, $cell->text, 'still counted, marked');
        $this->assertSame(StatusTone::Accent, $cell->tone);
        $this->assertSame("Also at work here: Ada, Cy\nThey see you as Me\nThe link to some of them is lost: shown as last seen, for a minute at most", $cell->tooltip);
        \assert(null !== $cell->menu, description: 'the roster\'s cell is clickable');
        $this->assertSame(['Ada — ssh '.Glyphs::OUT_OF_TOUCH, 'Cy — assist'], \array_map(static fn (MenuItem $item): string => $item->label, ($cell->menu)()));

        // Back in time: the timer is called off, and they are as they are now.
        $participants->set('presence', [new Participant('a', 'Ada', 'ssh')]);
        $this->assertFalse($participants->isOutOfTouch('presence'));
        $this->assertSame([], $this->scheduler->scheduled);
        $this->assertSame(Glyphs::PARTICIPANTS.' 2', $participants->statusCells()[0]->text);

        // Not back in time: they go, and say so.
        $participants->outOfTouch('presence');
        $this->events->dispatched = [];
        $this->scheduler->fire();
        $this->assertSame(['-presence/a Ada ssh'], $this->facts());
        $this->assertFalse($participants->isOutOfTouch('presence'));
        $this->assertSame(['Cy'], \array_map(static fn (Participant $p): string => $p->name, $participants->all()));
    }

    public function testAClickTakesYouToTheirFileAtTheirLine(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\n\n\nx\n"]);
        $participants = $this->participants($project->dir);
        $participants->set('presence', [
            new Participant('a', 'Ada', 'local', $project->path('src/A.php'), 4),
            new Participant('b', 'Bo', 'local', $project->path('src/A.php')),
            new Participant('c', 'Cy', 'ftp'),
        ]);
        \assert(null !== $participants->statusCells()[0]->menu, description: 'the roster\'s cell is clickable');
        [$ada, $bo, $cy] = ($participants->statusCells()[0]->menu)();

        $this->assertSame('Ada — local · src/A.php', $ada->label);
        $this->assertSame('Cy — ftp', $cy->label);
        $this->assertTrue($ada->enabled());
        $this->assertFalse($cy->enabled(), 'no file open: nowhere to go');
        \assert(!\in_array(null, [$ada->run, $bo->run, $cy->run], strict: true), description: 'every row runs something');
        $this->editor->openFile($project->path('src/A.php'), "<?php\n\n\n\nx\n");
        $this->editor->getDocument()->setCursor(4, 1);
        ($ada->run)();
        $this->assertSame([['path' => $project->path('src/A.php'), 'focus' => true]], $this->opener->opened);
        $this->assertSame([4, 0], [$this->editor->getDocument()->cursorLine, $this->editor->getDocument()->cursorCol], 'their line, from its start');
        ($bo->run)();
        $this->assertSame(4, $this->editor->getDocument()->cursorLine, 'an image, say: no line to go to');
        ($cy->run)();
        $this->assertCount(2, $this->opener->opened, 'run by hand, the disabled row still goes nowhere');

        // Someone on the first line is taken to it too.
        $participants->set('presence', [new Participant('a', 'Ada', 'local', $project->path('src/A.php'), 0)]);
        $participants->outOfTouch('presence');
        \assert(null !== $participants->statusCells()[0]->menu, description: 'the roster\'s cell is clickable');
        [$ada] = ($participants->statusCells()[0]->menu)();
        $this->assertSame('Ada — local · src/A.php '.Glyphs::OUT_OF_TOUCH, $ada->label, 'where they were, then that it is as last seen');
        \assert(null !== $ada->run, description: 'the row runs something');
        ($ada->run)();
        $this->assertSame(0, $this->editor->getDocument()->cursorLine);
    }
}
