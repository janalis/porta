<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Settings\SettingsRow;

/**
 * The column widths one page of settings rows renders in.
 *
 * The form used to concatenate a hard-coded two spaces after each label, so the
 * value column started wherever that row's label happened to end — a ragged
 * edge that made a page impossible to scan down. Worse, a `Bool` row put its
 * checkbox BEFORE the label while every other kind put its control after, so
 * the two halves of a mixed page disagreed about which column meant what.
 *
 * Measured per page rather than per row, and capped at half the pane so one
 * pathological label cannot push every value off the right edge.
 */
final readonly class SettingsRowLayout
{
    /** A label column narrower than this is not worth aligning to. */
    private const int MIN_LABEL = 8;

    private function __construct(
        /** Columns the label occupies, gap excluded. */
        public int $label,
    ) {}

    /**
     * @param list<SettingsRow> $rows
     */
    public static function measure(array $rows, int $width): self
    {
        $longest = 0;
        foreach ($rows as $row) {
            $longest = \max($longest, \mb_strwidth($row->label));
        }

        // @mago-expect lint:literal-named-argument -- intdiv's parameter is called `num2`; `intdiv($width, num2: 2)` names the divisor after nothing anyone thinks in
        return new self(\min($longest, \max(self::MIN_LABEL, \intdiv($width, 2))));
    }
}
