<?php

declare(strict_types=1);

namespace Porta\Settings\Section;

use Porta\Settings\SettingsController;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsSection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The General page — and the fallback for any nav id no other section claims,
 * which is why {@see handles()} always returns true and the tag priority sorts
 * this last. It reproduces the old if-chain's final `else`.
 */
#[AsTaggedItem(priority: 100)]
final readonly class GeneralSection implements SettingsSection
{
    public function __construct(
        private SettingsController $settings,
    ) {}

    #[\Override]
    public function handles(string $section): bool
    {
        return true;
    }

    /** @return list<SettingsRow> */
    #[\Override]
    public function rows(string $section): array
    {
        return $this->settings->formRows();
    }

    #[\Override]
    public function change(string $section, string $key, bool|int|string $value): void
    {
        $this->settings->changeSetting($key, $value);
    }

    #[\Override]
    public function reset(string $section, string $key): void
    {
        $this->settings->resetSetting($key);
    }
}
