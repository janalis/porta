<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Config\PortaHome;
use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\History\RestartSnapshot;
use Porta\Support\Files;
use Revolt\EventLoop;

/**
 * Unsaved work outliving the window. A desktop session can end without anyone
 * being asked: the application is killed, the machine logs out, a force quit,
 * a crash, a power cut. While the session runs, what is unsaved is copied
 * every half minute; a hangup copies it once more and keeps it. The next time
 * the project opens, it comes back.
 *
 * Ending on purpose — quitting, closing the window twice to discard — forgets
 * the copy: work thrown away does not return.
 */
#[AsIdeService]
final class DesktopRecovery
{
    /** This session's own copy: one per session, so two windows on one project keep both. */
    private ?string $directory = null;

    private bool $kept = false;

    private ?string $timer = null;

    public function __construct(
        private readonly DesktopSession $session,
        private readonly BufferManager $buffers,
        private readonly string $rootDir,
        private readonly RestartSnapshot $snapshots = new RestartSnapshot(),
        private readonly float $everySeconds = 30.0,
    ) {}

    /** Starts copying, in a desktop launch only. */
    public function start(): void
    {
        if ($this->session->active && null === $this->timer) {
            $this->timer = EventLoop::unreference(EventLoop::repeat($this->everySeconds, $this->preserve(...)));
        }
    }

    /** The session ended on purpose: no more copies, and the last one is forgotten. */
    public function stop(): void
    {
        if (null !== $this->timer) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
        if (!$this->kept) {
            $this->forget();
        }
    }

    /** The session is ending unasked: one last copy, kept for next time. */
    public function keep(): void
    {
        $this->preserve();
        $this->kept = null !== $this->directory;
    }

    /** The unsaved work as it stands now; nothing unsaved means no copy at all. */
    public function preserve(): void
    {
        $folder = self::folder($this->rootDir);
        if (!$this->session->active || null === $folder) {
            return;
        }
        $this->forget();
        if (0 === $this->buffers->dirtyCount()) {
            return;
        }
        $this->directory = $folder.'/'.\bin2hex(\random_bytes(8));
        $this->snapshots->save($this->directory, $this->rootDir, $this->buffers);
    }

    private function forget(): void
    {
        if (null !== $this->directory) {
            $this->snapshots->acknowledge($this->directory);
            $this->directory = null;
        }
    }

    /** @return string|null a kept session of $root, oldest first, when a desktop launch has one to resume */
    public static function pending(string $root, DesktopSession $session): ?string
    {
        $folder = self::folder($root);
        if (null === $folder || !$session->active) {
            return null;
        }
        $kept = [];
        foreach ((array) \glob($folder.'/*/session.json') as $file) {
            $kept[(string) $file] = (int) \filemtime((string) $file);
        }
        \asort($kept);

        return [] === $kept ? null : \dirname(\array_key_first($kept));
    }

    private static function folder(string $root): ?string
    {
        $home = PortaHome::base();

        return null === $home ? null : $home.'/.porta/recovery/'.\hash('xxh128', Files::canonical($root));
    }
}
