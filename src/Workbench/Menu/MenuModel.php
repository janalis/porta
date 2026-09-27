<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Config\ActionCatalog;
use Porta\Config\CommandRegistry;
use Porta\I18n\Translator;
use Porta\Plugin\Runtime\PluginMenuSpec;
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
        ],
    ];

    /**
     * @param (\Closure(): ?string)|null $activeView  while a view is active, only its {@see VIEW_SAFE} items stay enabled
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
        ?\Closure $activeView = null,
        array $pluginSpecs = [],
        array $contributions = [],
    ): array {
        $viewSafe = self::VIEW_SAFE;
        foreach ($contributions as $contribution) {
            if ('' === $contribution->viewId) {
                continue;
            }

            $viewSafe[$contribution->viewId] = [...($viewSafe[$contribution->viewId] ?? []), ...$contribution->viewSafe];
        }

        $menus = [];
        foreach (self::DEFS as $title => $specs) {
            $menus[] = new Menu($translator->trans($title), self::items($specs, $commands, $editor, $ideKeys, $translator, $activeView, $viewSafe));
            if ('Code' === $title && [] !== $pluginSpecs) {
                $menus[] = new Menu(
                    $translator->trans('Plugins'),
                    self::items(self::groupByPlugin($pluginSpecs), $commands, $editor, $ideKeys, $translator, $activeView, $viewSafe),
                );
            }
            foreach ($contributions as $contribution) {
                if ($title !== $contribution->after || null !== $contribution->visible && !($contribution->visible)()) {
                    continue;
                }
                $menus[] = new Menu(
                    $translator->trans($contribution->title),
                    self::items($contribution->items, $commands, $editor, $ideKeys, $translator, $activeView, $viewSafe),
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
     * @param list<string|PluginMenuSpec>   $specs
     * @param (\Closure(): ?string)|null    $activeView
     * @param array<string, list<string>>   $viewSafe the ids each open view leaves enabled
     *
     * @return list<MenuItem>
     */
    public static function items(
        array $specs,
        CommandRegistry $commands,
        EditorCommandTarget $editor,
        Keybindings $ideKeys,
        Translator $translator,
        ?\Closure $activeView = null,
        array $viewSafe = self::VIEW_SAFE,
    ): array {
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
                : self::item($spec, $commands, $editor, $ideKeys, $translator, $activeView, $viewSafe, $when);
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
     * The only commands that stay enabled while a full view is active — one
     * list per view, keyed by the id the view supplies. Everything else
     * targets the detached editor/tree and would act invisibly. Git keeps its
     * own verbs live; Settings only the ways out; `ide_welcome` is in every
     * list, not just Welcome's: a user who followed a Welcome step into
     * Settings must still be able to get back.
     */
    private const array VIEW_SAFE = [
        'settings' => ['ide_quit', 'ide_settings', 'ide_command_palette', 'ide_help', 'ide_welcome'],
        'welcome' => ['ide_quit', 'ide_welcome', 'ide_command_palette', 'ide_help'],
    ];

    /**
     * @param (\Closure(): ?string)|null      $activeView
     * @param array<string, list<string>>     $viewSafe   the ids each open view leaves enabled
     * @param (\Closure(): bool)|null         $pluginWhen a plugin item's own enabled gate, ANDed with the view gating
     */
    private static function item(
        string $spec,
        CommandRegistry $commands,
        EditorCommandTarget $editor,
        Keybindings $ideKeys,
        Translator $translator,
        ?\Closure $activeView,
        array $viewSafe,
        ?\Closure $pluginWhen = null,
    ): MenuItem {
        [$scope, $id] = \explode(':', $spec, limit: 2);
        $label = $translator->trans(
            'i' === $scope
                ? $commands->label($id) ?? ActionCatalog::label($id)
                : ActionCatalog::label($id),
        );
        $shortcut = ShortcutFormatter::format(self::binding($id, $editor, $ideKeys));
        $run = 'i' === $scope
            ? static fn () => $commands->run($id)
            : static fn () => $editor->runAction($id);

        $requiresFile = self::requiresOpenFile($scope, $id);
        $isEnabled = null === $activeView && !$requiresFile
            ? null
            : static fn (): bool => match (true) {
                null !== $activeView && null !== ($activeViewId = $activeView()) => 'i' === $scope && \in_array($id, $viewSafe[$activeViewId] ?? [], strict: true),
                default => !$requiresFile || null !== $editor->getFilePath(),
            };
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

    /**
     * The primary key binding for an action id, looking in the editor's merged
     * keybindings first (editor actions) then the IDE-level ones (ide_* actions).
     */
    private static function binding(string $id, EditorCommandTarget $editor, Keybindings $ideKeys): string
    {
        return $editor->getKeybindings()->getBindings($id)[0] ?? $ideKeys->getBindings($id)[0] ?? '';
    }
}
