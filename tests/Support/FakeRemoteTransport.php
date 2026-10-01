<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Filesystem\RemoteEntry;
use Porta\Filesystem\RemoteFile;
use Porta\Filesystem\RemoteStamp;
use Porta\Filesystem\RemoteTransport;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A server that is a local directory: every verb acts on $root as a transport
 * would act on the server's project directory.
 *
 * Answers come back at once — unless {@see $hold} is set, in which case each
 * waits in {@see $held} until {@see release()} — so a test can look at the
 * IDE while a fetch or an upload is still on its way. {@see $calls} records
 * every verb with its path, in order. {@see $fail} makes the next verb (or
 * the next one on a given path) answer that error instead.
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

    public function __construct(
        public readonly string $root,
    ) {}

    #[\Override]
    public function list(string $path, \Closure $done): void
    {
        $this->answer('list', $path, $done, function () use ($path): array {
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
        $this->answer('fetch', $path, $done, fn (): RemoteFile => new RemoteFile((string) \file_get_contents($this->at($path)), $this->stamp($path)));
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
    public function close(): void
    {
        $this->closed = true;
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
        $key = \array_key_exists($path, $this->fail) ? $path : '*';
        $error = $this->fail[$key] ?? null;
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
