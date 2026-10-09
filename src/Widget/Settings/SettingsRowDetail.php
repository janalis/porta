<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Config\ConfigScope;
use Porta\I18n\Translator;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;

/**
 * The dim trailing clause on a settings row: what the value may be, and what
 * the row is for.
 *
 * The form used to render `hint` for `Text` rows alone, and only while their
 * value was empty — so it behaved as a placeholder rather than a description,
 * and a hint declared on a Bool, Choice, Int or Action row went to the screen
 * nowhere at all. Three shipped hints were dead text on arrival, and a plugin
 * could declare one on any kind and never see it.
 *
 * Nothing else told you where a number stopped, so its range was discovered
 * by hammering a key. A choice's options are not listed here: its dropdown
 * shows them all, the way a browser's `<select>` does.
 *
 * Clauses are ordered most-important first, because the row truncates from the
 * right. The restart note leads: it is rare and consequential. The authored
 * hint comes before the range, because the range is discoverable by pressing
 * the key and the prose is not.
 */
final class SettingsRowDetail
{
    /** The clause text, unstyled and unpadded; '' when the row has nothing to add. */
    public static function of(SettingsRow $row, Translator $translator): string
    {
        $clauses = [];
        if ($row->restart) {
            $clauses[] = $translator->trans('applies on restart');
        }
        if (null !== $row->hint && '' !== $row->hint) {
            $clauses[] = $row->hint;
        }
        $domain = self::domain($row);
        if ('' !== $domain) {
            $clauses[] = $domain;
        }
        $origin = self::origin($row, $translator);
        if ('' !== $origin) {
            $clauses[] = $origin;
        }

        return \implode(' · ', $clauses);
    }

    /**
     * Where the shown value came from, when that is not obvious.
     *
     * Silent for the ordinary case — a value the project file sets and nothing
     * else — because a tag on every row is a tag on no row. It speaks up for
     * the two cases a user cannot otherwise see: a value coming from the file
     * they are not currently editing, and a project value quietly hiding a
     * global one.
     *
     * Last of the clauses, so a narrow row truncates this before it truncates
     * what the setting is for.
     */
    private static function origin(SettingsRow $row, Translator $translator): string
    {
        return match (true) {
            null === $row->origin => '', // a default; nothing set it anywhere
            $row->shadowed => $translator->trans('overrides global'),
            ConfigScope::Global === $row->origin => $translator->trans('from global'),
            default => '',
        };
    }

    /** What a bounded number may become: its range. */
    private static function domain(SettingsRow $row): string
    {
        return SettingsRowKind::Int === $row->kind ? self::range($row->min, $row->max) : '';
    }

    /**
     * `1-8`, or a single-sided bound, or nothing when the number is unbounded.
     *
     * A HYPHEN rather than the en dash typography would prefer: an en dash is
     * an ordinary neutral, so UAX#9 never joins the numbers either side of it,
     * and under a right-to-left base each takes the paragraph direction between
     * them and the two swap — `1–8` reads `8–1`, a range stated backwards. A
     * hyphen is a separator, which W4 folds into the number itself.
     */
    private static function range(?int $min, ?int $max): string
    {
        return match (true) {
            null !== $min && null !== $max => $min.'-'.$max,
            null !== $min => '≥ '.$min,
            null !== $max => '≤ '.$max,
            default => '',
        };
    }
}
