<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * ANSI-stripped views of rendered widget lines — the scrape primitive the
 * widget suites re-declared per file. {@see plain()} joins into one
 * searchable text block; {@see rows()} keeps the per-row grid for
 * row-indexed assertions.
 */
trait RendersWidget
{
    /** @param list<string> $lines */
    private function plain(array $lines): string
    {
        return \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function rows(array $lines): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $lines);
    }
}
