<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\History\RestartSnapshot;
use Porta\I18n\Translator;
use Revolt\EventLoop;
use Symfony\Component\Tui\Tui;

/** Coalesce update requests; snapshot and stop only at a safe event-loop boundary. */
#[AsIdeService]
final class UpdateRestart
{
    private bool $pending = false;
    private bool $core = false;
    private ?string $directory = null;
    private ?string $timer = null;

    public function __construct(
        private readonly string $rootDir,
        private readonly BufferManager $buffers,
        private readonly RestartSnapshot $snapshots,
        private readonly Tui $tui,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
    ) {}

    public function plugins(): void
    {
        $this->request();
    }

    public function core(): void
    {
        $this->core = true;
        $this->request();
    }

    private function request(): void
    {
        if (!$this->pending && null === $this->directory) {
            $this->pending = true;
            $this->notifier->flash($this->translator->trans('Porta will restart when active work stops.'), 8.0, 'update-restart');
        }
    }

    /** @param \Closure(): bool $busy includes debugging, terminal jobs, installs and unsent remote writes */
    public function start(\Closure $busy): void
    {
        if (null === $this->timer) {
            $this->timer = EventLoop::repeat(1.0, fn () => $this->attempt($busy));
            EventLoop::unreference($this->timer);
        }
    }

    public function stop(): void
    {
        if (null !== $this->timer) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
    }

    /** @param \Closure(): bool $busy evaluated again immediately before the synchronous snapshot */
    public function attempt(\Closure $busy): void
    {
        if (!$this->pending || null !== $this->directory || $busy()) {
            return;
        }
        $this->pending = false;
        $directory = \sys_get_temp_dir().'/porta-restart-'.\bin2hex(\random_bytes(16));
        try {
            if (!$this->snapshots->save($directory, $this->rootDir, $this->buffers)) {
                throw new \RuntimeException('snapshot write failed');
            }
            $this->directory = $directory;
            $this->tui->stop();
        } catch (\Throwable) {
            $this->notifier->error($this->translator->trans('Could not preserve the session; restart postponed.'), 8.0, 'update-restart');
        }
    }

    public function directory(): ?string
    {
        return $this->directory;
    }

    public function updatesCore(): bool
    {
        return $this->core;
    }
}
