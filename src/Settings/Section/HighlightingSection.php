<?php

declare(strict_types=1);

namespace Porta\Settings\Section;

use Porta\I18n\Translator;
use Porta\Settings\SettingsHighlightController;
use Porta\Settings\SettingsPage;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsScopeRow;
use Porta\Settings\SettingsSection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** The Highlighting page: which syntax backend serves which grammar. */
#[AsTaggedItem(priority: 500)]
final readonly class HighlightingSection implements SettingsSection
{
    public function __construct(
        private SettingsHighlightController $highlighting,
        private Translator $translator,
        private SettingsScopeRow $scopeRow,
    ) {}

    /** @return list<SettingsPage> */
    #[\Override]
    public function pages(): array
    {
        return [new SettingsPage('highlighting', $this->translator->trans('Highlighting'))];
    }

    #[\Override]
    public function handles(string $section): bool
    {
        return 'highlighting' === $section;
    }

    /** @return list<SettingsRow> */
    #[\Override]
    public function rows(string $section): array
    {
        return $this->scopeRow->lead($this->highlighting->rows());
    }

    #[\Override]
    public function change(string $section, string $key, bool|int|string $value): bool
    {
        if ($this->scopeRow->owns($key)) {
            $this->scopeRow->change($value);

            return true;
        }
        $this->highlighting->change($key, (string) $value);

        return true; // a backend id is a closed choice; nothing to refuse
    }

    /** A blank id already means "no preference", which IS this page's default. */
    #[\Override]
    public function reset(string $section, string $key): void
    {
        $this->highlighting->change($key, '');
    }
}
