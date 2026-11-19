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
        $folder = self::folder($this->rootDir);
        if (!$this->session->active || 0 === $this->buffers->dirtyCount() || null === $folder) {
            return;
        }
        // One per session: two windows on one project ended together keep both.
        $directory = $folder.'/'.\bin2hex(\random_bytes(8));
        $this->snapshots->save($directory, $this->rootDir, $this->buffers);
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
