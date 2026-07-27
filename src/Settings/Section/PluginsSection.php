<?php

declare(strict_types=1);

namespace Porta\Settings\Section;

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
    ) {}

    public function handles(string $section): bool
    {
        return 'plugins' === $section || str_starts_with($section, self::PREFIX);
    }

    /** @return list<SettingsRow> */
    public function rows(string $section): array
    {
        return 'plugins' === $section ? $this->plugins->rows() : $this->plugins->pluginRows($this->name($section));
    }

    public function change(string $section, string $key, bool|int|string $value): void
    {
        if ('plugins' === $section) {
            $this->plugins->change($key, (string) $value);

            return;
        }

        // The scalar passes through untouched — bool/int rows need their types.
        $this->plugins->changePlugin($this->name($section), $key, $value);
    }

    private function name(string $section): string
    {
        return substr($section, offset: \strlen(self::PREFIX));
    }
}
