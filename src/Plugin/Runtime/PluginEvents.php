<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Container\AsIdeService;
use Porta\Event\BufferOpened;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\Event\IdeReady;
use Porta\Event\IdeShuttingDown;
use Porta\Event\ThemeChanged;
use Porta\Plugin\Api\PluginException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The bridge from the app event bus to the plugin hook API, plus the two
 * LIFECYCLE moments that are not bus events at all — `ready` and `shutdown`
 * are called by {@see \Porta\Ide} directly, because "the IDE is up" and "the
 * IDE is going down" are positions in run(), not something dispatched.
 *
 * The rest of this class is the bridge proper. Plugin listeners
 * are pre-wrapped by {@see PluginManager} (a throwing plugin never propagates)
 * and keyed by plugin so a live-removed plugin's hooks can be dropped
 * ({@see removeFor}). The bridge runs at priority -100 — third-party code
 * observes every buffer event only after all core listeners ran — and
 * deduplicates consecutive identical switch paths so re-showing the active
 * buffer (config re-apply, focus churn) stays silent for plugins while core
 * listeners still see every show.
 */
#[AsIdeService]
final class PluginEvents
{
    public const array EVENTS = ['fileOpened', 'fileSaved', 'bufferSwitched', 'themeChanged', 'ready', 'shutdown'];

    /** @var array<string, array<string, list<\Closure(string): void>>> event => plugin => listeners */
    private array $listeners = [];

    private ?string $lastShownPath = null;

    /** @param \Closure(string): void $listener receives the absolute path */
    public function subscribe(string $plugin, string $event, \Closure $listener): void
    {
        if (!\in_array($event, self::EVENTS, strict: true)) {
            throw new PluginException(\sprintf('unknown event "%s"', $event));
        }
        $this->listeners[$event][$plugin][] = $listener;
    }

    /** Drop every listener a (removed) plugin subscribed. */
    public function removeFor(string $plugin): void
    {
        foreach (self::EVENTS as $event) {
            unset($this->listeners[$event][$plugin]);
        }
    }

    #[AsEventListener(priority: -100)]
    public function onBufferOpened(BufferOpened $event): void
    {
        $this->emit('fileOpened', $event->buffer->path);
    }

    #[AsEventListener(priority: -100)]
    public function onBufferSaved(BufferSaved $event): void
    {
        $this->emit('fileSaved', $event->buffer->path);
    }

    #[AsEventListener(priority: -100)]
    public function onBufferSwitched(BufferSwitched $event): void
    {
        if ($event->buffer->path === $this->lastShownPath) {
            return;
        }
        $this->lastShownPath = $event->buffer->path;
        $this->emit('bufferSwitched', $event->buffer->path);
    }

    /**
     * Theme switches reach plugins so a backend that bakes the theme's escape
     * codes can rebuild. Still -100, i.e. after core's rebuild listeners — and
     * core's highlight-cache drop deliberately runs *after this*, at -200, so a
     * plugin has rebuilt before the caches it feeds are dropped.
     */
    #[AsEventListener(priority: -100)]
    public function onThemeChanged(ThemeChanged $event): void
    {
        $this->emit('themeChanged', $event->theme);
    }

    /**
     * The IDE is up — {@see IdeReady} says what that means and when.
     *
     * A bus listener like the rest now: the moment used to be a direct call
     * from `Ide`, which made it the one part of the plugin hook surface with no
     * event behind it.
     */
    #[AsEventListener(priority: -100)]
    public function onIdeReady(IdeReady $event): void
    {
        $this->emit('ready', $event->projectRoot);
    }

    /** The IDE is going down — {@see IdeShuttingDown} for the synchronous-only contract. */
    #[AsEventListener(priority: -100)]
    public function onIdeShuttingDown(IdeShuttingDown $event): void
    {
        $this->emit('shutdown', $event->projectRoot);
    }

    private function emit(string $event, string $path): void
    {
        foreach ($this->listeners[$event] ?? [] as $pluginListeners) {
            foreach ($pluginListeners as $listener) {
                $listener($path);
            }
        }
    }
}
