<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Help\RenderedDoc;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A rendered markdown page read as text: every line ANSI-stripped and
 * right-trimmed, so a test asserts on the layout and leaves the styling to
 * the assertions that name a role.
 */
trait ReadsRenderedDoc
{
    /** @return list<string> */
    private static function plain(RenderedDoc $doc): array
    {
        return \array_map(static fn (string $line): string => \rtrim(AnsiUtils::stripAnsiCodes($line)), $doc->lines);
    }
}
