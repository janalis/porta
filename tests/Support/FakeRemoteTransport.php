<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\BufferManager;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteEntry;
use Porta\Filesystem\RemoteFile;
use Porta\Filesystem\RemoteFilesystem;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteStamp;
use Porta\Filesystem\RemoteTransport;
use Porta\Filesystem\RemoteTransportSource;
use Porta\I18n\Translator;
use Porta\Search\FileIndex;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A server that is a local directory: every verb acts on $root as a transport
 * would act on the server's project directory.
 *
 * Answers come back at once — unless {@see $hold} is set, in which case each
 * waits in {@see $held} until {@see release()} — so a test can look at the
 * IDE while a fetch or an upload is still on its way. {@see $calls} records
 * every verb with its path, in order. {@see $fail} makes the next verb (or
 * the next one on a given path) answer that error instead. {@see $walks}
 * turns on the optional walk, and a command "runs on the server" with `sh`
 * in its directory.
 */
final class FakeRemoteTransport extends RemoteTransport
{
    public bool $hold = false;

    /** @var list<\Closure(): void> */
    public array $held = [];

    /** @var list<string> "verb path" per call, in order */
    public array $calls = [];

    /** @var array<string, string> path ('*' for any) => the error its next verb answers */
    public array $fail = [];

    public bool $closed = false;

    /** Whether this server answers {@see walk()} — off by default, as a transport that cannot walk is. */
    public bool $walks = false;

    /** While set, the server cannot be reached: every verb answers this. */
    public ?string $down = null;

    /** When set, the next verb throws it instead of answering — a bug, not a server. */
    public ?\Throwable $throws = null;

    /** The failure that means the login was refused. */
    public const string LOGIN_DENIED = 'login denied';

    public function __construct(
        public readonly string $root,
    ) {}

    #[\Override]
    public function list(string $path, \Closure $done): void
    {
        $this->answer('list', $path, $done, function () use ($path): array|string {
            if (!\is_dir($this->at($path))) {
                return $path.': no such directory';
            }
            $entries = [];
            $names = \scandir($this->at($path));
            foreach (\array_diff(false === $names ? [] : $names, ['.', '..']) as $name) {
                $entries[] = new RemoteEntry($name, \is_dir($this->at($path).'/'.$name), $this->stamp($path.'/'.$name));
            }

            return $entries;
        });
    }

    #[\Override]
    public function fetch(string $path, \Closure $done): void
    {
        $this->answer('fetch', $path, $done, fn (): RemoteFile|string => \is_file($this->at($path))
            ? new RemoteFile((string) \file_get_contents($this->at($path)), $this->stamp($path))
            : $path.': no such file');
    }

    #[\Override]
    public function put(string $path, string $bytes, ?RemoteStamp $expected, \Closure $done): void
    {
        $this->answer('put', $path, $done, function () use ($path, $bytes, $expected): ?RemoteStamp {
            if (null !== $expected && !$expected->equals($this->stamp($path))) {
                return null;
            }
            if (!\is_dir(\dirname($this->at($path)))) {
                \mkdir(\dirname($this->at($path)), recursive: true);
            }
            \file_put_contents($this->at($path), $bytes);

            return $this->stamp($path);
        });
    }

    #[\Override]
    public function mkdir(string $path, \Closure $done): void
    {
        $this->answer('mkdir', $path, $done, function () use ($path): null {
            if (!\is_dir($this->at($path))) {
                \mkdir($this->at($path), recursive: true);
            }

            return null;
        });
    }

    #[\Override]
    public function rename(string $from, string $to, \Closure $done): void
    {
        $this->answer('rename', $from.' '.$to, $done, fn (): ?string => \file_exists($this->at($from)) && \rename($this->at($from), $this->at($to)) ? null : $from.': no such file');
    }

    #[\Override]
    // @mago-expect lint:no-boolean-flag-parameter -- the transport contract's own signature
    public function remove(string $path, bool $isDir, \Closure $done): void
    {
        $this->answer($isDir ? 'rmdir' : 'remove', $path, $done, function () use ($path): null {
            new Filesystem()->remove($this->at($path));

            return null;
        });
    }

    #[\Override]
    public function walk(string $path, array $skip, \Closure $done): bool
    {
        if (!$this->walks) {
            return false;
        }
        $this->answer('walk', $path, $done, function () use ($path, $skip): array {
            $entries = [];
            $walk = function (string $relative) use (&$walk, &$entries, $path, $skip): void {
                $names = \scandir($this->at(\ltrim($path.'/'.$relative, characters: '/')));
                foreach (\array_diff(false === $names ? [] : $names, ['.', '..']) as $name) {
                    $child = '' === $relative ? $name : $relative.'/'.$name;
                    $isDir = \is_dir($this->at(\ltrim($path.'/'.$child, characters: '/')));
                    if ($isDir && \in_array($name, $skip, strict: true)) {
                        continue;
                    }
                    $entries[] = new RemoteEntry($child, $isDir, $this->stamp(\ltrim($path.'/'.$child, characters: '/')));
                    if ($isDir) {
                        $walk($child);
                    }
                }
            };
            $walk('');

            return $entries;
        });

        return true;
    }

    /** The command, run in the server's directory — `sh` standing in for the server's own. */
    #[\Override]
    public function remoteCommand(array $argv): array
    {
        $this->calls[] = 'run '.$argv[0];

        return ['sh', '-c', 'cd -- "$1" && shift && exec "$@"', 'sh', $this->root, ...$argv];
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
    }

    #[\Override]
    public function refusesLogin(string $failure): bool
    {
        return self::LOGIN_DENIED === $failure;
    }

    /** What a plugin does once it is given a password: tell core to try now. */
    public function passwordGiven(): void
    {
        $this->retryNow();
    }

    /**
     * A remote project at $cacheDir served by this fake — what a test needs
     * when the remote filesystem is a collaborator, not the thing tested.
     */
    public function filesystem(string $cacheDir, ?StatusBarWidget $status = null, ?EventDispatcherInterface $events = null): RemoteFilesystem
    {
        return self::over($this, new RemoteSpec('ssh', 'example.org', '/srv'), $cacheDir, $status, $events);
    }

    /** A remote project at $cacheDir served by $transport — a fake one, or a real one against a real server. */
    public static function over(RemoteTransport $transport, RemoteSpec $spec, string $cacheDir, ?StatusBarWidget $status = null, ?EventDispatcherInterface $events = null): RemoteFilesystem
    {
        return new RemoteFilesystem(
            new ProjectLocation($spec),
            $cacheDir,
            new readonly class($transport) implements RemoteTransportSource {
                public function __construct(
                    private RemoteTransport $transport,
                ) {}

                public function remoteTransport(RemoteSpec $spec): RemoteTransport
                {
                    return $this->transport;
                }
            },
            new Notifier($status ?? new StatusBarWidget(), TestTui::create()),
            new Translator('en'),
            new BufferManager(),
            new FileIndex($cacheDir),
            $events ?? new EventDispatcher(),
        );
    }

    /** Answer everything held, in the order it was asked. */
    public function release(): void
    {
        $this->hold = false;
        while ([] !== $this->held) {
            \array_shift($this->held)();
        }
    }

    /** The stamp the server has for $path now — what a listing would say. */
    public function stamp(string $path): RemoteStamp
    {
        $file = $this->at($path);

        return new RemoteStamp(\is_file($file) ? (int) \filesize($file) : 0, \is_file($file) ? (int) \filemtime($file) : 0);
    }

    private function at(string $path): string
    {
        return '' === $path ? $this->root : $this->root.'/'.\ltrim($path, characters: '/');
    }

    /**
     * @param \Closure(mixed): void $done
     * @param \Closure(): mixed     $work
     */
    private function answer(string $verb, string $path, \Closure $done, \Closure $work): void
    {
        $this->calls[] = $verb.' '.$path;
        $throws = $this->throws;
        $this->throws = null;
        if (null !== $throws) {
            throw $throws;
        }
        $key = \array_key_exists($path, $this->fail) ? $path : '*';
        $error = $this->fail[$key] ?? $this->down;
        unset($this->fail[$key]);
        $reply = static fn () => $done($error ?? $work());
        if ($this->hold) {
            $this->held[] = static function () use ($reply): void {
                $reply();
            };

            return;
        }
        $reply();
    }
}
