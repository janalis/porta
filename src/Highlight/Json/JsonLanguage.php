<?php

declare(strict_types=1);

namespace Porta\Highlight\Json;

use Tempest\Highlight\Languages\Json\JsonLanguage as TempestJsonLanguage;

/**
 * JSON highlighting with escape-aware strings and full value coverage.
 *
 * Tempest's built-in JSON grammar matches a string as `".*?"`, which cannot see
 * an escaped quote: on `"she said \"hi\""` the non-greedy match stops at the
 * backslash-escaped quote, so the token ends mid-string and every remaining
 * quote on the line is off by one — the rest of the line renders with inverted
 * string/code coloring. {@see JsonPatterns} consumes escape pairs instead, so
 * quote parity survives any `\"`, `\\` or trailing-backslash content.
 *
 * Two further gaps are closed there: the built-in key pattern accepts only
 * `[\w\-]`, so keys carrying `$`, `@`, `/`, `.` or spaces (JSON Schema, npm
 * scoped names) fell through to the *value* rule and took the string color; and
 * numbers, `true`, `false` and `null` had no pattern at all, leaving a large
 * share of a typical document uncolored.
 *
 * Registered over the built-in `json` language by name.
 */
final class JsonLanguage extends TempestJsonLanguage
{
    public function getPatterns(): array
    {
        return [...JsonPatterns::base(), ...JsonPatterns::core()];
    }
}
