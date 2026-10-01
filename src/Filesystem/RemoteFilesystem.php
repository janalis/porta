<?php

declare(strict_types=1);

namespace Porta\Filesystem;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Workbench\Notifier;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A server's project, seen through its local cache.
 *
 * The cache holds the server's tree as far as anyone has looked: a directory
 * is listed the first time the tree shows it, and every file it holds lands as
 * an empty placeholder. A file's content is fetched the first time it is
 * opened. So everything that only lists or stats works on the cache as on any
 * project, and only what reads content waits — once per file.
 *
 * A change goes to the cache at once and to the server behind it, through the
 * {@see ChangeQueue}. A save while the file's upload is still waiting only
 * changes what that upload sends, so ten saves in a row upload once or twice,
 * never ten times; and an upload is refused when the server's copy changed
 * since it was fetched — the next save overwrites, as the editor's own
 * "changed on disk" does.
 *
 * `.porta/` and `.porta.json` stay on this machine: they are where Porta keeps
 * its state and settings for this copy, and the server's are not this
 * machine's to use.
 */
#[AsIdeService]
final class RemoteFilesystem implements ProjectFilesystem
{
    private const string INDEX = '.porta/remote-index.json';
    public const float POLL_SECONDS = 3.0;
    /** How many directories one poll re-lists. */
    private const int POLL_BATCH = 2;
    private const string SLOT = 'remote';

    private readonly RemoteIndex $index;
    private readonly ChangeQueue $queue;
    /** @var array<string, true> directories listed (or being listed) this session */
    private array $listed = [];
    /** @var array<string, true> listings in flight */
    private array $listing = [];
    /** @var array<string, \Closure(): void> fetches in flight, and what runs when each lands */
    private array $fetching = [];
    /** @var array<string, int> uploads queued and not started, by path — a later save rides the same one */
    private array $uploading = [];
    /** @var array<int, string> what each queued upload will send */
    private array $bytes = [];
    /** @var array<string, int> the upload on its way to the server, by path */
    private array $sending = [];
    /** @var array<int, true> uploads whose path moved or went before they were answered */
    private array $sealed = [];
    private int $uploads = 0;
    /** @var array<string, true> paths whose next upload overwrites what the server has */
    private array $force = [];
    private ?RemoteTransport $transport = null;
    /** Whether "no plugin serves this" was said: once is enough. */
    private bool $complained = false;
    /** @var array<string, true> directories asked for before a transport was there: the first poll lists them */
    private array $wanted = [];
    private int $pollCursor = 0;
    private readonly string $realRoot;

    public function __construct(
        private readonly ProjectLocation $location,
        private readonly string $rootDir,
        private readonly RemoteTransportSource $transports,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly BufferManager $buffers,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
        $this->realRoot = ($resolved = \realpath($rootDir)) ? $resolved : $rootDir;
        $this->index = new RemoteIndex($this->realRoot.'/'.self::INDEX, $fs);
        $this->queue = new ChangeQueue();
    }

    /** The project is closing: whatever the transport has in flight is not waited for. */
    public function close(): void
    {
        $this->transport?->close();
    }

    #[\Override]
    public function available(string $path, \Closure $then): bool
    {
        $relative = $this->relative($path);
        if (null === $relative || !$this->index->isPlaceholder($relative) || !\is_file($path)) {
            return true;
        }
        $this->fetch($relative, $then);

        return false;
    }

    #[\Override]
    public function listed(string $dir): bool
    {
        $relative = $this->relative($dir);
        if (null === $relative || \array_key_exists($relative, $this->listed)) {
            return true;
        }
        $transport = $this->transport();
        if (null === $transport) {
            // Plugins activate after the tree is first built: asked again by
            // the first poll, once there may be one.
            $this->wanted[$relative] = true;

            return false;
        }
        $this->listed[$relative] = true;
        $this->list($transport, $relative);

        return false;
    }

    #[\Override]
    public function dumpFile(string $path, string $content): void
    {
        $this->fs->dumpFile($path, $content);
        $relative = $this->relative($path);
        if (null !== $relative) {
            $this->upload($relative, $content);
        }
    }

    #[\Override]
    public function mkdir(string $path): void
    {
        $this->fs->mkdir($path);
        $relative = $this->relative($path);
        if (null !== $relative) {
            $this->mkdirs($relative);
        }
    }

    #[\Override]
    public function rename(string $from, string $to, bool $overwrite = false): void
    {
        $source = $this->relative($from);
        $this->fs->rename($from, $to, $overwrite);
        $target = $this->relative($to);
        $this->seal($source);
        if (null !== $source && null !== $target) {
            $this->index->move($source, $target);
            $this->listed = self::movedKeys($this->listed, $source, $target);
            $this->change($target, fn (RemoteTransport $transport, \Closure $next) => $transport->rename($source, $target, $this->reporting($target, $next)));

            return;
        }
        // Across the edge of what is synced: to the server it is a file that
        // appeared, or one that went.
        if (null !== $target) {
            $this->uploadTree($target, null);
        }
        if (null !== $source) {
            $this->removeRemote($source, \is_dir($to));
        }
    }

    #[\Override]
    public function remove(string $path): void
    {
        $isDir = \is_dir($path);
        $this->fs->remove($path);
        $relative = $this->relative($path);
        $this->seal($relative);
        if (null !== $relative) {
            $this->removeRemote($relative, $isDir);
        }
    }

    #[\Override]
    public function copy(string $source, string $target): void
    {
        \is_dir($source) ? $this->fs->mirror($source, $target) : $this->fs->copy($source, $target, overwriteNewerFiles: true);
        $relative = $this->relative($target);
        if (null !== $relative) {
            $this->uploadTree($relative, $this->relative($source));
        }
    }

    #[\Override]
    public function pending(): int
    {
        return $this->queue->pending();
    }

    /**
     * Re-list a few directories, so a change made on the server shows up:
     * the ones holding an open file first, then the others in turn. Run on
     * {@see \Porta\Ide}'s timer every {@see POLL_SECONDS}, and once as the
     * loop starts. A local project has no transport, and a slow server gets
     * no second round while the first is still out.
     *
     * Directories the tree asked for before any plugin could reach the server
     * come first, all of them, and alone: they are what the tree is waiting on.
     */
    public function poll(): void
    {
        $transport = $this->transport();
        if ([] !== $this->listing || null === $transport) {
            return;
        }
        $wanted = \array_keys($this->wanted);
        $this->wanted = [];
        foreach ($wanted as $dir) {
            $this->listed[$dir] = true;
            $this->list($transport, $dir);
        }
        if ([] !== $wanted) {
            return;
        }
        $dirs = [];
        foreach ($this->buffers->all() as $buffer) {
            $relative = $this->relative($buffer->path);
            if (null !== $relative) {
                $dirs[] = self::parent($relative);
            }
        }
        $others = \array_keys($this->listed);
        $count = \count($others);
        for ($i = 0; $i < \min($count, self::POLL_BATCH); ++$i) {
            $dirs[] = $others[($this->pollCursor + $i) % $count];
        }
        $this->pollCursor += self::POLL_BATCH;
        foreach (\array_slice(\array_values(\array_unique($dirs)), offset: 0, length: self::POLL_BATCH) as $dir) {
            $this->list($transport, $dir);
        }
    }

    /**
     * List $relative on the server. Never twice at once: listed() marks a
     * directory before it asks, and a poll waits for its last round.
     */
    private function list(RemoteTransport $transport, string $relative): void
    {
        $this->listing[$relative] = true;
        $transport->list($relative, function (array|string $answer) use ($relative): void {
            unset($this->listing[$relative]);
            if (\is_string($answer)) {
                unset($this->listed[$relative]); // asked again the next time the tree shows it
                $this->cannotRead($relative, $answer);

                return;
            }
            /** @var list<RemoteEntry> $answer */
            $this->reconcile($relative, $answer);
        });
    }

    /**
     * Bring directory $dir of the cache in line with the server's listing:
     * new names land as placeholders, a fetched file the server changed is
     * fetched again — the reconciler then reloads its buffer, or warns when
     * it has edits — and a name the server no longer has goes. Anything with
     * a change of ours still on its way is left alone: the server's word on
     * it is about to be out of date.
     *
     * @param list<RemoteEntry> $entries
     */
    private function reconcile(string $dir, array $entries): void
    {
        $local = $this->absolute($dir);
        $seen = [];
        foreach ($entries as $entry) {
            $relative = self::join($dir, $entry->name);
            if (!self::isName($entry->name) || !self::syncable($relative)) {
                continue;
            }
            $seen[$entry->name] = true;
            if (!$this->queue->touches($relative)) {
                $entry->isDir ? $this->landDirectory($relative) : $this->landFile($relative, $entry->stamp);
            }
        }
        foreach (self::names($local) as $name) {
            $relative = self::join($dir, $name);
            $gone = !\array_key_exists($name, $seen) && self::syncable($relative);
            if ($gone && !$this->queue->touches($relative) && null !== $this->index->get($relative)) {
                $this->fs->remove($local.'/'.$name);
                $this->index->forget($relative);
            }
        }
    }

    private function landDirectory(string $relative): void
    {
        $path = $this->absolute($relative);
        if (\is_file($path)) {
            $this->fs->remove($path);
        }
        $this->fs->mkdir($path);
        $this->index->directory($relative);
    }

    private function landFile(string $relative, RemoteStamp $stamp): void
    {
        $path = $this->absolute($relative);
        if (\is_dir($path)) {
            $this->fs->remove($path);
        }
        $known = \is_file($path) ? $this->index->stamp($relative) : null;
        if (!\is_file($path)) {
            $this->fs->dumpFile($path, '');
        }
        $changed = null !== $known && !$stamp->equals($known);
        // An empty file is all there is to fetch of one.
        $this->index->file($relative, $stamp, null !== $known && !$changed || 0 === $stamp->size && 0 === \filesize($path));
        if ($changed) {
            $this->fetch($relative, static function (): void {});
        }
    }

    /** @param \Closure(): void $then */
    private function fetch(string $relative, \Closure $then): void
    {
        $transport = $this->transport();
        $alreadyFetching = \array_key_exists($relative, $this->fetching);
        if (null === $transport) {
            $this->complain();

            return;
        }
        $this->fetching[$relative] = $then; // the latest ask is the one answered
        if ($alreadyFetching) {
            return;
        }
        $this->notifier->flash($this->translator->trans('Fetching %file%…', ['%file%' => $relative]), 2.0, self::SLOT);
        $transport->fetch($relative, function (RemoteFile|string $answer) use ($relative): void {
            $then = $this->fetching[$relative];
            unset($this->fetching[$relative]);
            if (\is_string($answer)) {
                $this->cannotRead($relative, $answer);

                return;
            }
            if ($this->queue->touches($relative)) {
                return; // a change of ours is on its way: the server's copy is not the latest
            }
            $this->fs->dumpFile($this->absolute($relative), $answer->bytes);
            $this->index->file($relative, $answer->stamp, fetched: true);
            $then();
        });
    }

    /**
     * Send $bytes as $relative. While an upload of it is queued and not
     * started, a later save only replaces what that upload will send.
     */
    private function upload(string $relative, string $bytes): void
    {
        $queued = $this->uploading[$relative] ?? null;
        if (null !== $queued) {
            $this->bytes[$queued] = $bytes;

            return;
        }
        $id = ++$this->uploads;
        $this->uploading[$relative] = $id;
        $this->bytes[$id] = $bytes;
        $this->change($relative, function (RemoteTransport $transport, \Closure $next) use ($relative, $id): void {
            if (($this->uploading[$relative] ?? null) === $id) {
                unset($this->uploading[$relative]);
            }
            $bytes = $this->bytes[$id];
            unset($this->bytes[$id]);
            $this->sending[$relative] = $id;
            $expected = \array_key_exists($relative, $this->force) || \array_key_exists($id, $this->sealed) ? null : $this->index->stamp($relative);
            $transport->put($relative, $bytes, $expected, function (RemoteStamp|string|null $answer) use ($relative, $id, $next): void {
                if (($this->sending[$relative] ?? null) === $id) {
                    unset($this->sending[$relative]);
                }
                // A path that moved since is the index's under its new name,
                // which the move already recorded: this stamp is the old one's.
                $sealed = \array_key_exists($id, $this->sealed);
                unset($this->sealed[$id]);
                if (!$sealed) {
                    $this->uploaded($relative, $answer);
                }
                // Sent without a stamp to check, so never refused as a
                // conflict: a failure is all there is left to say.
                if ($sealed && \is_string($answer)) {
                    $this->cannotChange($relative, $answer);
                }
                $next();
            });
        });
    }

    /**
     * $relative moved or went: an upload of it, or of anything under it, that
     * is queued keeps what it will send — it is still the server's next step
     * on the way to this — but a later save there is a new file's.
     */
    private function seal(?string $relative): void
    {
        if (null === $relative) {
            return;
        }
        foreach ([$this->uploading, $this->sending] as $uploads) {
            foreach ($uploads as $path => $id) {
                if (null === RemoteIndex::moved($path, $relative, $relative)) {
                    continue;
                }
                $this->sealed[$id] = true;
                unset($this->uploading[$path]);
            }
        }
    }

    /**
     * What the server answered an upload of $relative: its new stamp, a
     * refusal because its copy changed, or why it failed.
     */
    private function uploaded(string $relative, RemoteStamp|string|null $answer): void
    {
        if ($answer instanceof RemoteStamp) {
            unset($this->force[$relative]);
            $this->index->file($relative, $answer, fetched: true);

            return;
        }
        if (\is_string($answer)) {
            $this->cannotChange($relative, $answer);

            return;
        }
        $this->force[$relative] = true;
        $this->notifier->warn($this->translator->trans('%file% changed on the server — save again to overwrite', ['%file%' => $relative]), slot: self::SLOT);
    }

    private function mkdirs(string $relative): void
    {
        $this->index->directory($relative);
        $this->listed[$relative] = true; // a directory we made holds what we put in it
        $this->change($relative, fn (RemoteTransport $transport, \Closure $next) => $transport->mkdir($relative, $this->reporting($relative, $next)));
    }

    private function removeRemote(string $relative, bool $isDir): void
    {
        $this->index->forget($relative);
        $this->change($relative, fn (RemoteTransport $transport, \Closure $next) => $transport->remove($relative, $isDir, $this->reporting($relative, $next)));
    }

    /**
     * Send what now sits at $target, a file or a directory with all it holds.
     * $source is where it was copied from: a placeholder there has no content
     * here to send, so the copy's content comes from the server's original.
     */
    private function uploadTree(string $target, ?string $source): void
    {
        $path = $this->absolute($target);
        if (\is_dir($path)) {
            $this->mkdirs($target);
            foreach (self::names($path) as $name) {
                $this->uploadTree(self::join($target, $name), null === $source ? null : self::join($source, $name));
            }

            return;
        }
        null !== $source && $this->index->isPlaceholder($source) ? $this->copyRemote($source, $target) : $this->upload($target, (string) \file_get_contents($path));
    }

    /** A copy of a file never fetched: read the server's original, and write it at $target there and here. */
    private function copyRemote(string $source, string $target): void
    {
        $this->change($target, function (RemoteTransport $transport, \Closure $next) use ($source, $target): void {
            $transport->fetch($source, function (RemoteFile|string $answer) use ($transport, $target, $next): void {
                if (\is_string($answer)) {
                    $this->cannotRead($target, $answer);
                    $next();

                    return;
                }
                $this->fs->dumpFile($this->absolute($target), $answer->bytes);
                $transport->put($target, $answer->bytes, null, function (RemoteStamp|string|null $stamp) use ($target, $next): void {
                    $this->uploaded($target, $stamp);
                    $next();
                });
            });
        });
    }

    /**
     * Queue a change for the server. Without a transport there is no server
     * to tell, and the queue moves straight on.
     *
     * @param \Closure(RemoteTransport, \Closure(): void): void $change
     */
    private function change(string $relative, \Closure $change): void
    {
        $this->queue->add($relative, function (\Closure $next) use ($change): void {
            $transport = $this->transport();
            null === $transport ? $next() : $change($transport, $next);
        });
    }

    /**
     * @param \Closure(): void $next
     *
     * @return \Closure(?string): void
     */
    private function reporting(string $relative, \Closure $next): \Closure
    {
        return function (?string $error) use ($relative, $next): void {
            if (null !== $error) {
                $this->cannotChange($relative, $error);
            }
            $next();
        };
    }

    private function cannotRead(string $relative, string $error): void
    {
        $this->notifier->error($this->translator->trans('Cannot read %file% from the server: %error%', self::about($relative, $error)), slot: self::SLOT);
    }

    private function cannotChange(string $relative, string $error): void
    {
        $this->notifier->error($this->translator->trans('Cannot change %file% on the server: %error%', self::about($relative, $error)), slot: self::SLOT);
    }

    /** @return array{'%file%': string, '%error%': string} */
    private static function about(string $relative, string $error): array
    {
        return ['%file%' => '' === $relative ? '/' : $relative, '%error%' => $error];
    }

    /**
     * The transport for this project, asked of the plugins until one answers:
     * none may have activated yet, and one may be enabled later.
     */
    private function transport(): ?RemoteTransport
    {
        $spec = $this->location->remote;
        if (null === $this->transport && null !== $spec) {
            $this->transport = $this->transports->remoteTransport($spec);
        }

        return $this->transport;
    }

    /**
     * Something needed the server and no enabled plugin serves its scheme:
     * the cache is all there is. Said once — the rest is the same news.
     */
    private function complain(): void
    {
        if ($this->complained) {
            return;
        }
        $this->complained = true;
        $this->notifier->error($this->translator->trans('No enabled plugin opens %scheme%:// projects', ['%scheme%' => $this->location->remote->scheme ?? '']));
    }

    /**
     * $path relative to the root, or null when it is not the server's to know
     * of: the project is not remote, or the path is outside it, or in what
     * stays on this machine.
     */
    private function relative(string $path): ?string
    {
        if (!$this->location->isRemote()) {
            return null;
        }
        foreach ([$this->realRoot, $this->rootDir] as $root) {
            if ($path === $root) {
                return '';
            }
            $relative = \str_starts_with($path, $root.'/') ? \substr($path, \strlen($root) + 1) : null;
            if (null !== $relative) {
                return self::syncable($relative) ? $relative : null;
            }
        }

        return null;
    }

    private function absolute(string $relative): string
    {
        return '' === $relative ? $this->realRoot : $this->realRoot.'/'.$relative;
    }

    /** Whether $relative is the server's: not Porta's own `.porta/` or `.porta.json`, and well-formed. */
    private static function syncable(string $relative): bool
    {
        $segments = \explode('/', $relative);

        return '.porta' !== $segments[0] && '.porta.json' !== $relative && \array_all($segments, self::isName(...));
    }

    /** Whether $name is one entry's name — never `.`, `..`, or anything with a separator or NUL in it. */
    private static function isName(string $name): bool
    {
        return !\in_array($name, ['', '.', '..'], strict: true) && 1 !== \preg_match('#[/\x00]#', $name);
    }

    /** @return list<string> the entries of $dir, none when it is not one */
    private static function names(string $dir): array
    {
        $names = \is_dir($dir) ? \scandir($dir) : false;

        return \array_values(\array_filter(false === $names ? [] : $names, self::isName(...)));
    }

    private static function join(string $dir, string $name): string
    {
        return '' === $dir ? $name : $dir.'/'.$name;
    }

    private static function parent(string $relative): string
    {
        $slash = \strrpos($relative, needle: '/');

        return false === $slash ? '' : \substr($relative, offset: 0, length: $slash);
    }

    /**
     * @param array<string, true> $keys
     *
     * @return array<string, true>
     */
    private static function movedKeys(array $keys, string $from, string $to): array
    {
        $moved = [];
        foreach (\array_keys($keys) as $key) {
            $moved[RemoteIndex::moved($key, $from, $to) ?? $key] = true;
        }

        return $moved;
    }
}
