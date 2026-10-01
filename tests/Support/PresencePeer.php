<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\BufferManager;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteTransport;
use Porta\Filesystem\RemoteTransportSource;
use Porta\Presence\Identity;
use Porta\Presence\Participant;
use Porta\Presence\Participants;
use Porta\Presence\PresenceSync;

/**
 * One Porta at work on a server's project, without its screen: its own
 * cache, its open file and caret, its roster — and its presence, through a
 * transport of its own to the same server as the others. What a functional
 * test puts two of on one server to see them find each other.
 *
 * Its clock runs with the machine's, plus whatever {@see wait()} adds: a
 * peer can be made to have waited out the others' heartbeats without the
 * test waiting a minute.
 */
final class PresencePeer
{
    public readonly PresenceSync $sync;

    public readonly BufferManager $buffers;

    public readonly Participants $participants;

    public readonly FakeScheduler $scheduler;

    public readonly RecordingEvents $events;

    public readonly TempProject $cache;

    private float $skip = 0.0;

    /** @param \Closure(): RemoteTransport $transport a fresh one to the server, as a plugin hands it */
    public function __construct(string $name, RemoteSpec $spec, \Closure $transport)
    {
        $this->cache = new TempProject(['src/A.php' => "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n"]);
        $this->buffers = new BufferManager();
        $this->scheduler = new FakeScheduler();
        $this->events = new RecordingEvents();
        $identity = new Identity();
        $identity->rename($name);
        $this->participants = TestParticipants::create(scheduler: $this->scheduler, rootDir: $this->cache->dir, identity: $identity);
        $this->sync = new PresenceSync(
            new ProjectLocation($spec),
            new readonly class($transport) implements RemoteTransportSource {
                /** @param \Closure(): RemoteTransport $transport */
                public function __construct(
                    private \Closure $transport,
                ) {}

                public function remoteTransport(RemoteSpec $spec): RemoteTransport
                {
                    return ($this->transport)();
                }
            },
            $this->cache->dir,
            $this->buffers,
            $this->participants,
            $identity,
            $this->events,
            fn (): float => \microtime(true) + $this->skip,
        );
    }

    /** Put the caret on $line of $relative, as the editor would. */
    public function at(string $relative, int $line): void
    {
        $path = (string) \realpath($this->cache->path($relative));
        $buffer = $this->buffers->get($path) ?? $this->buffers->open($path, (string) \file_get_contents($path));
        $this->buffers->setActive($path);
        $buffer->document->setCursor($line, 0);
    }

    /** Let $seconds pass for this peer, and only for it. */
    public function wait(float $seconds): void
    {
        $this->skip += $seconds;
    }

    /** Whether its look is still waiting for the server. */
    public function looking(): bool
    {
        return true === Probe::get($this->sync, 'busy');
    }

    /** @return array<string, int> name => line of everyone it sees, by name */
    public function sees(): array
    {
        $seen = [];
        foreach ($this->participants->all() as $participant) {
            $seen[$participant->name] = $participant->line;
        }

        return $seen;
    }

    /** @return list<Participant> */
    public function roster(): array
    {
        return $this->participants->all();
    }
}
