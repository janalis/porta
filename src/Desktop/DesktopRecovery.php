<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Config\PortaHome;
use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\History\RestartSnapshot;
use Porta\Support\Files;

/**
 * Unsaved work outliving the window. A desktop session can end without anyone
 * being asked: the application is killed, the machine logs out, a force quit.
 * Each reaches Porta as a hangup, and what was unsaved is kept for the next
 * time this project opens. Discarding on purpose never comes through here.
 */
#[AsIdeService]
final readonly class DesktopRecovery
{
    public function __construct(
        private DesktopSession $session,
        private BufferManager $buffers,
        private string $rootDir,
        private RestartSnapshot $snapshots = new RestartSnapshot(),
    ) {}

    /** The session is ending unasked: keeps what would be lost, in a desktop launch only. */
    public function preserve(): void
    {
        $directory = self::directory($this->rootDir);
        if (!$this->session->active || 0 === $this->buffers->dirtyCount() || null === $directory) {
            return;
        }
        $this->snapshots->acknowledge($directory);
        $this->snapshots->save($directory, $this->rootDir, $this->buffers);
    }

    /** @return string|null the kept session of $root, when a desktop launch has one to resume */
    public static function pending(string $root, DesktopSession $session): ?string
    {
        $directory = self::directory($root);

        return $session->active && null !== $directory && \is_file($directory.'/session.json') ? $directory : null;
    }

    private static function directory(string $root): ?string
    {
        $home = PortaHome::base();

        return null === $home ? null : $home.'/.porta/recovery/'.\hash('xxh128', Files::canonical($root));
    }
}
