<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Layout\LayoutController;
use Porta\Layout\ViewRegions;
use Porta\Plugin\Api\PluginException;
use Porta\Support\Scheduler;
use Porta\Ui\CursorStyle;
use Porta\Ui\Theme\Styles;

/**
 * Builds each plugin-registered view and registers it — the
 * {@see \Porta\Editor\PaneRegistrar} shape, and for the same reason: the
 * registry belongs to the consumer, and the plugin subsystem is one more
 * registrant rather than the thing core has to ask.
 *
 * The full drain runs once after plugins have loaded and committed;
 * {@see registerFor()} and {@see forget()} are the live enable/disable halves,
 * so a view-contributing plugin toggles without a restart. The region is minted
 * BEFORE the factory is called, so a view can fill both of its panes while it
 * is being constructed — which is what core's own two do.
 *
 * A factory that throws costs its own plugin its view — and its minted region,
 * so nothing empty stays mounted — and leaves every other one registered. The
 * failure is visible: the command that would open it finds nothing registered
 * under that id and the workspace simply never swaps.
 */
#[AsIdeService]
final class ViewRegistrar
{
    /** @var array<string, string> successfully built view id => registering plugin */
    private array $built = [];

    public function __construct(
        private readonly ContributedViewSource $source,
        private readonly ViewRegistry $registry,
        private readonly ViewRegions $regions,
        private readonly ViewHost $host,
        private readonly HitTester $hit,
        private readonly Styles $styles,
        private readonly Translator $translator,
        private readonly Scheduler $scheduler,
        private readonly CursorStyle $cursorStyle,
        private readonly LayoutController $layout,
    ) {}

    public function register(): void
    {
        foreach ($this->source->contributedViews() as $entry) {
            $this->registerEntry($entry);
        }
    }

    /**
     * Build ONE plugin's committed views — the live-enable half. True when a
     * view actually landed, which is the caller's cue to announce
     * {@see \Porta\Event\ViewContributionsChanged} so the layout recompiles
     * (the mirror of Ide's boot-tail applyDirection + applyLayout — a
     * dependency this class cannot hold without a container cycle through the
     * overlay stack).
     */
    public function registerFor(string $plugin): bool
    {
        $built = false;
        foreach ($this->source->contributedViews() as $entry) {
            if ($entry['plugin'] !== $plugin) {
                continue;
            }
            $built = $this->registerEntry($entry) || $built;
        }

        return $built;
    }

    /**
     * Retract everything $plugin built. An OPEN view is closed first — that is
     * what returns the main area to the editor, focuses it, and lets the view
     * cancel its own timers — behind the fault barrier, with the host restored
     * by hand when a dying plugin's close() throws. Keyed off {@see $built}
     * rather than the source, so this works whether the manager's committed
     * set was already filtered or not.
     */
    public function forget(string $plugin): void
    {
        foreach ($this->built as $id => $owner) {
            if ($owner !== $plugin) {
                continue;
            }
            $view = $this->registry->find($id);
            if (null !== $view && $view->isOpen()) {
                try {
                    $view->close();
                } catch (\Throwable $error) {
                    $this->host->restore();
                    $this->source->reportViewFailure($plugin, $error);
                }
            }
            $this->registry->forget($id);
            $this->regions->forget($id);
            $this->layout->forgetView($id);
            unset($this->built[$id]);
        }
    }

    /**
     * Whether the view landed. The double guard refuses an id already built
     * (the boot drain re-running, or an enable racing its own queue) and an id
     * some OTHER registrar owns — a plugin may not displace `settings` or
     * `welcome` live, which register from their own constructors.
     *
     * @param array{plugin: string, id: string, factory: \Closure} $entry
     */
    private function registerEntry(array $entry): bool
    {
        if (\array_key_exists($entry['id'], $this->built) || null !== $this->registry->find($entry['id'])) {
            return false;
        }
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
                // A plugin-contract violation, so it carries the type that
                // already means that — the barrier below reports it against
                // the registering plugin either way.
                throw new PluginException(\sprintf('registerView("%s") must return a ContributedView', $entry['id']));
            }
            $this->registry->register($view);
        } catch (\Throwable $error) {
            // The mint above must not outlive the failure: an empty region
            // left mounted renders as a blank workspace with no error anywhere.
            $this->regions->forget($entry['id']);
            $this->source->reportViewFailure($entry['plugin'], $error);

            return false;
        }
        $this->built[$entry['id']] = $entry['plugin'];

        return true;
    }
}
