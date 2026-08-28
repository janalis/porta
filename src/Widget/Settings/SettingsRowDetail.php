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
 * Nothing else told you what a stepper could become either: `< dark >` gave no
 * hint that `light` and `high-contrast` existed, and a bounded number gave no
 * hint where it stopped, so both were discovered by hammering a key.
 *
 * Clauses are ordered most-important first, because the row truncates from the
 * right. The restart note leads: it is rare and consequential. The authored
 * hint comes before the option list, because the list is discoverable by
 * pressing the key and the prose is not — a Choice whose options are long
 * labels would otherwise push its own explanation off the edge.
 */
final class SettingsRowDetail
{
    /** Beyond this many options the list stops being an aid and becomes noise. */
    private const int MAX_OPTIONS = 6;

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

        return implode(' · ', $clauses);
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
        if (null === $row->origin) {
            return ''; // a default; nothing set it anywhere
        }
        if ($row->shadowed) {
            return $translator->trans('overrides global');
        }

        return ConfigScope::Global === $row->origin ? $translator->trans('from global') : '';
    }

    /**
     * What the control may become: a bounded number's range, or a choice's
     * options.
     *
     * `/` joins them, which {@see \Porta\Ui\ShortcutFormatter} fixes as the
     * separator for alternatives of one thing — `·` already separates the
     * clauses around it.
     */
    private static function domain(SettingsRow $row): string
    {
        if (SettingsRowKind::Int === $row->kind) {
            return self::range($row->min, $row->max);
        }
        if (SettingsRowKind::Choice !== $row->kind || null === $row->options) {
            return '';
        }
        // What it can BECOME: the current value already sits in the control,
        // and repeating it there costs the room the rest of the list needs.
        $others = array_values(array_filter($row->options, static fn (string $option): bool => $option !== (string) $row->value));
        // A long list would push the hint off a narrow row for no gain: the
        // stepper still walks them all.
        if ([] === $others || \count($others) > self::MAX_OPTIONS) {
            return '';
        }

        return implode('/', array_map(static fn (string $option): string => self::optionLabel($row, $option), $others));
    }

    /** `1–8`, or a single-sided bound, or nothing when the number is unbounded. */
    private static function range(?int $min, ?int $max): string
    {
        if (null !== $min && null !== $max) {
            return $min.'–'.$max;
        }
        if (null !== $min) {
            return '≥ '.$min;
        }

        return null === $max ? '' : '≤ '.$max;
    }

    /** An option's display label, falling back to the raw value. */
    private static function optionLabel(SettingsRow $row, string $option): string
    {
        $label = $row->optionLabels[$option] ?? $option;

        return '' === $label ? $option : $label;
    }
}
