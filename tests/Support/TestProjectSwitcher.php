<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Config\IdeConfig;
use Porta\Config\WorkspaceTrust;
use Porta\Editor\BufferManager;
use Porta\History\RecentProjectsStore;
use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Porta\Workbench\ProjectSwitcher;
use Porta\Workbench\UnsavedChangesGuard;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The real project switcher over doubles: a trust store with no home, so no
 * answer is ever written, and whatever bus a test listens on for the
 * `ProjectOpenRequested` a switch ends in.
 */
final class TestProjectSwitcher
{
    public static function create(
        ?EventDispatcherInterface $events = null,
        string $rootDir = '/project',
        ?CodeEditorWidget $editor = null,
        ?Notifier $notifier = null,
        ?BufferManager $buffers = null,
    ): ProjectSwitcher {
        $notifier ??= new Notifier(new StatusBarWidget(), TestTui::create());
        $translator = new Translator('en');

        return new ProjectSwitcher(
            new RecentProjectsStore(),
            $editor ?? new CodeEditorWidget(),
            $notifier,
            $translator,
            $events ?? new EventDispatcher(),
            new UnsavedChangesGuard($buffers ?? new BufferManager(), $notifier, $translator, new MockClock()),
            new WorkspaceTrust(null),
            IdeConfig::load($rootDir),
            $rootDir,
        );
    }
}
