<?php

declare(strict_types=1);

namespace Porta\PhpUnit;

use Porta\Plugin\Api\PluginApi;

/**
 * The last coverage run, on the files it measured: core shows a file's
 * coverage only while it is open, so it is published to each open file now
 * and to each one as it opens.
 *
 * A file loses its coverage once its text is no longer what the run measured:
 * saved, reloaded or rewritten from outside, or changed on disk before it is
 * opened. A file open with unsaved edits gets none at all — the run measured
 * the disk, whose lines are not the buffer's. Until then, edits move the bars
 * with their lines.
 */
final class CoverageView
{
    /** @var array<string, array<int, bool>> real path => 0-based line => whether it ran */
    private array $files = [];

    /** When the shown run's report arrived: a file changed on disk after it is not what it measured. */
    private int $measuredAt = 0;

    public function __construct(
        private readonly PluginApi $api,
    ) {}

    /**
     * Show a run's coverage in place of the last one's.
     *
     * @param array<string, array<int, bool>> $files as {@see CloverReport::parse()} gives it
     */
    public function show(array $files): void
    {
        $this->hide();
        $this->files = $files; // PHPUnit names files by their real path already
        $this->measuredAt = \time();
        foreach ($this->api->openBuffers()->files() as ['path' => $path, 'dirty' => $dirty]) {
            if ($dirty) {
                $this->forget($path);

                continue;
            }
            $this->opened($path);
        }
    }

    /** Give the change strip back to the change marks, in every file. */
    public function hide(): void
    {
        foreach ($this->api->openBuffers()->files() as ['path' => $path]) {
            if (!\array_key_exists(self::real($path), $this->files)) {
                continue;
            }
            $this->api->setCoverage($path, []);
        }
        $this->files = [];
    }

    /** Whether any coverage is on show, for the Hide command's menu entry. */
    public function shown(): bool
    {
        return [] !== $this->files;
    }

    public function opened(string $path): void
    {
        \clearstatcache(true, $path);
        $lines = $this->files[self::real($path)] ?? null;
        match (true) {
            null === $lines => null,
            (int) \filemtime($path) > $this->measuredAt => $this->forget($path),
            default => $this->api->setCoverage($path, $lines),
        };
    }

    /** Saved, reloaded or rewritten: the file's text is not what the run measured any more. */
    public function forget(string $path): void
    {
        $real = self::real($path);
        if (\array_key_exists($real, $this->files)) {
            unset($this->files[$real]);
            $this->api->setCoverage($path, []);
        }
    }

    private static function real(string $path): string
    {
        $real = \realpath($path);

        return false === $real ? $path : $real;
    }
}
