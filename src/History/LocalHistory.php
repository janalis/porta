<?php

declare(strict_types=1);

namespace Porta\History;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Timestamped local snapshots of edited files, kept under
 * `<root>/.porta/history/<hash>/<stamp>.snap`. A hard per-file cap prunes
 * the oldest snapshots. Independent of git — this is the editor's own undo
 * safety net across sessions.
 */
final readonly class LocalHistory
{
    private string $baseDir;
    /** @var \Closure(): int monotonic timestamp source (ms), injectable for tests */
    private \Closure $clock;
    private Filesystem $fs;

    /**
     * @param (callable(): int)|null $clock monotonic timestamp source (ms), injectable for tests
     */
    public function __construct(
        string $rootDir,
        ?callable $clock = null,
        private int $maxPerFile = 25,
    ) {
        $this->baseDir = $rootDir.'/.porta/history';
        $this->clock = null !== $clock ? $clock(...) : static fn (): int => (int) (microtime(true) * 1000);
        $this->fs = new Filesystem();
    }

    /**
     * Record a snapshot of $content for $path. Returns the number of pruned
     * old snapshots (>= 0), or -1 when the snapshot could not be written.
     */
    public function snapshot(string $path, string $content): int
    {
        $dir = $this->dirFor($path);
        try {
            $this->fs->mkdir($dir);
        } catch (IOExceptionInterface) {
            return -1;
        }

        // Skip if identical to the newest snapshot (no-op saves are common).
        $existing = $this->list($path);
        if ([] !== $existing && @file_get_contents($existing[0]['file']) === $content) {
            return 0;
        }

        $stamp = ($this->clock)();
        try {
            $this->fs->dumpFile($dir.'/'.$stamp.'.snap', $content);
        } catch (IOExceptionInterface) {
            return -1;
        }

        return $this->prune($path);
    }

    /**
     * Snapshots for $path, newest first.
     *
     * @return list<array{stamp: int, file: string}>
     */
    public function list(string $path): array
    {
        $dir = $this->dirFor($path);
        $entries = [];
        foreach (@scandir($dir) ?: [] as $name) {
            $m = [];
            if (preg_match('/^(\d+)\.snap$/', $name, $m)) {
                $entries[] = ['stamp' => (int) $m[1], 'file' => $dir.'/'.$name];
            }
        }
        usort($entries, static fn (array $a, array $b) => $b['stamp'] <=> $a['stamp']);

        return $entries;
    }

    public function read(string $snapFile): ?string
    {
        $content = @file_get_contents($snapFile);

        return false === $content ? null : $content;
    }

    private function prune(string $path): int
    {
        // The snapshots live in the directory we just wrote to, so removal here
        // is expected to succeed (Filesystem::remove ignores absent paths).
        $stale = \array_slice($this->list($path), $this->maxPerFile);
        $this->fs->remove(array_column($stale, 'file'));

        return \count($stale);
    }

    private function dirFor(string $path): string
    {
        $real = realpath($path) ?: $path;

        return $this->baseDir.'/'.substr(md5($real), 0, 16);
    }
}
