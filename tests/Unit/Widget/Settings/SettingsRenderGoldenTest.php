<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Tests\Support\AttachedStyles;
use Porta\Widget\Settings\SettingsFormWidget;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Locks the settings form's exact ANSI output across a fixture matrix.
 *
 * The {@see \Porta\Tests\Unit\Widget\Render\EditorRenderGoldenTest} argument,
 * applied to the other screen that is mostly styling: the ordinary widget tests
 * strip ANSI, so they can assert that a label is present but not where its
 * value column starts, how far a dim span runs, or which shade an armed row
 * wears. Column positions and dim spans are exactly what the alignment,
 * modified-marker and detail-column work moves, and a point assertion cannot
 * express any of them.
 *
 * Seeded BEFORE that work, so each subsequent commit's regeneration diff is the
 * review artefact for its visual change. A regeneration is therefore not a
 * formality — read the diff.
 *
 * Regenerate after an intentional visual change:
 *   UPDATE_SETTINGS_GOLDEN=1 vendor/bin/phpunit tests/Unit/Widget/Settings/SettingsRenderGoldenTest.php
 */
final class SettingsRenderGoldenTest extends TestCase
{
    private const GOLDEN = __DIR__.'/../../../Support/golden/settings-render.json';

    public function testRenderOutputMatchesGolden(): void
    {
        $actual = $this->renderAllScenarios();

        if (getenv('UPDATE_SETTINGS_GOLDEN')) {
            if (!is_dir(\dirname(self::GOLDEN))) {
                mkdir(\dirname(self::GOLDEN), permissions: 0o777, recursive: true);
            }
            file_put_contents(self::GOLDEN, json_encode($actual, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            $this->markTestSkipped('Golden regenerated.');
        }

        $this->assertFileExists(self::GOLDEN, 'run once with UPDATE_SETTINGS_GOLDEN=1 to seed');
        $expected = json_decode((string) file_get_contents(self::GOLDEN), associative: true);

        // Compare scenario by scenario for a readable diff on failure.
        foreach ($expected as $name => $rows) {
            $this->assertArrayHasKey($name, $actual, "scenario {$name} missing");
            $this->assertSame($rows, $actual[$name], "scenario {$name} render changed");
        }
        $this->assertSame(array_keys($expected), array_keys($actual), 'scenario set changed');
    }

    /** @return array<string, string[]> */
    private function renderAllScenarios(): array
    {
        $out = [];
        foreach ($this->scenarios() as $name => [$build, $cols, $rows]) {
            $widget = new SettingsFormWidget(new Translator('en'));
            AttachedStyles::attach($widget); // the selection shade resolves through the themed sheet
            $build($widget);
            $out[$name] = $widget->render(new RenderContext($cols, $rows));
        }

        return $out;
    }

    /**
     * @return array<string, array{0: callable(SettingsFormWidget): void, 1: int, 2: int}>
     */
    private function scenarios(): array
    {
        return [
            'every-kind-focused' => [
                function (SettingsFormWidget $w): void {
                    $w->setFocused(true);
                    $w->setRows($this->everyKind(), 'Interface');
                },
                72,
                10,
            ],

            'every-kind-unfocused' => [
                function (SettingsFormWidget $w): void {
                    $w->setFocused(false);
                    $w->setRows($this->everyKind(), 'Interface');
                },
                72,
                10,
            ],

            // The resting selection bar, one row down from the top.
            'selection-moved' => [
                function (SettingsFormWidget $w): void {
                    $w->setFocused(true);
                    $w->setRows($this->everyKind(), 'Interface');
                    $w->handleInput("\x1b[B");
                    $w->handleInput("\x1b[B");
                },
                72,
                10,
            ],

            'hovered-row' => [
                function (SettingsFormWidget $w): void {
                    $w->setFocused(true);
                    $w->setRows($this->everyKind(), 'Interface');
                    $w->hoverRow(3);
                },
                72,
                10,
            ],

            // An armed destructive action: today it wears DangerBg over every
            // other row state, selection included.
            'danger-armed-and-selected' => [
                static function (SettingsFormWidget $w): void {
                    $w->setFocused(true);
                    $w->setRows([
                        new SettingsRow(key: 'pluginRemove:project:demo', label: 'demo (project)', kind: SettingsRowKind::Action, value: 'Remove', restart: false, danger: true),
                        new SettingsRow(key: 'pluginRemove:project:other', label: 'other (project)', kind: SettingsRowKind::Action, value: 'Remove', restart: false),
                    ], 'Plugins');
                },
                72,
                6,
            ],

            // The empty-caption Action the plugins page ships today.
            'action-without-a-caption' => [
                static function (SettingsFormWidget $w): void {
                    $w->setFocused(true);
                    $w->setRows([
                        new SettingsRow(key: 'pluginInstallBinary', label: 'Install phpstan', kind: SettingsRowKind::Action, value: false, restart: false),
                    ], 'phpstan');
                },
                72,
                5,
            ],

            // A hint on a Text row shows; the identical hints on the Choice and
            // Int rows below it do not.
            'hints-across-kinds' => [
                static function (SettingsFormWidget $w): void {
                    $w->setFocused(true);
                    $w->setRows([
                        new SettingsRow(key: 'lspServer', label: 'LSP server', kind: SettingsRowKind::Text, value: '', restart: false, hint: 'auto: phpactor'),
                        new SettingsRow(
                            key: 'highlightDefault',
                            label: 'Default highlighter',
                            kind: SettingsRowKind::Choice,
                            value: '',
                            restart: false,
                            options: ['', 'chroma'],
                            optionLabels: ['' => 'auto (first available)'],
                            hint: 'Used for any language without its own choice below',
                        ),
                        new SettingsRow(key: 'maxBytes', label: 'Skip images larger than (bytes)', kind: SettingsRowKind::Int, value: 20_971_520, restart: false, hint: 'routing only'),
                    ], 'image-viewer');
                },
                72,
                7,
            ],

            // Ragged value column: three labels of very different lengths.
            'ragged-value-column' => [
                static function (SettingsFormWidget $w): void {
                    $w->setFocused(true);
                    $w->setRows([
                        new SettingsRow(key: 'a', label: 'Theme', kind: SettingsRowKind::Choice, value: 'dark', restart: false, options: ['dark', 'light']),
                        new SettingsRow(key: 'b', label: 'Sticky scroll', kind: SettingsRowKind::Bool, value: true, restart: false),
                        new SettingsRow(key: 'c', label: 'Skip images larger than (bytes)', kind: SettingsRowKind::Int, value: 4, restart: false),
                    ], 'General');
                },
                72,
                7,
            ],

            'restart-note' => [
                static function (SettingsFormWidget $w): void {
                    $w->setFocused(true);
                    $w->setRows([
                        new SettingsRow(key: 'runCommand', label: 'Run/debug command', kind: SettingsRowKind::Text, value: 'frankenphp php-cli', restart: true),
                    ], 'PHP');
                },
                72,
                5,
            ],

            'truncating-long-label' => [
                static function (SettingsFormWidget $w): void {
                    $w->setFocused(true);
                    $w->setRows([
                        new SettingsRow(key: 'long', label: 'A very long setting label that runs past the pane edge', kind: SettingsRowKind::Bool, value: true, restart: false),
                    ], 'General');
                },
                30,
                5,
            ],

            'editing-a-text-row' => [
                static function (SettingsFormWidget $w): void {
                    $w->setFocused(true);
                    $w->setRows([
                        new SettingsRow(key: 'lspServer', label: 'LSP server', kind: SettingsRowKind::Text, value: '', restart: false, hint: 'auto-detect'),
                    ], 'PHP');
                    $w->handleInput("\r"); // opens the inline editor
                    foreach (str_split('my-ls') as $char) {
                        $w->handleInput($char);
                    }
                },
                72,
                5,
            ],

            // A page taller than the pane, scrolled so the window is off the top.
            'scrolled-page' => [
                function (SettingsFormWidget $w): void {
                    $w->setFocused(true);
                    $rows = [];
                    for ($i = 0; $i < 20; ++$i) {
                        $rows[] = new SettingsRow(key: 'k'.$i, label: 'Setting '.$i, kind: SettingsRowKind::Bool, value: 0 === ($i % 2), restart: false);
                    }
                    $w->setRows($rows, 'Long');
                    for ($i = 0; $i < 12; ++$i) {
                        $w->handleInput("\x1b[B");
                    }
                },
                72,
                8,
            ],

            'empty-page' => [
                static function (SettingsFormWidget $w): void {
                    $w->setFocused(true);
                    $w->setRows([], 'Nothing');
                },
                72,
                5,
            ],
        ];
    }

    /** One row of every kind, so a layout change shows on all of them at once. */
    private function everyKind(): array
    {
        return [
            new SettingsRow(key: 'stickyScroll', label: 'Sticky scroll', kind: SettingsRowKind::Bool, value: true, restart: false),
            new SettingsRow(key: 'theme', label: 'Theme', kind: SettingsRowKind::Choice, value: 'dark', restart: false, options: ['dark', 'light', 'high-contrast']),
            new SettingsRow(key: 'tabWidth', label: 'Tab width', kind: SettingsRowKind::Int, value: 4, restart: false, min: 1, max: 8),
            new SettingsRow(key: 'lspServer', label: 'LSP server', kind: SettingsRowKind::Text, value: 'phpactor language-server', restart: false),
            new SettingsRow(key: 'pluginRemove:project:demo', label: 'demo (project)', kind: SettingsRowKind::Action, value: 'Remove', restart: false),
        ];
    }
}
