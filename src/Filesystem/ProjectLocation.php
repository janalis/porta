<?php

declare(strict_types=1);

namespace Porta\Filesystem;

use Porta\Support\JsonFile;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Whether the open project is this machine's or a server's.
 *
 * A remote project is opened like any other — Porta is pointed at a local
 * directory — and that directory is its cache under
 * `~/.porta/remote/<id>/<name>`. What makes it remote is the marker in its
 * `.porta/` folder, which names the server it mirrors. Nothing else in the
 * IDE needs to know where the root came from: it stays a local path for the
 * stores, the buffers and the tree.
 */
#[Autoconfigure(constructor: 'forRoot')]
final readonly class ProjectLocation
{
    /** The marker, relative to the root; private to this machine like the rest of `.porta/`. */
    public const string MARKER = '.porta/remote.json';

    public function __construct(
        public ?RemoteSpec $remote = null,
    ) {}

    /** The location of the project at $rootDir: remote when its marker names a server. */
    public static function forRoot(string $rootDir): self
    {
        // @mago-expect analysis:mixed-assignment -- a decoded file: narrowed below
        $url = JsonFile::read($rootDir.'/'.self::MARKER)['url'] ?? null;

        return new self(\is_string($url) ? RemoteSpec::parse($url) : null);
    }

    /**
     * The local directory $spec's project is opened at, under $home — made,
     * and marked, when it is not there yet. One per server project, kept
     * between sessions: what was fetched once is not fetched again.
     */
    public static function prepare(RemoteSpec $spec, string $home, Filesystem $fs = new Filesystem()): string
    {
        $root = $home.'/.porta/remote/'.$spec->id().'/'.$spec->name();
        $fs->mkdir($root.'/.porta', 0o700);
        JsonFile::write($fs, $root.'/'.self::MARKER, ['url' => $spec->url()], trailingNewline: true);

        return (string) \realpath($root);
    }

    public function isRemote(): bool
    {
        return null !== $this->remote;
    }
}
