<?php

declare(strict_types=1);

namespace Porta\Filesystem;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Event\StatusCellsChanged;
use Porta\I18n\Translator;
use Porta\Search\FileIndex;
use Porta\Support\Files;
use Porta\Ui\Glyphs;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\Notifier;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusSegment;
use Porta\Workbench\StatusTone;
use Psr\EventDispatcher\EventDispatcherInterface;
use Revolt\EventLoop;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
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
 *
 * A failed call is first asked of the {@see RemoteLink}: while the link is
 * down, the change that failed waits at the head of the queue, what was being
 * read waits with it, and the poll stops asking. A change the server REFUSED
 * stays here, unsent: no listing or fetch overwrites it, and the next save of
 * it tries again.
 */
#[AsIdeService]
#[AsTaggedItem(priority: 240)]
final class RemoteFilesystem implements ProjectFilesystem, StatusSegment
{
    private const string INDEX = '.porta/remote-index.json';
    public const float POLL_SECONDS = 3.0;
    /** How many directories one poll re-lists. */
    private const int POLL_BATCH = 2;
    private const string SLOT = 'remote';
    /**
     * What a walk of the whole tree does not descend into: what go-to-file
     * leaves out by default ({@see FileIndex}), and Porta's own state.
     */
    private const array WALK_SKIP = ['.git', 'node_modules', 'vendor', '.idea', '.vscode', '.porta'];
    /** How many polls — half a minute — before a directory the server refused is asked again. */
    private const int RETRY_POLLS = 10;
    /** How many walked entries land per loop turn: the loop renders between them. */
    private const int WALK_CHUNK = 500;

    private readonly RemoteIndex $index;
    private readonly ChangeQueue $queue;
    /** @var array<array-key, true> directories listed (or being listed) this session */
    private array $listed = [];
    /**
     * Each change of ours numbered as the server answers it, and the folder
     * it touched marked with that number. A listing carries the number it was
     * asked at: one that started before a change of ours in its folder and
     * lands after describes a server that is gone — it put a renamed file
     * back as an empty placeholder and dropped its new name, blanking the
     * open buffer, whose next save then sent the blank over the real file.
     * Such a listing is dropped, and the folder listed again.
     */
    private int $changes = 0;

    /** @var array<array-key, int> folder => the number of the last change of ours there */
    private array $changedAt = [];

    /** @var array<array-key, true> listings in flight */
    private array $listing = [];
    /** @var array<array-key, array{then: \Closure(): void, failed: (\Closure(): void)|null}> fetches in flight: what runs when each lands, or will not */
    private array $fetching = [];
    /** @var array<array-key, int> uploads queued and not started, by path — a later save rides the same one */
    private array $uploading = [];
    /** @var array<int, string> what each queued upload will send */
    private array $bytes = [];
    /** @var array<array-key, int> the upload on its way to the server, by path */
    private array $sending = [];
    /** @var array<int, true> uploads whose path moved or went before they were answered */
    private array $sealed = [];
    private int $uploads = 0;
    /** @var array<array-key, true> paths whose next upload overwrites what the server has */
    private array $force = [];
    private ?RemoteTransport $transport = null;
    /** The last thing the server refused, until it next answers yes. */
    private ?string $trouble = null;
    /** Whether "no plugin serves this" was said: once is enough. */
    private bool $complained = false;
    /** @var array<array-key, true> directories asked for before a transport was there: the first poll lists them */
    private array $wanted = [];
    private int $pollCursor = 0;
    /** Whether the whole tree was asked for this session. */
    private bool $walked = false;
    private int $polls = 0;
    /** @var array<array-key, int> directories the server refused to list, and the poll that asks again */
    private array $retry = [];
    private readonly string $realRoot;
    private readonly RemoteLink $link;
    /** @var array<array-key, string> changes the server refused, and why: here, not there, until saved again */
    private array $unsent = [];

    public function __construct(
        private readonly ProjectLocation $location,
        private readonly string $rootDir,
        private readonly RemoteTransportSource $transports,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly BufferManager $buffers,
        private readonly FileIndex $files,
        private readonly EventDispatcherInterface $events,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
        $this->realRoot = Files::canonical($rootDir);
        $this->index = new RemoteIndex($this->realRoot.'/'.self::INDEX, $fs);
        $this->queue = new ChangeQueue();
        $host = $location->remote->host ?? '';
        $this->link = new RemoteLink(
            function (string $why) use ($host): void {
                $this->notifier->warn($this->translator->trans('Lost %host% (%why%) — trying again', ['%host%' => $host, '%why%' => $why]), slot: self::SLOT);
            },
            function (array $root) use ($host): void {
                $this->troubled(null);
                $this->notifier->flash($this->translator->trans('Back in touch with %host%', ['%host%' => $host]), 2.5, self::SLOT);
                // Applied as it is: whatever was changed while the link was
                // down is still queued, which ours() leaves alone.
                $this->reconcile('', $root);
            },
            $this->cellChanged(...),
        );
    }

    /** The project is closing: whatever the transport has in flight is not waited for. */
    public function close(): void
    {
        $this->link->close();
        $this->transport?->close();
    }

    #[\Override]
    public function available(string $path, \Closure $then, ?\Closure $failed = null): bool
    {
        $relative = $this->relative($path);
        if (null === $relative || !$this->index->isPlaceholder($relative) || !\is_file($path)) {
            return true;
        }
        $this->fetch($relative, $then, $failed);

        return false;
    }

    #[\Override]
    public function listed(string $dir): bool
    {
        $relative = $this->relative($dir);
        if (null !== $relative && !\array_key_exists($relative, $this->listed)) {
            $this->requestListing($relative);

            return false;
        }

        return true;
    }

    /** Ask the server for $relative's entries, or for later when there is no transport yet. */
    private function requestListing(string $relative): void
    {
        $transport = $this->transport();
        if (null === $transport) {
            // Plugins activate after the tree is first built: asked again by
            // the first poll, once there may be one.
            $this->wanted[$relative] = true;

            return;
        }
        $this->listed[$relative] = true;
        $this->list($transport, $relative);
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
            $this->index->save();
            $this->listed = self::movedKeys($this->listed, $source, $target);
            $this->unsent = self::movedKeys($this->unsent, $source, $target);
            $this->change(
                $target,
                // Not settled() — a rename moves the server's file, it does not
                // send what the server refused of it: that stays unsent.
                static fn (RemoteTransport $transport, \Closure $answered) => $transport->rename($source, $target, $answered),
                fn (string $why) => $this->refused($target, $why),
                $source,
            );

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

    /**
     * Where the project is, in the status bar — and what a save there does
     * not show by itself: that it has not reached the server yet. A saved
     * buffer looks saved; this is the only place an upload still on its way
     * is visible. The last refusal stays until the server next answers yes.
     */
    #[\Override]
    public function statusCells(): array
    {
        $spec = $this->location->remote;
        if (null === $spec) {
            return [];
        }
        $pending = $this->queue->pending();
        $count = $pending + \count($this->unsent);
        $down = $this->link->down();
        $text = Glyphs::REMOTE.' '.$spec->host.(null === $down ? '' : ' '.Glyphs::OUT_OF_TOUCH).(0 === $count ? '' : ' '.Glyphs::UPLOADING.$count);
        $lines = [
            0 === $pending
                ? $this->translator->trans('This project is on %host%: a file is fetched when you open it, and sent back when you save it', ['%host%' => $spec->host])
                : $this->translator->trans('%count% change on its way to %host%|%count% changes on their way to %host%', ['%count%' => $pending, '%host%' => $spec->host]),
        ];
        if (null !== $down) {
            $lines[] = $this->link->loginRefused()
                ? $this->translator->trans('%host% refused the login: %why% — right-click to try again', ['%host%' => $spec->host, '%why%' => $down])
                : $this->translator->trans('Cannot reach %host%: %why% — trying again', ['%host%' => $spec->host, '%why%' => $down]);
        }
        if ([] !== $this->unsent) {
            $lines[] = $this->translator->trans('%count% change the server refused is only here — save it again to retry|%count% changes the server refused are only here — save them again to retry', [
                '%count%' => \count($this->unsent),
            ]);
        }
        if (null !== $this->trouble && null === $down) {
            $lines[] = $this->trouble;
        }

        return [new StatusCell(
            $text,
            match (true) {
                null !== $down, null !== $this->trouble, [] !== $this->unsent => StatusTone::Accent,
                0 !== $pending => StatusTone::Active,
                default => StatusTone::Subtle,
            },
            fn (): array => [new MenuItem($this->translator->trans('Reconnect Now'), '', $this->reconnect(...), isEnabled: fn (): bool => null !== $this->link->down())],
            \implode("\n", $lines),
        )];
    }

    /**
     * List the root now, rather than when the backoff says: the user asked,
     * or the transport says what stopped it changed — a password was given.
     */
    public function reconnect(): void
    {
        $transport = $this->transport();
        if (null !== $transport) {
            $this->link->reconnect($transport);
        }
    }

    /** Whether the project lives on a server — the container builds this for a local project too. */
    public function isRemote(): bool
    {
        return null !== $this->location->remote;
    }

    /**
     * $argv run on the server, in the project's directory there — or null
     * when the project is not remote, no plugin serves it yet, or its
     * transport cannot run commands: the caller then runs it here, on the
     * cache.
     *
     * @param list<string> $argv
     *
     * @return list<string>|null
     */
    public function remoteCommand(array $argv): ?array
    {
        return $this->transport()?->remoteCommand($argv);
    }

    /** Changes not on the server: on their way, or refused and kept here. */
    #[\Override]
    public function pending(): int
    {
        return $this->queue->pending() + \count($this->unsent);
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
        // While the link is down only its probe asks: a poll would be one
        // more failure — one more login, to a server that may count them.
        if ([] !== $this->listing || null === $transport || null !== $this->link->down()) {
            return;
        }
        ++$this->polls;
        foreach ($this->retry as $dir => $due) {
            if ($due > $this->polls) {
                continue;
            }
            unset($this->retry[$dir]);
            $this->wanted[$dir] = true;
        }
        $wanted = self::paths($this->wanted);
        $this->wanted = [];
        foreach ($wanted as $dir) {
            $this->listed[$dir] = true;
            $this->list($transport, $dir);
        }
        if ([] !== $wanted || $this->walk($transport)) {
            return;
        }
        $dirs = [];
        foreach ($this->buffers->all() as $buffer) {
            $relative = $this->relative($buffer->path);
            if (null !== $relative) {
                $dirs[] = self::parent($relative);
            }
        }
        $others = self::paths($this->listed);
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
     * Ask, once a session, for the whole tree — the poll's first round after
     * what the tree was waiting on. Whether it was asked: a transport that
     * cannot walk leaves the poll to its listings.
     */
    private function walk(RemoteTransport $transport): bool
    {
        if ($this->walked) {
            return false;
        }
        $this->walked = true;
        $asked = $this->changes;

        return $transport->walk('', self::WALK_SKIP, function (array|string $answer) use ($transport, $asked): void {
            if (\is_string($answer)) {
                // Asked again: by the next poll once the link is back.
                $this->link->failed(
                    $transport,
                    $answer,
                    function (): void {
                        $this->walked = false;
                    },
                    fn () => $this->cannotRead('', $answer),
                );

                return;
            }
            /** @var list<RemoteEntry> $answer */
            $this->landWalked($answer, $asked);
        });
    }

    /**
     * Land a walk's entries a chunk per loop turn — a project's tree can be
     * tens of thousands of placeholders — then let go-to-file see them. Only
     * adds: what went is a listing's to remove, and a path with a change of
     * ours on its way is left alone.
     *
     * @param list<RemoteEntry> $entries
     */
    private function landWalked(array $entries, int $asked): void
    {
        foreach (\array_splice($entries, offset: 0, length: self::WALK_CHUNK) as $entry) {
            if (!self::syncable($entry->name) || $this->ours($entry->name) || $this->changedSince($entry->name, $asked)) {
                continue;
            }
            $entry->isDir ? $this->landDirectory($entry->name) : $this->landFile($entry->name, $entry->stamp);
        }
        $this->index->save();
        if ([] === $entries) {
            $this->files->refresh();

            return;
        }
        EventLoop::defer(fn () => $this->landWalked($entries, $asked));
    }

    /**
     * List $relative on the server. Never twice at once: listed() marks a
     * directory before it asks, and a poll waits for its last round.
     */
    private function list(RemoteTransport $transport, string $relative): void
    {
        $this->listing[$relative] = true;
        $asked = $this->changes;
        $transport->list($relative, function (array|string $answer) use ($relative, $transport, $asked): void {
            unset($this->listing[$relative]);
            if ($this->changedSince($relative, $asked)) {
                $this->wanted[$relative] = true; // out of date before it landed: the next poll asks again

                return;
            }
            if (\is_string($answer)) {
                unset($this->listed[$relative]);
                $this->link->failed(
                    $transport,
                    $answer,
                    function () use ($relative): void {
                        $this->wanted[$relative] = true; // the first poll once the link is back
                    },
                    function () use ($relative, $answer): void {
                        // Refused: asked again the next time the tree shows it,
                        // or half a minute from now.
                        $this->retry[$relative] = $this->polls + self::RETRY_POLLS;
                        $this->cannotRead($relative, $answer);
                    },
                );

                return;
            }
            /** @var list<RemoteEntry> $answer */
            $this->troubled(null);
            unset($this->retry[$relative]);
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
            if (!$this->ours($relative)) {
                $entry->isDir ? $this->landDirectory($relative) : $this->landFile($relative, $entry->stamp);
            }
        }
        foreach (self::names($local) as $name) {
            $relative = self::join($dir, $name);
            $gone = !\array_key_exists($name, $seen) && self::syncable($relative);
            if ($gone && !$this->ours($relative) && !$this->holdsUnsent($relative) && null !== $this->index->get($relative)) {
                $this->fs->remove($local.'/'.$name);
                $this->index->forget($relative);
            }
        }
        $this->index->save();
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
            // touch(), not dumpFile(): a walk lands thousands of these, and
            // an empty file needs no write-then-rename to be whole. Its
            // directory is here: a listing is of one that is, and a walk
            // names a directory before what it holds.
            $this->fs->touch($path);
        }
        $changed = null !== $known && !$stamp->equals($known);
        // An empty file is all there is to fetch of one.
        $this->index->file($relative, $stamp, (null !== $known && !$changed) || (0 === $stamp->size && 0 === \filesize($path)));
        if ($changed) {
            $this->fetch($relative, static function (): void {});
        }
    }

    /** @param \Closure(): void $then */
    /**
     * @param \Closure(): void       $then
     * @param (\Closure(): void)|null $failed
     */
    private function fetch(string $relative, \Closure $then, ?\Closure $failed = null): void
    {
        $transport = $this->transport();
        $alreadyFetching = \array_key_exists($relative, $this->fetching);
        if (null === $transport) {
            $this->complain();
            $failed?->__invoke();

            return;
        }
        $this->fetching[$relative] = ['then' => $then, 'failed' => $failed]; // the latest ask is the one answered
        if ($alreadyFetching) {
            return;
        }
        $this->notifier->flash($this->translator->trans('Fetching %file%…', ['%file%' => $relative]), 2.0, self::SLOT);
        $transport->fetch($relative, function (RemoteFile|string $answer) use ($relative, $transport): void {
            ['then' => $then, 'failed' => $failed] = $this->fetching[$relative];
            unset($this->fetching[$relative]);
            if (\is_string($answer)) {
                // Once the link is back the file is asked for again, and
                // whoever was waiting for it — a tab, a stand-in — still is.
                // Refused, it never comes: whoever waited is told.
                $this->link->failed($transport, $answer, fn () => $this->fetch($relative, $then, $failed), function () use ($relative, $answer, $failed): void {
                    $this->cannotRead($relative, $answer);
                    $failed?->__invoke();
                });

                return;
            }
            if ($this->ours($relative)) {
                return; // a change of ours is on its way: the server's copy is not the latest
            }
            $this->troubled(null);
            $this->fs->dumpFile($this->absolute($relative), $answer->bytes);
            $this->index->file($relative, $answer->stamp, fetched: true);
            $this->index->save();
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
        $this->change(
            $relative,
            function (RemoteTransport $transport, \Closure $answered) use ($relative, $id): void {
                // Taken on the first try: a save from now on is a new upload,
                // and a try again after the link was lost sends the same bytes.
                if (($this->uploading[$relative] ?? null) === $id) {
                    unset($this->uploading[$relative]);
                }
                $bytes = $this->bytes[$id] ?? '';
                $this->sending[$relative] = $id;
                $expected = \array_key_exists($relative, $this->force) || \array_key_exists($id, $this->sealed) ? null : $this->index->stamp($relative);
                $transport->put($relative, $bytes, $expected, function (RemoteStamp|string|null $answer) use ($relative, $id, $bytes, $answered, $transport): void {
                    if (\is_string($answer)) {
                        $answered($answer);

                        return;
                    }
                    unset($this->bytes[$id]);
                    if (($this->sending[$relative] ?? null) === $id) {
                        unset($this->sending[$relative]);
                    }
                    // A path that moved since is the index's under its new name,
                    // which the move already recorded: this stamp is the old one's.
                    $sealed = \array_key_exists($id, $this->sealed);
                    unset($this->sealed[$id]);
                    if ($sealed) {
                        $answered(null);

                        return;
                    }
                    unset($this->unsent[$relative]);
                    $this->uploaded($transport, $relative, $bytes, $answer, $answered);
                });
            },
            function (string $why) use ($relative, $id): void {
                unset($this->bytes[$id]);
                if (($this->sending[$relative] ?? null) === $id) {
                    unset($this->sending[$relative]);
                }
                $sealed = \array_key_exists($id, $this->sealed);
                unset($this->sealed[$id]);
                $sealed ? $this->cannotChange($relative, $why) : $this->refused($relative, $why);
            },
        );
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
                if (null === RemoteIndex::moved((string) $path, $relative, $relative)) {
                    continue;
                }
                $this->sealed[$id] = true;
                unset($this->uploading[$path]);
            }
        }
    }

    /**
     * What the server answered an upload of $relative: its new stamp, or a
     * refusal because its copy changed — which it may not have: an upload
     * that landed and lost its answer with the link is tried again, and
     * finds its own bytes there. Those are adopted, not called a conflict.
     *
     * @param \Closure(?string): void $answered
     */
    private function uploaded(RemoteTransport $transport, string $relative, string $bytes, ?RemoteStamp $answer, \Closure $answered): void
    {
        if (null !== $answer) {
            $this->landed($relative, $answer);
            $answered(null);

            return;
        }
        $transport->fetch($relative, function (RemoteFile|string $there) use ($relative, $bytes, $answered): void {
            $answered(null);
            if ($there instanceof RemoteFile && $there->bytes === $bytes) {
                $this->landed($relative, $there->stamp);

                return;
            }
            $this->force[$relative] = true;
            // Refused, so kept as any refusal is: the next listing would
            // fetch theirs over the cache, the reconciler would reload the
            // buffer — saved, so clean — and the edit would be gone.
            $warning = $this->translator->trans('%file% changed on the server — save again to overwrite', ['%file%' => $relative]);
            $this->unsent[$relative] = $warning;
            $this->notifier->warn($warning, slot: self::SLOT);
        });
    }

    private function landed(string $relative, RemoteStamp $stamp): void
    {
        $this->troubled(null);
        unset($this->force[$relative]);
        $this->index->file($relative, $stamp, fetched: true);
        $this->index->save();
    }

    private function mkdirs(string $relative): void
    {
        $this->index->directory($relative);
        $this->index->save();
        $this->listed[$relative] = true; // a directory we made holds what we put in it
        $this->change(
            $relative,
            fn (RemoteTransport $transport, \Closure $answered) => $transport->mkdir($relative, $this->settled($relative, $answered)),
            fn (string $why) => $this->refused($relative, $why),
        );
    }

    private function removeRemote(string $relative, bool $isDir): void
    {
        $this->index->forget($relative);
        $this->index->save();
        $this->change(
            $relative,
            static fn (RemoteTransport $transport, \Closure $answered) => $transport->remove($relative, $isDir, $answered),
            fn (string $why) => $this->cannotChange($relative, $why),
        );
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

    /**
     * A copy of a file never fetched: read the server's original, and write
     * it at $target there and here. A refusal leaves nothing unsent: there is
     * no content here that the server lacks, only a copy it would not make.
     */
    private function copyRemote(string $source, string $target): void
    {
        $read = false;
        $this->change(
            $target,
            function (RemoteTransport $transport, \Closure $answered) use ($source, $target, &$read): void {
                $transport->fetch($source, function (RemoteFile|string $answer) use ($transport, $target, $answered, &$read): void {
                    $read = !\is_string($answer);
                    if (\is_string($answer)) {
                        $answered($answer);

                        return;
                    }
                    $this->fs->dumpFile($this->absolute($target), $answer->bytes);
                    // Sent with no stamp to check, so never refused as a conflict.
                    $transport->put($target, $answer->bytes, null, function (RemoteStamp|string|null $stamp) use ($target, $answered): void {
                        if (\is_string($stamp)) {
                            $answered($stamp);

                            return;
                        }
                        if (null !== $stamp) {
                            $this->landed($target, $stamp);
                        }
                        $answered(null);
                    });
                });
            },
            function (string $why) use ($target, &$read): void {
                $read ? $this->cannotChange($target, $why) : $this->cannotRead($target, $why);
            },
        );
    }

    /**
     * Queue a change for the server. Without a transport there is no server
     * to tell, and the queue moves straight on.
     *
     * $attempt answers null once the server has it — or has answered for
     * good — and a reason when the call failed. Then the queue holds: a link
     * that is down waits at its head and tries $attempt again once it is
     * back; a server that refused it has $refused told why, and the queue
     * moves on. The queue moves on, too, when what ran threw: a stalled
     * queue would keep every later change, and the quit, waiting forever.
     *
     * @param \Closure(RemoteTransport, \Closure(?string): void): void $attempt
     * @param \Closure(string): void                                   $refused
     */
    /**
     * Number a change of ours, and mark each path it touches and the folder
     * holding it with that number.
     *
     * @param list<string> $relatives
     */
    private function mark(array $relatives): void
    {
        ++$this->changes;
        foreach ($relatives as $relative) {
            $this->changedAt[$relative] = $this->changes;
            $this->changedAt[self::parent($relative)] = $this->changes;
        }
    }

    /** Whether $relative, or the folder holding it, saw a change of ours after answer number $asked was asked. */
    private function changedSince(string $relative, int $asked): bool
    {
        return ($this->changedAt[$relative] ?? 0) > $asked || ($this->changedAt[self::parent($relative)] ?? 0) > $asked;
    }

    /** @param string|null $from a rename's other side, whose folder it changes too */
    private function change(string $relative, \Closure $attempt, \Closure $refused, ?string $from = null): void
    {
        // Marked as it is queued, and again as it is answered: a listing asked
        // before either describes a server this change is about to make, or
        // has made, out of date.
        $touched = null === $from ? [$relative] : [$relative, $from];
        $this->mark($touched);
        $this->queue->add($relative, function (\Closure $next) use ($attempt, $refused, $touched): void {
            // The cell counts what is on its way: one fewer once answered.
            $done = function () use ($next, $touched): void {
                $this->mark($touched);
                $next();
                $this->cellChanged();
            };
            $transport = $this->transport();
            if (null === $transport) {
                $done();

                return;
            }
            $try = null;
            $try = function () use ($transport, $attempt, $refused, $done, &$try): void {
                $attempt($transport, function (?string $failure) use ($transport, $refused, $done, &$try): void {
                    if (null === $failure) {
                        $done();

                        return;
                    }
                    $this->link->failed($transport, $failure, $try, static function () use ($refused, $failure, $done): void {
                        try {
                            $refused($failure);
                        } finally {
                            $done();
                        }
                    });
                });
            };
            try {
                $try();
            } catch (\Throwable $error) {
                $done();

                throw $error;
            }
        });
        $this->cellChanged();
    }

    /** A change the server refused: it stays here, unsent, and is said. */
    private function refused(string $relative, string $why): void
    {
        $this->unsent[$relative] = $why;
        $this->cannotChange($relative, $why);
    }

    /**
     * Whether a change of ours to $relative is on its way or was refused —
     * either way what the server says of it is not to be taken over it.
     */
    private function ours(string $relative): bool
    {
        return $this->queue->touches($relative) || \array_key_exists($relative, $this->unsent);
    }

    /** Whether anything unsent is at or under $relative: removing it would lose it. */
    private function holdsUnsent(string $relative): bool
    {
        return \array_any(self::paths($this->unsent), static fn (string $path): bool => null !== RemoteIndex::moved($path, $relative, $relative));
    }

    /** The last thing the server refused, or null once it answered yes: the cell says it, so a change is said. */
    private function troubled(?string $trouble): void
    {
        if ($trouble !== $this->trouble) {
            $this->trouble = $trouble;
            $this->cellChanged();
        }
    }

    /** What the status cell shows moved — the count on its way, the last refusal — whatever else did not. */
    private function cellChanged(): void
    {
        $this->events->dispatch(new StatusCellsChanged());
    }

    /**
     * What a change's answer means for $relative before the queue hears it:
     * landed, and anything refused of it before is sent now.
     *
     * @param \Closure(?string): void $answered
     *
     * @return \Closure(?string): void
     */
    private function settled(string $relative, \Closure $answered): \Closure
    {
        return function (?string $error) use ($relative, $answered): void {
            if (null === $error) {
                unset($this->unsent[$relative]);
            }
            $answered($error);
        };
    }

    private function cannotRead(string $relative, string $error): void
    {
        $this->troubled($error);
        $this->notifier->error($this->translator->trans('Cannot read %file% from the server: %error%', self::about($relative, $error)), slot: self::SLOT);
    }

    private function cannotChange(string $relative, string $error): void
    {
        $this->troubled($error);
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
            $this->transport?->onRetry($this->reconnect(...));
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
        $root = \array_find([$this->realRoot, $this->rootDir], static fn (string $root): bool => $path === $root || \str_starts_with($path, $root.'/'));

        return match (true) {
            null === $root => null,
            $path === $root => '',
            self::syncable($relative = \substr($path, \strlen($root) + 1)) => $relative,
            default => null,
        };
    }

    private function absolute(string $relative): string
    {
        return '' === $relative ? $this->realRoot : $this->realRoot.'/'.$relative;
    }

    /**
     * Whether $relative is the server's: not Porta's own `.porta/` or
     * `.porta.json`, however the server cases them ({@see Files::asTheDiskReadsIt()}:
     * its `.PORTA/remote.json` would be this copy's marker), and well-formed.
     */
    private static function syncable(string $relative): bool
    {
        $segments = \explode('/', $relative);
        $first = Files::asTheDiskReadsIt($segments[0]) ?? $segments[0];

        return '.porta' !== $first && '.porta.json' !== $first && \array_all($segments, self::isName(...));
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
     * The relative paths an array is keyed by, as strings: PHP turns a key of
     * digits — a top-level `2024/` — into an int, which strict types then
     * refused where a path was asked for, throwing in the middle of a rename.
     *
     * @param array<array-key, mixed> $keyed
     *
     * @return list<string>
     */
    private static function paths(array $keyed): array
    {
        return \array_map(static fn (int|string $key): string => (string) $key, \array_keys($keyed));
    }

    /**
     * @template T
     *
     * @param array<array-key, T> $keys keyed by paths, a path of digits an int
     *
     * @return array<array-key, T>
     */
    private static function movedKeys(array $keys, string $from, string $to): array
    {
        $moved = [];
        foreach ($keys as $key => $value) {
            $moved[RemoteIndex::moved((string) $key, $from, $to) ?? $key] = $value;
        }

        return $moved;
    }
}
