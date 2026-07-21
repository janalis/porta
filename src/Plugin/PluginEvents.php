<?php

declare(strict_types=1);

namespace Porta\Plugin;

use Porta\Event\BufferOpened;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\Event\ThemeChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The bridge from the app event bus to the plugin hook API. Plugin listeners
 * are pre-wrapped by {@see PluginManager} (a throwing plugin never propagates)
 * and keyed by plugin so a live-removed plugin's hooks can be dropped
 * ({@see removeFor}). The bridge runs at priority -100 — third-party code
 * observes every buffer event only after all core listeners ran — and
 * deduplicates consecutive identical switch paths so re-showing the active
 * buffer (config re-apply, focus churn) stays silent for plugins while core
 * listeners still see every show.
 */
final class PluginEvents
{
    public const array EVENTS = ['fileOpened', 'fileSaved', 'bufferSwitched', 'themeChanged'];

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

    private function emit(string $event, string $path): void
    {
        foreach ($this->listeners[$event] ?? [] as $pluginListeners) {
            foreach ($pluginListeners as $listener) {
                $listener($path);
            }
        }
    }
}
