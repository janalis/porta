<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\PortaJsonWriter;
use Porta\Editor\EditorViewOptions;
use Porta\Git\GitRefresher;
use Porta\Git\GitSettings;
use Porta\I18n\Translator;
use Porta\Ui\Notifier;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Tui\Tui;

/**
 * The model behind the settings view's "Version control > Git" page: exposes the
 * eight git settings as rows, applies each change (live for the two display
 * toggles — mutate the holder + repaint/relayout directly, like
 * {@see SettingsLspController} does its own live-apply — holder-only for the
 * commit/interval ones, restart-required for the git path), persists it into
 * .porta.json, and flashes. The General page stays untouched.
 */
final class SettingsGitController
{
    /** key => [label, kind, restart]. The single source for both rows and the flash. */
    private const array META = [
        'gitShowBranch' => ['Show branch in status bar', SettingsRowKind::Bool, false],
        'gitGutterMarks' => ['Change marks in the gutter', SettingsRowKind::Bool, false],
        'gitBlameDefault' => ['Show blame annotations by default', SettingsRowKind::Bool, false],
        'gitSignCommits' => ['Sign commits (GPG)', SettingsRowKind::Bool, false],
        'gitNoVerify' => ['Skip commit hooks (--no-verify)', SettingsRowKind::Bool, false],
        'gitSignoff' => ['Add Signed-off-by trailer', SettingsRowKind::Bool, false],
        'gitRefreshInterval' => ['Status refresh interval (seconds)', SettingsRowKind::Int, false],
        'gitPath' => ['Git executable path', SettingsRowKind::Text, true],
    ];

    /**
     * @param array<string, int|bool|string> $settings resolved settings (defaults merged)
     */
    public function __construct(
        #[Autowire('%env(json:PORTA_SETTINGS)%')]
        private array $settings,
        private readonly GitSettings $gitSettings,
        private readonly EditorViewOptions $editor,
        private readonly GitRefresher $gitRefresher,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly string $rootDir,
    ) {}

    /** @return list<SettingsRow> */
    public function rows(): array
    {
        $rows = [];
        foreach (self::META as $key => [$label, $kind, $restart]) {
            $raw = $this->settings[$key] ?? '';
            $value = $kind->coerce($raw);
            $rows[] = new SettingsRow(
                key: $key,
                label: $this->translator->trans($label),
                kind: $kind,
                value: $value,
                restart: $restart,
                min: SettingsRowKind::Int === $kind ? 1 : null,
                max: SettingsRowKind::Int === $kind ? 60 : null,
                hint: 'gitPath' === $key ? $this->translator->trans('auto-detect') : null,
            );
        }

        return $rows;
    }

    public function change(string $key, bool|int|string $value): void
    {
        $meta = self::META[$key] ?? null;
        if (null === $meta) {
            $this->notifier->flash($this->translator->trans('Unknown setting "%key%"', ['%key%' => $key]));

            return;
        }

        $this->settings[$key] = $value;
        // The two display toggles apply live; the rest mutate the holder (read
        // lazily at commit/poll) or are restart-required (gitPath).
        match ($key) {
            'gitShowBranch' => $this->applyShowBranch((bool) $value),
            'gitGutterMarks' => $this->applyGutter((bool) $value),
            'gitBlameDefault' => $this->gitSettings->setBlameDefault((bool) $value),
            'gitSignCommits' => $this->gitSettings->setSignCommits((bool) $value),
            'gitNoVerify' => $this->gitSettings->setNoVerify((bool) $value),
            'gitSignoff' => $this->gitSettings->setSignoff((bool) $value),
            'gitRefreshInterval' => $this->gitSettings->setRefreshInterval((int) $value),
            default => null, // gitPath: applies on restart
        };

        $persisted = PortaJsonWriter::persistSetting($this->rootDir, $key, $value);
        $state = \is_bool($value) ? $this->translator->trans($value ? 'on' : 'off') : (string) $value;
        $note = $meta[2] ? ' · '.$this->translator->trans('applies on restart') : '';
        $saved = $persisted ? '' : ' ('.$this->translator->trans('not saved').')';
        $this->notifier->flash(\sprintf('%s %s%s%s', $this->translator->trans($meta[0]), $state, $note, $saved));
    }

    /** Live "show branch in status bar": mutate the holder and repaint. */
    private function applyShowBranch(bool $on): void
    {
        $this->gitSettings->setShowBranch($on);
        $this->statusBar->invalidate();
        $this->tui->requestRender();
    }

    /**
     * Live "git change-marks in gutter": mutate the holder, grow/shrink the
     * gutter, and repopulate marks when turning back on (disable clears them).
     */
    // @mago-expect lint:no-boolean-flag-parameter -- a live on/off setting applier
    private function applyGutter(bool $on): void
    {
        $this->gitSettings->setGutterMarks($on);
        // @mago-expect lint:no-else-clause -- symmetric on/off branches converging on the shared requestRender() below
        if ($on) {
            $this->editor->enableGitGutter();
            $this->gitRefresher->refreshActiveMarks();
        } else {
            $this->editor->disableGitGutter();
        }
        $this->tui->requestRender();
    }
}
