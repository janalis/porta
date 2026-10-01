<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\I18n\Translator;
use Porta\Presence\Identity;
use Porta\Presence\Participants;
use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Tui;

/**
 * The roster with doubles for what it reaches: a recording opener, a
 * scheduler whose timers a test fires by hand, and an editor nobody sees.
 */
final class TestParticipants
{
    public static function create(
        ?EventDispatcherInterface $events = null,
        ?FakeScheduler $scheduler = null,
        ?Tui $tui = null,
        ?RecordingFileOpener $opener = null,
        ?CodeEditorWidget $editor = null,
        string $rootDir = '/project',
        ?Identity $identity = null,
    ): Participants {
        return new Participants(
            $events ?? new EventDispatcher(),
            $scheduler ?? new FakeScheduler(),
            new Translator('en'),
            $tui ?? TestTui::create(),
            $opener ?? new RecordingFileOpener(),
            $editor ?? new CodeEditorWidget(),
            new ProjectPaths($rootDir),
            $identity ?? new Identity(),
        );
    }
}
