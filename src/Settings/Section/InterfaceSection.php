<?php

declare(strict_types=1);

namespace Porta\Settings\Section;

use Porta\I18n\Translator;
use Porta\Settings\SettingsController;
use Porta\Settings\SettingsPage;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsScopeRow;
use Porta\Settings\SettingsSection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** The Interface page: the appearance half of the general settings table. */
#[AsTaggedItem(priority: 600)]
final readonly class InterfaceSection implements SettingsSection
{
    public function __construct(
        private SettingsController $settings,
        private Translator $translator,
        private SettingsScopeRow $scopeRow,
    ) {}

    /** @return list<SettingsPage> */
    #[\Override]
    public function pages(): array
    {
        return [new SettingsPage('interface', $this->translator->trans('Interface'))];
    }

    #[\Override]
    public function handles(string $section): bool
    {
        return 'interface' === $section;
    }

    /** @return list<SettingsRow> */
    #[\Override]
    public function rows(string $section): array
    {
        return $this->scopeRow->lead($this->settings->interfaceRows());
    }

    #[\Override]
    public function change(string $section, string $key, bool|int|string $value): bool
    {
        if ($this->scopeRow->owns($key)) {
            $this->scopeRow->change($value);

            return true;
        }

        return $this->settings->changeSetting($key, $value);
    }

    #[\Override]
    public function reset(string $section, string $key): void
    {
        $this->settings->resetSetting($key);
    }
}
