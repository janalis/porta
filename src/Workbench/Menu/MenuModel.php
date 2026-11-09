<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Config\ActionCatalog;
use Porta\Config\CommandRegistry;
use Porta\I18n\Translator;
use Porta\Plugin\Runtime\PluginMenuSpec;
use Porta\Ui\KeyHints;
use Porta\Ui\ShortcutFormatter;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Builds the application-menu structure (File / Edit / Navigate / …) from the
 * existing command + action systems, so menu labels, shortcuts and handlers stay
 * a single source of truth. Each item is either an **ide** command (run via
 * {@see CommandRegistry::run()}) or an **editor** action (run via
 * {@see EditorCommandTarget::runAction()}); shortcuts are resolved from the live
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
            'i:ide_open_project',
            'i:ide_open_remote_project',
            self::SEP,
            'e:save',
            'e:save_all',
            'e:recent_files',
            'i:goto_file',
            'e:close_buffer',
            self::SEP,
            'i:ide_local_history',
            self::SEP,
            'i:ide_settings',
            'i:plugins_store',
            'i:ide_quit',
        ],
        'Edit' => [
            'e:undo',
            'e:redo',
            'i:ide_undo_refactoring',
            self::SEP,
            'e:cut',
            'e:copy',
            'e:paste',
            'e:paste_history',
            self::SEP,
            'e:duplicate_line',
            'e:delete_line',
            'e:insert_line_below',
            'e:insert_line_above',
            'e:complete_statement',
            'e:toggle_comment',
            'e:toggle_block_comment',
            'e:move_line_up',
            'e:move_line_down',
            self::SEP,
            'e:sort_lines',
            'e:join_lines',
            'e:toggle_case',
            self::SEP,
            'e:select_all',
            'i:reformat',
        ],
        'Navigate' => [
            'i:ide_search_everywhere',
            self::SEP,
            'e:goto_definition',
            'e:goto_implementation',
            'e:goto_type_definition',
            'e:find_usages',
            'e:call_hierarchy',
            'e:outgoing_calls',
            'e:goto_matching_bracket',
            self::SEP,
            'e:goto_line',
            'i:ide_goto_symbol',
            'i:ide_goto_test',
            'i:ide_goto_super',
            'e:file_structure',
            self::SEP,
            'e:rename_symbol',
            'e:navigate_back',
        ],
        'Code' => [
            'e:trigger_completion',
            'e:code_action',
            'e:apply_quick_fix',
            'e:refactor_this',
            'e:inline_refactoring',
            'e:organize_imports',
            self::SEP,
            'e:expand_selection',
            'e:shrink_selection',
            'e:surround_with',
            self::SEP,
            'e:toggle_fold',
            // Both directions: the pair is bound and labelled alike, and a menu
            // offering only one of them reads as the other not existing.
            'e:add_caret_above',
            'e:add_caret_below',
            'e:add_next_occurrence',
        ],
        'Run' => [
            'i:ide_run',
            'i:ide_run_last',
            self::SEP,
            'i:ide_debug',
            'i:debug_toggle_breakpoint',
            'i:debug_edit_breakpoint',
            'i:debug_add_watch',
            'i:debug_step_over',
            'i:debug_step_into',
            'i:debug_step_out',
            'i:debug_continue',
            'i:debug_pause',
            'i:debug_run_to_cursor',
            'i:debug_stop',
            'i:debug_restart',
            self::SEP,
            'i:ide_terminal',
            'i:ide_terminal_new',
        ],
        'View' => [
            'i:ide_problems',
            'i:next_problem',
            'i:prev_problem',
            'i:next_member',
            'i:prev_member',
            'i:next_change',
            'i:prev_change',
            'i:toggle_bookmark',
            'i:ide_bookmarks',
            'i:ide_recent_locations',
            'i:toggle_soft_wrap',
            'i:toggle_code_lenses',
            self::SEP,
            'i:preview_editor',
            'i:preview_split',
            'i:preview_only',
            self::SEP,
            'i:ide_structure',
            'i:ide_todo',
            'i:ide_project_tree',
            'i:ide_toggle_focus',
            'i:tree_select_opened_file',
            self::SEP,
            'i:cycle_diagnostic_messages',
            'i:cycle_inlay_hints',
            'i:cycle_line_numbers',
            'i:toggle_auto_pairs',
        ],
        'Help' => [
            'i:ide_welcome',
            'i:ide_help',
            self::SEP,
            'i:ide_command_palette',
            'e:quick_doc',
            'e:show_error_description',
        ],
    ];

    /**
     * @param (\Closure(string, string): void)|null $beforeRun called with an item's scope and id before it runs (the open view's gate)
     * @param list<PluginMenuSpec>              $pluginSpecs runtime plugin entries — a Plugins menu between Code and Run when non-empty
     * @param list<MenuContribution>            $contributions top-level menus core does not own; each is placed after its `after` title
     *
     * @return list<Menu>
     */
    public static function build(
        CommandRegistry $commands,
        EditorCommandTarget $editor,
        Keybindings $ideKeys,
        Translator $translator,
        ?\Closure $beforeRun = null,
        array $pluginSpecs = [],
        array $contributions = [],
    ): array {
        $menus = [];
        foreach (self::DEFS as $title => $specs) {
            $menus[] = new Menu($translator->trans($title), self::items($specs, $commands, $editor, $ideKeys, $translator, $beforeRun));
            if ('Code' === $title && [] !== $pluginSpecs) {
                $menus[] = new Menu(
                    $translator->trans('Plugins'),
                    self::items(self::groupByPlugin($pluginSpecs), $commands, $editor, $ideKeys, $translator, $beforeRun),
                );
            }
            foreach ($contributions as $contribution) {
                if ($title !== $contribution->after || (null !== $contribution->visible && !($contribution->visible)())) {
                    continue;
                }
                $menus[] = new Menu(
                    $translator->trans($contribution->title),
                    self::items($contribution->items, $commands, $editor, $ideKeys, $translator, $beforeRun),
                );
            }
        }

        return $menus;
    }

    /**
     * Interleave a separator wherever the owning plugin changes between two
     * consecutive specs, so each plugin's entries read as one group. No leading
     * or trailing separators by construction; a single plugin gets none.
     *
     * @param list<PluginMenuSpec> $pluginSpecs
     *
     * @return list<string|PluginMenuSpec>
     */
    private static function groupByPlugin(array $pluginSpecs): array
    {
        $grouped = [];
        $previous = null;
        foreach ($pluginSpecs as $entry) {
            if (null !== $previous && $previous !== $entry->plugin) {
                $grouped[] = self::SEP;
            }
            $grouped[] = $entry;
            $previous = $entry->plugin;
        }

        return $grouped;
    }

    /**
     * Build a list of menu items from `scope:id` specs (or {@see SEP}). Shared by
     * the menu bar and the right-click context menus so labels, shortcuts and
     * run handlers stay a single source of truth. Plugin entries arrive as
     * {@see PluginMenuSpec}s carrying an optional enabled-closure; core specs
     * stay plain strings.
     *
     * A spec list writes its separators assuming every item around them
     * survives, and one kind does not: an ADOPTABLE id whose bundled plugin is
     * disabled is skipped below. `ide_local_history` sits between two dividers
     * in the File menu, so skipping it would render them adjacent — hence a
     * separator that follows a separator, or trails the list, is dropped.
     *
     * @param list<string|PluginMenuSpec>          $specs
     * @param (\Closure(string, string): void)|null $beforeRun called with an item's scope and id before it runs
     *
     * @return list<MenuItem>
     */
    public static function items(
        array $specs,
        CommandRegistry $commands,
        EditorCommandTarget $editor,
        Keybindings $ideKeys,
        Translator $translator,
        ?\Closure $beforeRun = null,
    ): array {
        // Once per menu, not per item: the editor's map is merged and
        // re-validated on every call, and boot builds every menu three times.
        $editorKeys = $editor->getKeybindings();
        $items = [];
        foreach ($specs as $entry) {
            $spec = $entry instanceof PluginMenuSpec ? $entry->spec : $entry;
            $when = $entry instanceof PluginMenuSpec ? $entry->when : null;
            // An ADOPTABLE id is the one kind of spec nothing may answer to:
            // core names the verb but a bundled plugin implements it, so with
            // that plugin disabled the item would render, carry the catalog's
            // shortcut and do nothing. Every other 'i:' id is core's own and
            // its provider always registers it.
            if (self::SEP !== $spec && \str_starts_with($spec, 'i:') && ActionCatalog::isAdoptable(\substr($spec, offset: 2)) && !$commands->has(\substr($spec, offset: 2))) {
                continue;
            }
            if (self::SEP === $spec && ([] === $items || $items[\count($items) - 1]->separator)) {
                continue; // see below: a skipped item must not leave its dividers doubled
            }
            $items[] = self::SEP === $spec
                ? MenuItem::separator()
                : self::item($spec, $commands, $editor, $editorKeys, $ideKeys, $translator, $beforeRun, $when);
        }
        while ([] !== $items && $items[\count($items) - 1]->separator) {
            \array_pop($items);
        }

        return $items;
    }

    /**
     * IDE commands that operate on the current file (so they are disabled when no
     * file is open, alongside every editor action except `recent_files`).
     */
    private const array FILE_SCOPED_IDE = ['reformat', 'ide_local_history', 'git_stage', 'git_blame'];

    /**
     * @param (\Closure(string, string): void)|null $beforeRun  called with the item's scope and id before it runs
     * @param (\Closure(): bool)|null               $pluginWhen a plugin item's own enabled gate
     */
    private static function item(
        string $spec,
        CommandRegistry $commands,
        EditorCommandTarget $editor,
        Keybindings $editorKeys,
        Keybindings $ideKeys,
        Translator $translator,
        ?\Closure $beforeRun,
        ?\Closure $pluginWhen = null,
    ): MenuItem {
        [$scope, $id] = \explode(':', $spec, limit: 2);
        $label = $translator->trans(
            'i' === $scope
                ? $commands->label($id) ?? ActionCatalog::label($id)
                : ActionCatalog::label($id),
        );
        $shortcut = KeyHints::key($id, $editorKeys, $ideKeys);
        $run = static function () use ($scope, $id, $commands, $editor, $beforeRun): void {
            if (null !== $beforeRun) {
                $beforeRun($scope, $id);
            }
            'i' === $scope ? $commands->run($id) : $editor->runAction($id);
        };

        $isEnabled = self::requiresOpenFile($scope, $id) ? static fn (): bool => null !== $editor->getFilePath() : null;
        if ('i' === $scope) {
            // A core command's OWN `when` gate, which this item used to ignore:
            // only a plugin item's was ever composed in, so the Run menu offered
            // seven debugger verbs fully enabled with no session behind them.
            // `isEnabled()` answers true for a registered command carrying no
            // gate, so this is a no-op for all but the thirteen that have one.
            //
            // Unregistered ids keep today's answer rather than greying: an
            // adoptable id whose plugin is off is already dropped upstream, and
            // nothing else in the menus depends on a registry being populated.
            $base = $isEnabled;
            $isEnabled = static fn (): bool => (null === $base || $base()) && (!$commands->has($id) || $commands->isEnabled($id));
        }
        if (null !== $pluginWhen) {
            $base = $isEnabled;
            $isEnabled = static fn (): bool => (null === $base || $base()) && $pluginWhen();
        }

        return new MenuItem($label, $shortcut, $run, isEnabled: $isEnabled, isChecked: 'i' === $scope ? $commands->checkedState($id) : null);
    }

    /**
     * Whether an action needs a file open: any editor action except `recent_files`
     * (a way back into a file), plus the file-scoped IDE commands.
     */
    private static function requiresOpenFile(string $scope, string $id): bool
    {
        return 'e' === $scope
            ? 'recent_files' !== $id
            : \in_array($id, self::FILE_SCOPED_IDE, strict: true);
    }
}
