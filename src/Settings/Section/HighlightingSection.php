<?php

declare(strict_types=1);

namespace Porta\Settings\Section;

use Porta\Settings\SettingsHighlightController;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsSection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** The Highlighting page: which syntax backend serves which grammar. */
#[AsTaggedItem(priority: 500)]
final readonly class HighlightingSection implements SettingsSection
{
    public function __construct(
        private SettingsHighlightController $highlighting,
    ) {}

    public function handles(string $section): bool
    {
        return 'highlighting' === $section;
    }

    /** @return list<SettingsRow> */
    public function rows(string $section): array
    {
        return $this->highlighting->rows();
    }

    public function change(string $section, string $key, bool|int|string $value): void
    {
        $this->highlighting->change($key, (string) $value);
    }
}
