<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * The descriptor table for CORE's rebindable actions: each one's palette label
 * and, for Ide-scope actions (bound on the application rather than on a
 * widget), its default keys. {@see self::label()} serves labels from it and the
 * coordinator builds its default keymap from it — previously two hand-synced
 * lists.
 *
 * It is not the whole set any more: a plugin declares its pane's actions with
 * `PluginApi::registerActions()`, and {@see ActionRegistry} is the composition
 * the keymap page lists and {@see KeybindingsFactory} validates against. Ask
 * the REGISTRY "can the user rebind this"; this constant answers "is this
 * core's".
 *
 * Widget-scope defaults stay in each widget's getDefaultKeybindings(); a test
 * asserts every catalog entry is default-bound in exactly one of the two
 * places.
 */
final class ActionCatalog
{
    /**
     * `adoptable` marks a verb core NAMES but no longer IMPLEMENTS: a bundled
     * plugin registers the handler under this same bare id through
     * `PluginApi::adoptCommand()`, so the keymap page, the `.porta.json` binding
     * and the menu position all survive the implementation moving out. Core
     * must have VACATED the id — two registrations would both dispatch — which
     * is why the flag lands in the same commit as the move.
     *
     * @var array<string, array{label: string, keys?: list<string>, adoptable?: true}>
     */
    public const array ACTIONS = [
        // Editor: movement
        'cursor_up' => ['label' => 'Move Caret Up'],
        'cursor_down' => ['label' => 'Move Caret Down'],
        'cursor_left' => ['label' => 'Move Caret Left'],
        'cursor_right' => ['label' => 'Move Caret Right'],
        'cursor_word_left' => ['label' => 'Move Caret to Previous Word'],
        'cursor_word_right' => ['label' => 'Move Caret to Next Word'],
        'cursor_line_start' => ['label' => 'Move Caret to Line Start'],
        'cursor_line_end' => ['label' => 'Move Caret to Line End'],
        'document_start' => ['label' => 'Move Caret to Text Start'],
        'document_end' => ['label' => 'Move Caret to Text End'],
        'page_up' => ['label' => 'Page Up'],
        'page_down' => ['label' => 'Page Down'],
        // Editor: selection
        'select_up' => ['label' => 'Extend Selection Up'],
        'select_down' => ['label' => 'Extend Selection Down'],
        'select_left' => ['label' => 'Extend Selection Left'],
        'select_right' => ['label' => 'Extend Selection Right'],
        'select_word_left' => ['label' => 'Extend Selection to Previous Word'],
        'select_word_right' => ['label' => 'Extend Selection to Next Word'],
        'select_line_start' => ['label' => 'Extend Selection to Line Start'],
        'select_line_end' => ['label' => 'Extend Selection to Line End'],
        'select_all' => ['label' => 'Select All'],
        // Editor: editing
        'delete_char_backward' => ['label' => 'Backspace'],
        'delete_char_forward' => ['label' => 'Delete Character'],
        'delete_word_backward' => ['label' => 'Delete Word'],
        'delete_to_line_end' => ['label' => 'Delete to Line End'],
        'delete_line' => ['label' => 'Delete Line'],
        'duplicate_line' => ['label' => 'Duplicate Line or Selection'],
        'move_line_up' => ['label' => 'Move Line Up'],
        'move_line_down' => ['label' => 'Move Line Down'],
        'sort_lines' => ['label' => 'Sort Lines'],
        'join_lines' => ['label' => 'Join Lines'],
        'toggle_case' => ['label' => 'Toggle Case'],
        'move_statement_up' => ['label' => 'Move Statement Up'],
        'move_statement_down' => ['label' => 'Move Statement Down'],
        'expand_selection' => ['label' => 'Extend Selection'],
        'shrink_selection' => ['label' => 'Shrink Selection'],
        'surround_with' => ['label' => 'Surround With…'],
        'toggle_comment' => ['label' => 'Toggle Comment'],
        'toggle_fold' => ['label' => 'Collapse / Expand Fold'],
        'goto_matching_bracket' => ['label' => 'Go to Matching Bracket'],
        'next_conflict' => ['label' => 'Next Conflict'],
        'prev_conflict' => ['label' => 'Previous Conflict'],
        'resolve_conflict' => ['label' => 'Resolve Conflict'],
        'new_line' => ['label' => 'New Line'],
        'indent' => ['label' => 'Indent'],
        'prev_tab_stop' => ['label' => 'Previous Snippet Tab Stop'],
        'undo' => ['label' => 'Undo'],
        'redo' => ['label' => 'Redo'],
        // Editor: multi-cursor
        'add_caret_above' => ['label' => 'Add Caret Above'],
        'add_caret_below' => ['label' => 'Add Caret Below'],
        'add_next_occurrence' => ['label' => 'Add Caret at Next Occurrence'],
        'select_all_occurrences' => ['label' => 'Select All Occurrences'],
        // Editor: clipboard
        'copy' => ['label' => 'Copy'],
        'cut' => ['label' => 'Cut'],
        'paste' => ['label' => 'Paste'],
        // Editor: IDE features
        'save' => ['label' => 'Save File'],
        'save_all' => ['label' => 'Save All'],
        // IDE scope, not the editor keymap: the point of this one is to be
        // available while a VIEWER holds the pane and the editor is detached.
        'open_as_text' => ['label' => 'Open as Text', 'keys' => ['ctrl+alt+x']],
        'trigger_completion' => ['label' => 'Code Completion'],
        'accept_completion' => ['label' => 'Accept Completion'],
        'select_cancel' => ['label' => 'Cancel'],
        'goto_definition' => ['label' => 'Go to Declaration or Usages'],
        'goto_implementation' => ['label' => 'Go to Implementation'],
        'goto_type_definition' => ['label' => 'Go to Type Definition'],
        'navigate_back' => ['label' => 'Navigate Back'],
        'next_buffer' => ['label' => 'Next Tab'],
        'prev_buffer' => ['label' => 'Previous Tab'],
        'recent_files' => ['label' => 'Recent Files'],
        'close_buffer' => ['label' => 'Close Tab'],
        // Move the active tab left/right (Ctrl+Left/Right switch tabs).
        'move_tab_left' => ['label' => 'Move Tab Left', 'keys' => ['ctrl+shift+left']],
        'move_tab_right' => ['label' => 'Move Tab Right', 'keys' => ['ctrl+shift+right']],
        'find' => ['label' => 'Find in File'],
        'replace' => ['label' => 'Replace in File'],
        'goto_file' => ['label' => 'Go to File'],
        'quick_doc' => ['label' => 'Quick Documentation'],
        'goto_line' => ['label' => 'Go to Line'],
        'rename_symbol' => ['label' => 'Rename Symbol'],
        'find_usages' => ['label' => 'Find Usages'],
        'call_hierarchy' => ['label' => 'Call Hierarchy'],
        'code_action' => ['label' => 'Show Quick Fixes'],
        'file_structure' => ['label' => 'File Structure'],
        'peek_definition' => ['label' => 'Peek Definition'],
        'reformat' => ['label' => 'Reformat File'],
        'cycle_line_numbers' => ['label' => 'Cycle Line Numbers', 'keys' => ['ctrl+shift+l']],
        'ide_find_in_project' => ['label' => 'Find in Project', 'keys' => ['ctrl+shift+f']],
        'ide_goto_symbol' => ['label' => 'Go to Symbol in Project', 'keys' => ['ctrl+alt+shift+n', 'ctrl+alt+o']],
        'ide_goto_test' => ['label' => 'Go to Test', 'keys' => ['ctrl+shift+t']],
        'ide_problems' => ['label' => 'Show Problems', 'keys' => ['alt+6']],
        'ide_todo' => ['label' => 'Show TODOs', 'keys' => ['alt+8'], 'adoptable' => true], // implemented by the bundled todo plugin
        'ide_command_palette' => ['label' => 'Find Action', 'keys' => ['ctrl+shift+a']],
        'ide_search_everywhere' => ['label' => 'Search Everywhere', 'keys' => ['ctrl+shift+e']],
        'ide_local_history' => ['label' => 'Show Local History', 'keys' => ['ctrl+alt+h'], 'adoptable' => true], // implemented by the bundled local-history plugin
        'ide_terminal' => ['label' => 'Open Terminal', 'keys' => ['ctrl+`', 'alt+f12']],
        'ide_terminal_new' => ['label' => 'New Terminal Session', 'keys' => ['ctrl+shift+`', 'alt+f11']],
        'dock_next_panel' => ['label' => 'Next Dock Panel', 'keys' => ['alt+page_down']],
        'dock_prev_panel' => ['label' => 'Previous Dock Panel', 'keys' => ['alt+page_up']],
        'terminal_next_session' => ['label' => 'Next Terminal'],
        'terminal_prev_session' => ['label' => 'Previous Terminal'],
        'terminal_close_session' => ['label' => 'Close Terminal Session'],
        'ide_run' => ['label' => 'Run…', 'keys' => ['ctrl+shift+r']],
        'ide_run_last' => ['label' => 'Run Last', 'keys' => ['shift+f10']],
        'ide_debug' => ['label' => 'Debug Current File', 'keys' => ['shift+f9']],
        'debug_toggle_breakpoint' => ['label' => 'Toggle Breakpoint', 'keys' => ['ctrl+f8']],
        'debug_edit_breakpoint' => ['label' => 'Edit Breakpoint Condition', 'keys' => ['ctrl+shift+f8']],
        'debug_add_watch' => ['label' => 'Add Watch Expression', 'keys' => ['ctrl+shift+f9']],
        'debug_step_over' => ['label' => 'Step Over', 'keys' => ['f8']],
        'debug_step_into' => ['label' => 'Step Into', 'keys' => ['f7']],
        'debug_step_out' => ['label' => 'Step Out', 'keys' => ['shift+f8']],
        'debug_continue' => ['label' => 'Resume Program', 'keys' => ['f9']],
        'debug_run_to_cursor' => ['label' => 'Run to Cursor', 'keys' => ['alt+f9']],
        'debug_stop' => ['label' => 'Stop Debugger', 'keys' => ['ctrl+f2']],
        'debug_restart' => ['label' => 'Restart Debugger', 'keys' => ['ctrl+shift+f5']],
        'ide_git' => ['label' => 'Git: Changes', 'keys' => ['ctrl+alt+d']],
        'git_push' => ['label' => 'Git: Push', 'keys' => ['ctrl+shift+k']],
        'ide_settings' => ['label' => 'Settings…', 'keys' => ['ctrl+,']],
        'ide_help' => ['label' => 'Documentation', 'keys' => ['f1']],
        'ide_menu' => ['label' => 'Open Menu', 'keys' => ['f10']],
        // Help viewer (bound by HelpViewerWidget)
        'help_next_link' => ['label' => 'Help: Next Link'],
        'help_prev_link' => ['label' => 'Help: Previous Link'],
        'help_follow_link' => ['label' => 'Help: Follow Link'],
        'help_back' => ['label' => 'Help: Back'],
        'help_scroll_up' => ['label' => 'Help: Scroll Up'],
        'help_scroll_down' => ['label' => 'Help: Scroll Down'],
        'help_page_up' => ['label' => 'Help: Page Up'],
        'help_page_down' => ['label' => 'Help: Page Down'],
        'help_home' => ['label' => 'Help: Go to Top'],
        'help_end' => ['label' => 'Help: Go to Bottom'],
        // The image and graph viewers' twenty actions are NOT here: they belong
        // to the two viewer PLUGINS, which declare them through
        // PluginApi::registerActions() and get composed onto this table by
        // Config/ActionRegistry. Their ids stay un-namespaced ("image_zoom_in",
        // not "image-viewer:zoom-in") on purpose — they are user-visible
        // .porta.json keymap keys, and renaming them would silently break every
        // existing rebind.
        // Tree
        'move_up' => ['label' => 'Tree: Select Previous'],
        'move_down' => ['label' => 'Tree: Select Next'],
        'collapse' => ['label' => 'Tree: Collapse'],
        'expand' => ['label' => 'Tree: Expand'],
        'open' => ['label' => 'Tree: Open / Toggle'],
        'reload' => ['label' => 'Tree: Reload'],
        // Application
        'ide_quit' => ['label' => 'Quit', 'keys' => ['ctrl+q']],
        'ide_toggle_focus' => ['label' => 'Switch Tree / Editor Focus', 'keys' => ['ctrl+t', 'f2']],
        // Shift+F6 is rename (PhpStorm); cycle focus with F6 / Ctrl+F6.
        'focus_next' => ['label' => 'Focus Next Pane', 'keys' => ['f6']],
        'focus_previous' => ['label' => 'Focus Previous Pane', 'keys' => ['ctrl+f6']],
        // Shift+F10 is run-last (PhpStorm) and the Menu key never reaches the
        // parser, so the context menu opens on Ctrl+F10 (legacy-encodable).
        'show_context_menu' => ['label' => 'Show Context Menu', 'keys' => ['ctrl+f10']],
        // Every modifier+arrow chord is taken and punctuation chords need the
        // kitty protocol; Shift+F11/F12 deliver everywhere and read as −/+.
        'resize_pane_shrink' => ['label' => 'Shrink Pane', 'keys' => ['shift+f11']],
        'resize_pane_grow' => ['label' => 'Grow Pane', 'keys' => ['shift+f12']],
    ];

    /** Whether a bundled plugin may register the handler for $id (core has vacated it). */
    public static function isAdoptable(string $id): bool
    {
        return self::ACTIONS[$id]['adoptable'] ?? false;
    }

    /** @return array<string, list<string>> default keymap for Ide-scope actions */
    public static function ideDefaults(): array
    {
        $defaults = [];
        foreach (self::ACTIONS as $id => $spec) {
            if (($spec['keys'] ?? null) === null) {
                continue;
            }

            $defaults[$id] = $spec['keys'];
        }

        return $defaults;
    }

    /** Palette label for an action id; unknown actions fall back to a title-cased slug. */
    public static function label(string $action): string
    {
        return self::ACTIONS[$action]['label'] ?? ucwords(str_replace('_', replace: ' ', subject: $action));
    }
}
