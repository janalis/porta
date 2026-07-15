<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\BufferManager;

/**
 * The source lines just above and below a search match (for the results
 * panel's Alt+. context expansion), read from the open buffer when the file
 * is loaded, else from disk. The match line itself is omitted — it is already
 * shown as the result row.
 */
final class ResultContextProvider
{
    public function __construct(
        private readonly BufferManager $buffers,
        private readonly string $rootDir,
    ) {}

    /** @return list<array{line: int, text: string}> */
    public function around(string $relativePath, int $line): array
    {
        $abs = $this->rootDir.'/'.$relativePath;
        $buffer = $this->buffers->get($abs) ?? $this->buffers->get(($real = realpath($abs)) ? $real : $abs);
        $lines = null !== $buffer
            ? $buffer->document->getLines()
            : explode("\n", is_file($abs) ? (string) file_get_contents($abs) : '');

        $context = [];
        for ($i = max(0, $line - 2); $i <= min(\count($lines) - 1, $line + 2); ++$i) {
            if ($i === $line) {
                continue;
            }

            $context[] = ['line' => $i, 'text' => rtrim($lines[$i])];
        }

        return $context;
    }
}
