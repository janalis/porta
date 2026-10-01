<?php

declare(strict_types=1);

namespace Porta\Presence;

use Porta\Filesystem\RemoteEntry;
use Porta\Filesystem\RemoteFile;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteStamp;
use Porta\Filesystem\RemoteTransport;
use Porta\Filesystem\RemoteTransportSource;

/**
 * The board in the SERVER's `.porta/presence/`, beside the project there:
 * the one place everybody who opens that directory reaches, whether over ssh
 * or FTP, from whichever machine.
 *
 * Through a transport of its own from the plugin serving the project — an
 * ssh one shares the project's connection (ControlMaster), an FTP one makes
 * one per call anyway — and with as few calls as it can: a listing per look,
 * and a fetch only for a beacon whose stamp moved since the last (and once
 * more as it settles, {@see self::SETTLE}); the rest are remembered. A
 * transport not there yet (no enabled plugin serves the scheme) is not
 * "out of touch": nothing is written, nobody is read, and the next look asks
 * again.
 */
final class RemotePresenceBoard extends PresenceBoard
{
    /**
     * How long, in seconds, a stamp is not trusted to mean "unchanged" after
     * it was first seen. A stamp is a size and a time in whole SECONDS: a
     * beacon rewritten in the same second, to the same size — a caret one
     * line down — has the stamp it had. So a new stamp is fetched once more
     * when it has had this long to settle; after that, it is the file's.
     */
    public const float SETTLE = 2.0;

    /** @var array<string, array{stamp: RemoteStamp, beacon: ?Beacon, seen: float, settled: bool}> file => what was fetched of it, when its stamp was first seen, and whether it was fetched again since */
    private array $known = [];

    /** Whether the directory is known to be there. */
    private bool $made = false;

    private ?RemoteTransport $transport = null;

    /**
     * @param (\Closure(): float)|null $clock seconds, this machine's; microtime by default
     */
    public function __construct(
        private readonly RemoteSpec $spec,
        private readonly RemoteTransportSource $transports,
        private readonly ?\Closure $clock = null,
    ) {}

    #[\Override]
    public function cadence(): array
    {
        return (
            \in_array($this->spec->scheme, ['ftp', 'ftps'], strict: true)
                ? ['via' => 'ftp', 'tick' => 10.0, 'heartbeat' => 30.0]
                : ['via' => 'ssh', 'tick' => 3.0, 'heartbeat' => 15.0]
        );
    }

    #[\Override]
    public function publish(Beacon $beacon, \Closure $done): void
    {
        $this->made($done, function (RemoteTransport $transport) use ($beacon, $done): void {
            $transport->put($this->path(self::file($beacon->id)), $beacon->toJson(), null, static function (RemoteStamp|string|null $stamp) use ($done): void {
                $done(\is_string($stamp) ? $stamp : null);
            });
        });
    }

    #[\Override]
    public function read(\Closure $done): void
    {
        $this->made(static function () use ($done): void {
            $done([]);
        }, function (RemoteTransport $transport) use ($done): void {
            $transport->list(
                $this->path(''),
                /** @param list<RemoteEntry>|string $entries */
                function (array|string $entries) use ($transport, $done): void {
                    if (\is_string($entries)) {
                        $done($entries);

                        return;
                    }
                    $this->fetchMoved($transport, $entries, $done);
                },
            );
        });
    }

    #[\Override]
    public function withdraw(string $id): void
    {
        unset($this->known[self::file($id)]);
        $this->transport()?->remove($this->path(self::file($id)), false, static function (): void {});
    }

    /**
     * Run $then with the transport once the directory is there. Made once a
     * session: whether it was already there is of no interest — a listing
     * that fails afterwards says what a failed mkdir would have.
     *
     * @param \Closure(string): void          $none what answers when no transport serves the project
     * @param \Closure(RemoteTransport): void $then
     */
    private function made(\Closure $none, \Closure $then): void
    {
        $transport = $this->transport();
        if (null === $transport) {
            $none('no transport');

            return;
        }
        if ($this->made) {
            $then($transport);

            return;
        }
        $transport->mkdir($this->path(''), function () use ($transport, $then): void {
            $this->made = true;
            $then($transport);
        });
    }

    /**
     * Fetch every beacon whose stamp moved, then answer them all — the ones
     * that did not move as they were.
     *
     * @param list<RemoteEntry>                   $entries
     * @param \Closure(list<Beacon>|string): void $done
     */
    private function fetchMoved(RemoteTransport $transport, array $entries, \Closure $done): void
    {
        $files = [];
        foreach ($entries as $entry) {
            if ($entry->isDir || null === self::idOf($entry->name)) {
                continue;
            }
            $files[$entry->name] = $entry->stamp;
        }
        // What left the board is forgotten, so the memory never outgrows it.
        $this->known = \array_intersect_key($this->known, $files);
        // One for the loop itself: an answer that comes back at once must not
        // count as the last before the next fetch is even asked.
        $waiting = 1;
        $answer = function () use (&$waiting, $files, $done): void {
            if (0 !== $waiting) {
                return;
            }
            $beacons = [];
            foreach (\array_keys($files) as $file) {
                $beacon = $this->known[$file]['beacon'] ?? null;
                if (null !== $beacon) {
                    $beacons[] = $beacon;
                }
            }
            $done($beacons);
        };
        $now = null === $this->clock ? \microtime(true) : ($this->clock)();
        foreach ($files as $file => $stamp) {
            $known = $this->known[$file] ?? null;
            $same = null !== $known && $known['stamp']->equals($stamp);
            if (null !== $known && $same && ($known['settled'] || ($now - $known['seen']) < self::SETTLE)) {
                continue;
            }
            ++$waiting;
            $transport->fetch($this->path($file), function (RemoteFile|string $fetched) use ($file, $stamp, $known, $same, $now, &$waiting, $answer): void {
                --$waiting;
                // One that could not be fetched — withdrawn since the listing,
                // say — is no beacon, and is asked again next time.
                if ($fetched instanceof RemoteFile) {
                    $this->known[$file] = [
                        'stamp' => $stamp,
                        'beacon' => Beacon::fromJson($fetched->bytes),
                        'seen' => null !== $known && $same ? $known['seen'] : $now,
                        'settled' => $same,
                    ];
                }
                $answer();
            });
        }
        --$waiting;
        $answer();
    }

    #[\Override]
    public function close(): void
    {
        $this->transport?->close();
    }

    /** The transport, asked of the plugins until one answers. */
    private function transport(): ?RemoteTransport
    {
        return $this->transport ??= $this->transports->remoteTransport($this->spec);
    }

    private function path(string $file): string
    {
        // Relative to the project, as every transport verb takes a path.
        return '' === $file ? self::DIR : self::DIR.'/'.$file;
    }
}
