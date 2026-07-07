<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Config\ActionCatalog;
use Porta\Config\CommandRegistry;
use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Builds the application-menu structure (File / Edit / Navigate / …) from the
 * existing command + action systems, so menu labels, shortcuts and handlers stay
 * a single source of truth. Each item is either an **ide** command (run via
 * {@see CommandRegistry::run()}) or an **editor** action (run via
 * {@see CodeEditorWidget::runAction()}); shortcuts are resolved from the live
 * keybindings and formatted by {@see ShortcutFormatter}.
 */
final class MenuModel
{
    public const string SEP = '---';

    /**
     * title => list of 'scope:id' item specs (or SEP). scope is 'e' (editor) or 'i' (ide).
     *
     * @var array<string, list<string>>
     */
    private const array DEFS = [
        'File' => [
            'e:save',
            'e:save_all',
            'e:recent_files',
            'i:goto_file',
            'e:close_buffer',
            self::SEP,
            'i:ide_local_history',
            self::SEP,
            'i:ide_settings',
            'i:ide_quit',
        ],
        'Edit' => [
            'e:undo',
            'e:redo',
            self::SEP,
            'e:cut',
            'e:copy',
            'e:paste',
            self::SEP,
            'e:duplicate_line',
            'e:delete_line',
            'e:toggle_comment',
            'e:move_line_up',
            'e:move_line_down',
            self::SEP,
            'e:select_all',
            'i:reformat',
        ],
        'Navigate' => [
            'e:goto_definition',
            'e:goto_implementation',
            'e:goto_type_definition',
            'e:find_usages',
            self::SEP,
            'e:goto_line',
            'i:ide_goto_symbol',
            'e:file_structure',
            self::SEP,
            'e:rename_symbol',
            'e:navigate_back',
        ],
        'Code' => [
            'e:trigger_completion',
            'e:code_action',
            self::SEP,
            'e:expand_selection',
            'e:shrink_selection',
            'e:surround_with',
            self::SEP,
            'e:toggle_fold',
            'e:add_caret_below',
            'e:add_next_occurrence',
        ],
        'Run' => [
            'i:ide_run',
            'i:ide_run_last',
            self::SEP,
            'i:ide_terminal',
        ],
        'Git' => [
            'i:ide_git',
            self::SEP,
            'i:git_stage',
            'i:git_commit',
            'i:git_undo_commit',
            'i:git_blame',
        ],
        'View' => [
            'i:ide_problems',
            'i:ide_toggle_focus',
            self::SEP,
            'i:toggle_inline_diagnostics',
            'i:toggle_relative_line_numbers',
            'i:toggle_auto_pairs',
        ],
        'Help' => [
            'i:ide_command_palette',
            'e:quick_doc',
        ],
    ];

    /**
     * @param (\Closure(): bool)|null $gitViewOpen      while true, only GIT_VIEW_SAFE items stay enabled
     * @param (\Closure(): bool)|null $settingsViewOpen while true, only SETTINGS_VIEW_SAFE items stay enabled
     *
     * @return list<Menu>
     */
    public static function build(CommandRegistry $commands, CodeEditorWidget $editor, Keybindings $ideKeys, Translator $translator, ?\Closure $gitViewOpen = null, ?\Closure $settingsViewOpen = null): array
    {
        $menus = [];
        foreach (self::DEFS as $title => $specs) {
            $menus[] = new Menu($translator->trans($title), self::items($specs, $commands, $editor, $ideKeys, $translator, $gitViewOpen, $settingsViewOpen));
        }

        return $menus;
    }

    /**
     * Build a list of menu items from `scope:id` specs (or {@see SEP}). Shared by
     * the menu bar and the right-click context menus so labels, shortcuts and
     * run handlers stay a single source of truth.
     *
     * @param list<string>            $specs
     * @param (\Closure(): bool)|null $gitViewOpen
     * @param (\Closure(): bool)|null $settingsViewOpen
     *
     * @return list<MenuItem>
     */
    public static function items(array $specs, CommandRegistry $commands, CodeEditorWidget $editor, Keybindings $ideKeys, Translator $translator, ?\Closure $gitViewOpen = null, ?\Closure $settingsViewOpen = null): array
    {
        $items = [];
        foreach ($specs as $spec) {
            $items[] = self::SEP === $spec
                ? MenuItem::separator()
                : self::item($spec, $commands, $editor, $ideKeys, $translator, $gitViewOpen, $settingsViewOpen);
        }

        return $items;
    }

    /**
     * IDE commands that operate on the current file (so they are disabled when no
     * file is open, alongside every editor action except `recent_files`).
     */
    private const array FILE_SCOPED_IDE = ['reformat', 'ide_local_history', 'git_stage', 'git_blame'];

    /**
     * The only commands that stay enabled while the git view owns the main area:
     * everything else targets the detached editor/tree and would act invisibly.
     */
    private const array GIT_VIEW_SAFE = ['ide_quit', 'ide_git', 'git_commit', 'git_undo_commit', 'ide_command_palette'];

    /**
     * The only commands that stay enabled while the settings view owns the main
     * area: leaving it, closing the app, or the palette. Everything else targets
     * the detached editor/tree and would act invisibly.
     */
    private const array SETTINGS_VIEW_SAFE = ['ide_quit', 'ide_settings', 'ide_command_palette'];

    /**
     * @param (\Closure(): bool)|null $gitViewOpen
     * @param (\Closure(): bool)|null $settingsViewOpen
     */
    private static function item(string $spec, CommandRegistry $commands, CodeEditorWidget $editor, Keybindings $ideKeys, Translator $translator, ?\Closure $gitViewOpen, ?\Closure $settingsViewOpen): MenuItem
    {
        [$scope, $id] = explode(':', $spec, 2);
        $label = $translator->trans('i' === $scope
            ? $commands->label($id) ?? ActionCatalog::label($id)
            : ActionCatalog::label($id));
        $shortcut = ShortcutFormatter::format(self::binding($id, $editor, $ideKeys));
        $run = 'i' === $scope
            ? static fn () => $commands->run($id)
            : static fn () => $editor->runAction($id);

        $requiresFile = self::requiresOpenFile($scope, $id);
        $gitSafe = 'i' === $scope && \in_array($id, self::GIT_VIEW_SAFE, true);
        $settingsSafe = 'i' === $scope && \in_array($id, self::SETTINGS_VIEW_SAFE, true);
        $isEnabled = null === $gitViewOpen && null === $settingsViewOpen && !$requiresFile
            ? null
            : static fn (): bool => match (true) {
                null !== $settingsViewOpen && $settingsViewOpen() => $settingsSafe,
                null !== $gitViewOpen && $gitViewOpen() => $gitSafe,
                default => !$requiresFile || null !== $editor->getFilePath(),
            };

        return new MenuItem($label, $shortcut, $run, isEnabled: $isEnabled);
    }

    /**
     * Whether an action needs a file open: any editor action except `recent_files`
     * (a way back into a file), plus the file-scoped IDE commands.
     */
    private static function requiresOpenFile(string $scope, string $id): bool
    {
        return 'e' === $scope
            ? 'recent_files' !== $id
            : \in_array($id, self::FILE_SCOPED_IDE, true);
    }

    /**
     * The primary key binding for an action id, looking in the editor's merged
     * keybindings first (editor actions) then the IDE-level ones (ide_* actions).
     */
    private static function binding(string $id, CodeEditorWidget $editor, Keybindings $ideKeys): string
    {
        return $editor->getKeybindings()->getBindings($id)[0] ?? $ideKeys->getBindings($id)[0] ?? '';
    }
}
