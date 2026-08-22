<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\I18n\Translator;

/**
 * The " (not saved)" suffix every settings controller appends to its flash
 * when a write to disk failed, so the user knows the change is live but not
 * persisted.
 */
final class SettingsFlash
{
    // @mago-expect lint:no-boolean-flag-parameter -- $persisted is the write-result being formatted, not a mode switch
    public static function savedSuffix(Translator $translator, bool $persisted): string
    {
        return $persisted ? '' : ' ('.$translator->trans('not saved').')';
    }
}
