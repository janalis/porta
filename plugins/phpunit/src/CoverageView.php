<?php

declare(strict_types=1);

namespace Porta\PhpUnit;

use Porta\Plugin\Api\PluginApi;

/**
 * The last coverage run, on the files it measured: core shows a file's
 * coverage only while it is open, so it is published to each open file now
 * and to each one as it opens.
 *
 * A file loses its coverage when it is saved: the run measured the code that
 * was there, and the bars would otherwise go on claiming lines ran that the
 * run never saw. Until then, edits move the bars with their lines.
 */
final class CoverageView
{
    /** @var array<string, array<int, bool>> real path => 0-based line => whether it ran */
    private array $files = [];

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
        foreach ($files as $file => $lines) {
            $this->files[self::real($file)] = $lines;
        }
        foreach ($this->api->openBuffers()->files() as ['path' => $path]) {
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
        $lines = $this->files[self::real($path)] ?? null;
        if (null !== $lines) {
            $this->api->setCoverage($path, $lines);
        }
    }

    public function saved(string $path): void
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
