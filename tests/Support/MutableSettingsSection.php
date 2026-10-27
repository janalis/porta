<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Settings\SettingsPage;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Settings\SettingsSection;

/** A section of plain toggles whose row set can move between searches, as a plugin toggle moves it. */
final class MutableSettingsSection implements SettingsSection
{
    /** @param array<string, string> $rows key => label */
    public function __construct(
        private readonly string $id = 'general',
        private readonly string $label = 'General',
        private array $rows = ['theme' => 'Theme'],
    ) {}

    public function addRow(string $key, string $label): void
    {
        $this->rows[$key] = $label;
    }

    public function handles(string $section): bool
    {
        return $this->id === $section;
    }

    public function pages(): array
    {
        return [new SettingsPage($this->id, $this->label)];
    }

    public function rows(string $section): array
    {
        $out = [];
        foreach ($this->rows as $key => $label) {
            $out[] = new SettingsRow(key: $key, label: $label, kind: SettingsRowKind::Bool, value: true, restart: false);
        }

        return $out;
    }

    public function change(string $section, string $key, bool|int|string $value): bool
    {
        return true;
    }

    public function reset(string $section, string $key): void {}
}
