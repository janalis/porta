<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Support\Scheduler;
use Porta\Ui\Theme\Styles;
use Porta\Widget\EditorPaneWidget;
use Porta\Widget\ViewerTabHandler;

/**
 * Builds each plugin-registered pane and pushes it into the core registry —
 * the {@see \Porta\Command\CommandRegistryPopulator} shape, and for the same
 * reason: the registry belongs to the consumer, and the plugin subsystem is one
 * more registrant rather than the thing core has to ask.
 *
 * Runs once at the constructor tail, after plugins have loaded and committed.
 * A pane factory that throws costs its own plugin its pane, reported through
 * the manager's fault barrier, and leaves every other pane registered — the
 * file it claimed then falls through to the text path, which is exactly what
 * happens when no pane answers an id at all.
 */
#[AsIdeService]
final class PaneRegistrar
{
    public function __construct(
        private readonly EditorPaneSource $source,
        private readonly EditorPanes $panes,
        private readonly Styles $styles,
        private readonly Translator $translator,
        private readonly Scheduler $scheduler,
        private readonly ViewerTabHandler $tabs,
    ) {}

    /**
     * Drop $plugin's panes — the REMOVAL path only.
     *
     * Disabling a plugin stops its routing claim and leaves its pane standing,
     * because a tab opened before the toggle keeps rendering in it (the
     * "applies to files opened afterwards" contract the viewer plugins already
     * keep). A removed plugin's pane has no such claim on the slot: its code is
     * gone from disk and nothing may route to it again.
     */
    public function forget(string $plugin): void
    {
        $this->panes->forget($plugin);
    }

    public function register(): void
    {
        $services = new PaneServices($this->styles, $this->translator, $this->scheduler, $this->tabs);
        foreach ($this->source->panes() as $registration) {
            try {
                /** @var mixed $pane the factory is a bare Closure at the boundary; what it returns is checked below */
                $pane = $registration['pane']($services);
            } catch (\Throwable $error) {
                $this->source->reportPaneFailure($registration['plugin'], $error);
                continue;
            }
            if (!$pane instanceof EditorPaneWidget) {
                $this->source->reportPaneFailure($registration['plugin'], new \LogicException('a pane factory must return an EditorPaneWidget'));
                continue;
            }
            $pane->setHandler($this->tabs);
            $this->panes->register($pane, $registration['load'], $registration['plugin']);
        }
    }
}
