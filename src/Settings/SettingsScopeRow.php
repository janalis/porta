<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Container\AsIdeService;
use Porta\I18n\Translator;

/**
 * The "Save changes to" row, and the one place that knows how to render and
 * actuate it.
 *
 * It leads every page that writes a file, because it changes what every row
 * below it does. A page whose rows all apply to the session — Keybindings has
 * its own file verb, the Plugins list actuates installs — is not such a page
 * and does not get one.
 *
 * The key is shared across pages on purpose. Row keys are not unique anyway
 * (`indentStyle` is on thirteen), and a single key means the search index
 * offers "Save changes to" once per page it actually appears on, each labelled
 * by its page, rather than inventing a name per section.
 */
#[AsIdeService]
final readonly class SettingsScopeRow
{
    public const string KEY = 'configScope';

    public function __construct(
        private SettingsScope $scope,
        private ConfigFiles $files,
        private Translator $translator,
    ) {}

    /**
     * The row, or null when there is nothing to choose: an environment with no
     * home has one writable file, and a chooser offering one option is a
     * control that cannot be wrong and cannot be useful.
     *
     * $also is for a page where this choice governs more than the file — the
     * Plugins page, where it also decides where an install lands. The row
     * names a FILE, so a page whose scope means a directory too has to say so
     * or the control under-describes itself.
     */
    public function row(?string $also = null): ?SettingsRow
    {
        if (null === $this->files->path(ConfigScope::Global)) {
            return null;
        }

        return new SettingsRow(
            key: self::KEY,
            label: $this->translator->trans('Save changes to'),
            kind: SettingsRowKind::Choice,
            value: $this->scope->current()->value,
            restart: false,
            options: \array_map(static fn (ConfigScope $scope): string => $scope->value, ConfigScope::inPrecedenceOrder()),
            optionLabels: [
                ConfigScope::Global->value => $this->files->label(ConfigScope::Global),
                ConfigScope::Project->value => $this->files->label(ConfigScope::Project),
            ],
            // The file names ARE the explanation, so the hint says the parts a
            // filename cannot: that this is a session choice rather than a
            // setting, and anything else this page's scope decides.
            hint: null === $also
                ? $this->translator->trans('for this session')
                : $this->translator->trans('for this session').' · '.$also,
        );
    }

    /**
     * Prepend the row to a page's own rows, when there is one.
     *
     * @param list<SettingsRow> $rows
     *
     * @return list<SettingsRow>
     */
    public function lead(array $rows, ?string $also = null): array
    {
        $row = $this->row($also);

        return null === $row ? $rows : [$row, ...$rows];
    }

    /** Whether $key is this row; the caller routes its own keys otherwise. */
    public function owns(string $key): bool
    {
        return self::KEY === $key;
    }

    /** Apply a chosen value. An unrecognised one leaves the scope alone. */
    public function change(bool|int|string $value): void
    {
        $scope = ConfigScope::tryFrom((string) $value);
        if (null !== $scope) {
            $this->scope->set($scope);
        }
    }
}
