<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

/**
 * The version a program names for itself, out of whatever it printed.
 *
 * Every tool says it differently — `Symfony Language Tools 0.21.0`,
 * `dot - graphviz version 16.1.0 (20260904.0139)`, `custos 0.1.6`,
 * `PHPStan - PHP Static Analysis Tool 2.1.17` — and all of them put a
 * dotted triple somewhere, so the first one is the version. Bare, without a
 * tag's leading `v`: this is what the BINARY says, and comparisons normalise
 * a tag on the way in rather than pretending the two spellings are one.
 */
final class VersionText
{
    /** The first `major.minor.patch[suffix]` in $output, or null when it names none. */
    public static function of(string $output): ?string
    {
        $matches = [];

        return 1 === \preg_match('/\b(\d+\.\d+\.\d+[0-9A-Za-z.\-]*)/', $output, $matches) ? $matches[1] : null;
    }
}
