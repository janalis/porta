<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Layout\ViewRegions;
use Porta\Support\Scheduler;
use Porta\Ui\CursorStyle;
use Porta\Ui\Theme\Styles;

/**
 * Builds each plugin-registered view and registers it — the
 * {@see \Porta\Editor\PaneRegistrar} shape, and for the same reason: the
 * registry belongs to the consumer, and the plugin subsystem is one more
 * registrant rather than the thing core has to ask.
 *
 * Runs once after plugins have loaded and committed. The region is minted
 * BEFORE the factory is called, so a view can fill both of its panes while it
 * is being constructed — which is what core's own two do.
 *
 * A factory that throws costs its own plugin its view and leaves every other
 * one registered. The failure is visible: the command that would open it finds
 * nothing registered under that id and the workspace simply never swaps.
 */
#[AsIdeService]
final readonly class ViewRegistrar
{
    public function __construct(
        private ContributedViewSource $source,
        private ViewRegistry $registry,
        private ViewRegions $regions,
        private ViewHost $host,
        private HitTester $hit,
        private Styles $styles,
        private Translator $translator,
        private Scheduler $scheduler,
        private CursorStyle $cursorStyle,
    ) {}

    public function register(): void
    {
        foreach ($this->source->contributedViews() as $entry) {
            try {
                /** @var mixed $view */
                $view = $entry['factory'](new ViewServices(
                    $this->styles,
                    $this->translator,
                    $this->scheduler,
                    $this->cursorStyle,
                    $this->regions->mint($entry['id']),
                    $this->host,
                    $this->hit,
                ));
                if (!$view instanceof ContributedView) {
                    throw new \RuntimeException(\sprintf('registerView("%s") must return a ContributedView', $entry['id']));
                }
                $this->registry->register($view);
            } catch (\Throwable $error) {
                $this->source->reportViewFailure($entry['plugin'], $error);
            }
        }
    }
}
