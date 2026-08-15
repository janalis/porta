<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Best-effort append-only diagnostic file with a size cap: the shared sink
 * for evidence that must survive a session (freeze.log, error.log, dap.log).
 * Never throws — a diagnostic channel must not break the app it is
 * diagnosing — and starts the file over once it outgrows the cap, so a
 * repeating fault (a permanently hung loop re-alarming every few seconds)
 * cannot grow it until the disk ends.
 */
final class DiagnosticLog
{
    /** Generous for a post-mortem, harmless on disk. */
    public const int MAX_BYTES = 262_144;

    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes = self::MAX_BYTES,
    ) {}

    /** The channel's log under $rootDir's `.porta/` directory — the shared naming convention for error/dap/freeze logs. */
    public static function forRoot(string $rootDir, string $channel): self
    {
        return new self($rootDir.'/.porta/'.$channel.'.log');
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Append one entry, creating the parent directory on first use. */
    public function append(string $entry): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir) && is_dir(\dirname($dir))) {
            mkdir($dir, permissions: 0o755, recursive: true);
        }
        if (!is_dir($dir)) {
            return; // unwritable location: stay silent by contract
        }
        $oversized = is_file($this->path) && (int) filesize($this->path) > $this->maxBytes;
        file_put_contents($this->path, $entry."\n", $oversized ? 0 : \FILE_APPEND);
    }
}
