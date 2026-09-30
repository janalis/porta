<?php

declare(strict_types=1);

namespace Porta\I18n;

/**
 * The UI locale the system asks for — what the `auto` language setting means.
 *
 * Read from the POSIX variables in their own precedence (`LC_ALL`, then
 * `LC_MESSAGES`, then `LANG`), skipping unset, empty and the `C`/`POSIX`
 * non-choices. A value like `fr_FR.UTF-8` or `de_DE@euro` names its language
 * by the leading subtag; a language Porta ships no catalog for is English.
 *
 * When none of them decides, the caller's fallback does: ICU's default locale
 * (`\Locale::getDefault()`, ext-intl), which on Windows is the user's display
 * language — Windows sets no `LANG`. On Linux and macOS ICU reads the same
 * variables, so there it adds nothing. Nothing is spawned: a boot must not
 * wait on a system query.
 */
final readonly class SystemLocale
{
    private const array VARIABLES = ['LC_ALL', 'LC_MESSAGES', 'LANG'];

    /**
     * @param array<string, string|false> $env
     * @param string                      $fallback a locale name (`fr_FR`, `fr-FR`) read when no variable decides; '' for none
     */
    public static function fromEnvironment(array $env, string $fallback = ''): string
    {
        foreach ([...\array_map(static fn (string $name): string|false => $env[$name] ?? false, self::VARIABLES), $fallback] as $value) {
            if (false === $value || '' === $value || 'C' === $value || 'POSIX' === $value) {
                continue;
            }
            $language = \strtolower((string) \preg_replace('/[_.@-].*$/', replacement: '', subject: $value));

            return \in_array($language, Translator::LOCALES, strict: true) ? $language : 'en';
        }

        return 'en';
    }
}
