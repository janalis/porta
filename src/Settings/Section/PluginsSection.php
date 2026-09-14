<?php

declare(strict_types=1);

namespace Porta\Settings\Section;

use Porta\I18n\Translator;
use Porta\Settings\SettingsPage;
use Porta\Settings\SettingsPluginsController;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsSection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Plugins page and every per-plugin sub-page. Both live here because they
 * are one controller and one nav family: `plugins` lists and installs,
 * `plugin:<name>` shows that plugin's own settings.
 */
#[AsTaggedItem(priority: 400)]
final readonly class PluginsSection implements SettingsSection
{
    private const string PREFIX = 'plugin:';

    public function __construct(
        private SettingsPluginsController $plugins,
        private Translator $translator,
    ) {}

    #[\Override]
    public function handles(string $section): bool
    {
        return 'plugins' === $section || \str_starts_with($section, self::PREFIX);
    }

    /**
     * The index, then one page per installed plugin — from the same call the
     * nav's sub-items come from.
     *
     * Plugin names are NOT translated: they are directory names the user
     * installed, which is the rule the nav renders by.
     *
     * @return list<SettingsPage>
     */
    #[\Override]
    public function pages(): array
    {
        $pages = [new SettingsPage('plugins', $this->translator->trans('Plugins'))];
        foreach ($this->plugins->pluginNames() as $name) {
            $pages[] = new SettingsPage(self::PREFIX.$name, $name);
        }

        return $pages;
    }

    /** @return list<SettingsRow> */
    #[\Override]
    public function rows(string $section): array
    {
        return 'plugins' === $section ? $this->plugins->rows() : $this->plugins->pluginRows($this->name($section));
    }

    #[\Override]
    public function change(string $section, string $key, bool|int|string $value): bool
    {
        if ('plugins' === $section) {
            $this->plugins->change($key, (string) $value);

            return true;
        }

        // The scalar passes through untouched — bool/int rows need their types.
        $this->plugins->changePlugin($this->name($section), $key, $value);

        return true;
    }

    /**
     * Only a per-plugin page has resettable rows; the Plugins index is a mode
     * switch, an install field and a list of buttons.
     */
    #[\Override]
    public function reset(string $section, string $key): void
    {
        if ('plugins' !== $section) {
            $this->plugins->resetPlugin($this->name($section), $key);
        }
    }

    private function name(string $section): string
    {
        return \substr($section, offset: \strlen(self::PREFIX));
    }
}
