<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Psr\Clock\ClockInterface;

/**
 * A bounded history of language-server output (stderr chunks and
 * window/logMessage notifications), so a misbehaving server can be debugged
 * from inside the IDE — the `ide_lsp_log` command shows the entries
 * newest-first in a list overlay. Chunks are split into lines here; stderr
 * reads are not line-aligned, so a long line may span two entries.
 */
final class LspLog
{
    private const int MAX_ENTRIES = 200;

    private const int MAX_LINE_LENGTH = 500;

    /** @var list<array{time: \DateTimeImmutable, text: string}> oldest first */
    private array $entries = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    public function append(string $chunk): void
    {
        foreach (explode("\n", $chunk) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $this->entries[] = ['time' => $this->clock->now(), 'text' => mb_substr($line, start: 0, length: self::MAX_LINE_LENGTH)];
        }
        if (\count($this->entries) > self::MAX_ENTRIES) {
            $this->entries = \array_slice($this->entries, -self::MAX_ENTRIES);
        }
    }

    /** @return list<array{time: \DateTimeImmutable, text: string}> oldest first */
    public function entries(): array
    {
        return $this->entries;
    }
}
