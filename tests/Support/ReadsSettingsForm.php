<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\Settings\SettingsFormWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Read and drive the settings form by LABEL rather than by row index.
 *
 * The sibling {@see NavigatesSettings} exists because the nav is
 * index-addressed and inserting one section moved ~20 call sites. The form has
 * the same shape and was about to learn the same lesson: it grew a title row
 * and a footer, which shifts every rendered index, and its value column stopped
 * being a hard-coded two spaces. Tests that spelled either out had to change
 * together, and two of them spelled it inside `awaitPainted`, where a stale
 * string is a ten-second timeout rather than a failure.
 *
 * So: no test should know which row number a setting is on, nor how many
 * spaces sit between its label and its value.
 */
trait ReadsSettingsForm
{
    /** Satisfied by the host TestCase (PHPUnit's Assert::fail). */
    abstract public static function fail(string $message = ''): never;

    /**
     * The rendered line carrying $label, ANSI stripped and right-trimmed.
     *
     * @param list<string> $lines a widget render, or a screen grid
     */
    private function settingRow(array $lines, string $label): string
    {
        foreach ($lines as $line) {
            $plain = AnsiUtils::stripAnsiCodes($line);
            if (str_contains($plain, $label)) {
                return rtrim($plain);
            }
        }

        self::fail(\sprintf('No settings row labelled "%s" is on screen', $label));
    }

    /**
     * Whether a row labelled $label is currently rendered.
     *
     * @param list<string> $lines a widget render, or a screen grid
     */
    private function hasSettingRow(array $lines, string $label): bool
    {
        foreach ($lines as $line) {
            if (str_contains(AnsiUtils::stripAnsiCodes($line), $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move the form's selection onto the row labelled $label, with real input
     * so the widget's own navigation runs — never by poking the selection.
     */
    private function selectSettingRow(SettingsFormWidget $form, string $label, int $columns = 80, int $rows = 24): void
    {
        for ($i = 0; $i < self::MAX_FORM_ROWS; ++$i) {
            $form->handleInput("\x1b[A");
        }
        for ($i = 0; $i < self::MAX_FORM_ROWS; ++$i) {
            if (str_contains($this->selectedSettingRow($form, $columns, $rows), $label)) {
                return;
            }
            $form->handleInput("\x1b[B");
        }

        self::fail(\sprintf('Settings row "%s" is not reachable in the form', $label));
    }

    /**
     * The selected row's text, found by its selection shading rather than by
     * index — the shading is what a user sees, and it survives a header row.
     *
     * The title and the footer carry a background of their own, so the search
     * runs over the setting rows only.
     */
    private function selectedSettingRow(SettingsFormWidget $form, int $columns = 80, int $rows = 24): string
    {
        $lines = $form->render(new RenderContext($columns, $rows));
        foreach (array_slice($lines, offset: 1, length: -1) as $line) {
            if (str_contains($line, "\x1b[48;5;")) {
                return rtrim(AnsiUtils::stripAnsiCodes($line));
            }
        }

        return '';
    }

    /** Generous bound: the longest settings page plus its chrome. */
    private const int MAX_FORM_ROWS = 64;
}
