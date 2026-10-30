<?php

declare(strict_types=1);

namespace Porta\Presence;

use Porta\Support\Files;

/**
 * The board in this project's own `.porta/presence/`: what two windows on
 * one folder share — or two machines, when the folder is on a disk they both
 * mount. Every answer comes at once.
 *
 * A beacon is written beside its file and renamed over it, so a reader never
 * sees one half written. Nor written, nor taken back, through a link
 * ({@see Files::throughLink()}): `.porta/` comes with a clone.
 */
final class LocalPresenceBoard extends PresenceBoard
{
    private readonly string $dir;

    public function __construct(
        private readonly string $rootDir,
    ) {
        $this->dir = $rootDir.'/'.self::DIR;
    }

    #[\Override]
    public function cadence(): array
    {
        return ['via' => 'local', 'tick' => 1.0, 'heartbeat' => 5.0];
    }

    #[\Override]
    public function publish(Beacon $beacon, \Closure $done): void
    {
        $file = $this->dir.'/'.self::file($beacon->id);
        $temporary = $file.'.tmp';
        // @mago-expect lint:no-error-control-operator -- a directory that cannot be made is answered below, as a board that cannot be written
        $ready = !Files::throughLink($this->rootDir, $temporary) && (\is_dir($this->dir) || @\mkdir($this->dir, permissions: 0o777, recursive: true) || \is_dir($this->dir));
        // @mago-expect lint:no-error-control-operator -- nor can one that is there but read-only: the write warns as well as failing, and the failure is the answer
        $written = $ready && false !== @\file_put_contents($temporary, $beacon->toJson()) && \rename($temporary, $file);
        $done($written ? null : 'cannot write '.$file);
    }

    #[\Override]
    public function read(\Closure $done): void
    {
        // @mago-expect lint:no-error-control-operator -- a directory that cannot be listed is answered below, as a board that cannot be read
        $names = \is_dir($this->dir) ? @\scandir($this->dir) : [];
        if (false === $names) {
            $done('cannot read '.$this->dir);

            return;
        }
        $beacons = [];
        foreach ($names as $name) {
            // @mago-expect lint:no-error-control-operator -- the file may be withdrawn between the listing and the read: then there is no beacon
            $json = null === self::idOf($name) ? false : @\file_get_contents($this->dir.'/'.$name);
            $beacon = false === $json ? null : Beacon::fromJson($json);
            if (null !== $beacon) {
                $beacons[] = $beacon;
            }
        }
        $done($beacons);
    }

    #[\Override]
    public function withdraw(string $id, ?\Closure $done = null): void
    {
        $file = $this->dir.'/'.self::file($id);
        if (\is_file($file) && !Files::throughLink($this->rootDir, $file)) {
            \unlink($file);
        }
        $done?->__invoke();
    }
}
