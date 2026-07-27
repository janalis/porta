<?php

declare(strict_types=1);

namespace Porta\Settings\Section;

use Porta\Settings\SettingsGitController;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsSection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** The Version control > Git page. */
#[AsTaggedItem(priority: 300)]
final readonly class GitSection implements SettingsSection
{
    public function __construct(
        private SettingsGitController $git,
    ) {}

    public function handles(string $section): bool
    {
        return 'vcs:git' === $section;
    }

    /** @return list<SettingsRow> */
    public function rows(string $section): array
    {
        return $this->git->rows();
    }

    public function change(string $section, string $key, bool|int|string $value): void
    {
        $this->git->change($key, $value);
    }
}
