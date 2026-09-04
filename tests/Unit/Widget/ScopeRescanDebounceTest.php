<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;

/**
 * The debounced structure rescan: typing must not cost a whole-file tokenizer
 * pass per keystroke, but the trail and the sticky band have to catch up once
 * the typing stops.
 *
 * Only reachable with a scheduler to fire, which is why it went untested until
 * the timer's two fields were folded into one value and the lines moved into a
 * diff being mutation-checked.
 */
final class ScopeRescanDebounceTest extends TestCase
{
    private const string BEFORE = "<?php\n\nclass Alpha\n{\n}\n";
    private const string AFTER = "<?php\n\nclass Renamed\n{\n}\n";

    private function editor(FakeScheduler $scheduler): CodeEditorWidget
    {
        $editor = new CodeEditorWidget(scheduler: $scheduler);
        $editor->showDocument('/p/a.php', new Document(self::BEFORE));
        $editor->scopes(); // the first look scans in place and seeds the cache

        return $editor;
    }

    /** @return list<string> */
    private static function labels(CodeEditorWidget $editor): array
    {
        return array_column($editor->scopes(), 'label');
    }

    public function testAnEditDefersTheRescanRatherThanPayingPerKeystroke(): void
    {
        $scheduler = new FakeScheduler();
        $editor = $this->editor($scheduler);

        $editor->getDocument()->replaceAll(self::AFTER);

        self::assertSame(['Alpha'], self::labels($editor), 'the trail stays stale until the typing pauses');
        self::assertCount(1, $scheduler->scheduled, 'and a rescan is armed');
    }

    public function testFiringTheTimerRescansFromTheLIVEDocument(): void
    {
        // Re-keyed from the live document on purpose: it may have moved on, or
        // been swapped, since the edit that armed the timer.
        $scheduler = new FakeScheduler();
        $editor = $this->editor($scheduler);
        $editor->getDocument()->replaceAll(self::AFTER);
        $editor->scopes();

        $scheduler->fire();

        self::assertSame(['Renamed'], self::labels($editor));
    }

    public function testASETTLEDRescanAsksForAFrame(): void
    {
        // Without it the freshly-scanned trail sits behind whatever is on
        // screen until something else happens to repaint.
        $scheduler = new FakeScheduler();
        $editor = $this->editor($scheduler);
        $editor->getDocument()->replaceAll(self::AFTER);
        $editor->scopes();
        $before = Probe::get($editor, 'renderRevision');

        $scheduler->fire();

        self::assertNotSame($before, Probe::get($editor, 'renderRevision'), 'the settled trail needs a frame to show');
    }

    public function testARE_EDITDoesNotPushTheRescanOutForever(): void
    {
        // scopes() runs every frame; re-arming per CALL would let an ambient
        // repaint stream keep the trail stale for as long as anything animates.
        $scheduler = new FakeScheduler();
        $editor = $this->editor($scheduler);
        $editor->getDocument()->replaceAll(self::AFTER);

        $editor->scopes();
        $editor->scopes();
        $editor->scopes();

        self::assertCount(1, $scheduler->scheduled, 'one timer for one version, however often it is asked');
        self::assertSame([], $scheduler->cancelled, 'and nothing re-armed');
    }
}
