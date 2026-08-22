<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\EditorPanes;
use Porta\Tests\Support\TestPane;

/** The registry that replaces core's hard-coded list of viewer widgets. */
final class EditorPanesTest extends TestCase
{
    public function testAPaneAnswersToItsIdAndCarriesItsLoader(): void
    {
        $panes = new EditorPanes();
        $pane = new TestPane('demo');
        $panes->register($pane, static fn (string $path): string => 'payload of '.basename($path));

        $this->assertSame($pane, $panes->paneFor('demo'));
        $loader = $panes->loaderFor('demo');
        $this->assertNotNull($loader);
        $this->assertSame('payload of a.demo', $loader('/p/a.demo'));
        $this->assertSame('payload of a.demo', $panes->load('demo', '/p/a.demo'), 'the same read, through the id');
        $this->assertSame([$pane], $panes->all());
    }

    public function testAnUnknownIdIsNullSoTheFileTakesTheTextPath(): void
    {
        $panes = new EditorPanes();

        // The contract `registerViewer()` has always promised: an id no pane
        // answers to is not an error, it is the text editor.
        $this->assertNull($panes->paneFor('nothing'));
        $this->assertNull($panes->loaderFor('nothing'));
        $this->assertSame([], $panes->all());
    }

    public function testLoadingThroughAnIdNoPaneAnswersToIsARefusal(): void
    {
        // The caller that already asked `paneFor()` cannot reach this; the one
        // that knows its id by heart (core's help pane) can, if that pane is
        // ever not registered — and a refusal reads better than a silent
        // nothing-happens.
        $panes = new EditorPanes();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No pane answers to "help"/');
        $panes->load('help', '/p/docs/README.md');
    }

    public function testRegisteringTheSameIdTwiceKeepsTheLastPane(): void
    {
        $panes = new EditorPanes();
        $first = new TestPane('shared');
        $second = new TestPane('shared');
        $panes->register($first, static fn (): string => 'first');
        $panes->register($second, static fn (): string => 'second');

        $this->assertSame($second, $panes->paneFor('shared'));
        $this->assertCount(1, $panes->all(), 'one id, one pane');
    }

    public function testForgettingAPluginDropsOnlyItsPanes(): void
    {
        // The deactivate half of the symmetry: disabling a viewer plugin has to
        // make its pane stop answering, or a disabled plugin still owns the slot.
        $panes = new EditorPanes();
        $mine = new TestPane('mine');
        $theirs = new TestPane('theirs');
        $core = new TestPane('help');
        $panes->register($mine, static fn (): string => '', 'my-plugin');
        $panes->register($theirs, static fn (): string => '', 'their-plugin');
        $panes->register($core, static fn (): string => '');

        $panes->forget('my-plugin');

        $this->assertNull($panes->paneFor('mine'));
        $this->assertNull($panes->loaderFor('mine'));
        $this->assertSame($theirs, $panes->paneFor('theirs'));
        $this->assertSame($core, $panes->paneFor('help'), 'core registrations have no owner and survive');
    }

    public function testForgettingAPluginRemovesItsPaneEvenWhenAnEarlierEntryBelongsToSomeoneElse(): void
    {
        // A `continue`/`break` mix-up in the loop would stop scanning as soon
        // as it hit an owner it must skip — silently keeping every later
        // match. Registering the non-matching owner FIRST is what would
        // expose that: `mine` must still be dropped even though it comes
        // after `theirs` in iteration order.
        $panes = new EditorPanes();
        $theirs = new TestPane('theirs');
        $mine = new TestPane('mine');
        $panes->register($theirs, static fn (): string => '', 'their-plugin');
        $panes->register($mine, static fn (): string => '', 'my-plugin');

        $panes->forget('my-plugin');

        $this->assertNull($panes->paneFor('mine'), 'a match after a non-match must still be dropped');
        $this->assertSame($theirs, $panes->paneFor('theirs'));
    }
}
