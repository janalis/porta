<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Support\Scheduler;
use Porta\Ui\Graphics\TerminalGraphics;
use Porta\Ui\Theme\Styles;
use Porta\Widget\EditorPaneWidget;
use Porta\Widget\ViewerTabHandler;

/**
 * Builds each plugin-registered pane and pushes it into the core registry —
 * the {@see \Porta\Command\CommandRegistryPopulator} shape, and for the same
 * reason: the registry belongs to the consumer, and the plugin subsystem is one
 * more registrant rather than the thing core has to ask.
 *
 * The full drain runs once at the constructor tail, after plugins have loaded
 * and committed; {@see registerFor()} is the live-activation counterpart, so a
 * plugin enabled mid-session gets its pane built without a restart. A pane
 * factory that throws costs its own plugin its pane, reported through the
 * manager's fault barrier, and leaves every other pane registered — the file it
 * claimed then falls through to the text path, which is exactly what happens
 * when no pane answers an id at all.
 */
#[AsIdeService]
final readonly class PaneRegistrar
{
    public function __construct(
        private EditorPaneSource $source,
        private EditorPanes $panes,
        private Styles $styles,
        private Translator $translator,
        private Scheduler $scheduler,
        private ViewerTabHandler $tabs,
        private TerminalGraphics $graphics,
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
        foreach ($this->source->panes() as $registration) {
            $this->registerOne($registration);
        }
    }

    /**
     * Build ONE plugin's committed panes — the live-activation counterpart of
     * {@see register()}, called when a plugin is enabled mid-session.
     *
     * A pane whose id the SAME plugin already answers is skipped: on
     * disable→re-enable the standing instance was deliberately never forgotten
     * (an open tab is still rendering in it), so it keeps the slot. A different
     * owner — core, or another plugin — is replaced, the same "a plugin
     * claiming a built-in id replaces it" contract the boot drain keeps by
     * running after {@see \Porta\Bootstrap\CorePanes}.
     */
    public function registerFor(string $plugin): void
    {
        foreach ($this->source->panes() as $registration) {
            if ($registration['plugin'] !== $plugin) {
                continue;
            }
            if ($this->panes->ownerOf($registration['id']) === $plugin) {
                continue;
            }
            $this->registerOne($registration);
        }
    }

    /** @param array{plugin: string, id: string, pane: \Closure, load: \Closure} $registration */
    private function registerOne(array $registration): void
    {
        $services = new PaneServices($this->styles, $this->translator, $this->scheduler, $this->tabs, $this->graphics);
        try {
            /** @var mixed $pane the factory is a bare Closure at the boundary; what it returns is checked below */
            $pane = $registration['pane']($services);
        } catch (\Throwable $error) {
            $this->source->reportPaneFailure($registration['plugin'], $error);

            return;
        }
        if (!$pane instanceof EditorPaneWidget) {
            $this->source->reportPaneFailure($registration['plugin'], new \LogicException('a pane factory must return an EditorPaneWidget'));

            return;
        }
        $pane->setHandler($this->tabs);
        $this->panes->register($pane, $registration['load'], $registration['plugin']);
    }
}
