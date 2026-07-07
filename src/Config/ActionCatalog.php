<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * The single descriptor table for every rebindable action: its palette label
 * and, for Ide-scope actions (bound on the application rather than on a
 * widget), its default keys. {@see IdeConfig} derives the rebindable-action
 * allowlist from it, {@see self::label()} serves labels from it, and the
 * coordinator builds its default keymap from it — previously three
 * hand-synced lists.
 *
 * Widget-scope defaults stay in each widget's getDefaultKeybindings(); a test
 * asserts every catalog entry is default-bound in exactly one of the two
 * places.
 */
final class ActionCatalog
{
    /** @var array<string, array{label: string, keys?: list<string>}> */
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
        'move_statement_up' => ['label' => 'Move Statement Up'],
        'move_statement_down' => ['label' => 'Move Statement Down'],
        'expand_selection' => ['label' => 'Extend Selection'],
        'shrink_selection' => ['label' => 'Shrink Selection'],
        'surround_with' => ['label' => 'Surround With…'],
        'toggle_comment' => ['label' => 'Toggle Comment'],
        'toggle_fold' => ['label' => 'Collapse / Expand Fold'],
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
        // Move the active tab left/right (Alt+Left/Right switch tabs).
        'move_tab_left' => ['label' => 'Move Tab Left', 'keys' => ['alt+shift+left']],
        'move_tab_right' => ['label' => 'Move Tab Right', 'keys' => ['alt+shift+right']],
        'find' => ['label' => 'Find in File'],
        'replace' => ['label' => 'Replace in File'],
        'goto_file' => ['label' => 'Go to File'],
        'quick_doc' => ['label' => 'Quick Documentation'],
        'goto_line' => ['label' => 'Go to Line'],
        'rename_symbol' => ['label' => 'Rename Symbol'],
        'find_usages' => ['label' => 'Find Usages'],
        'code_action' => ['label' => 'Show Quick Fixes'],
        'file_structure' => ['label' => 'File Structure'],
        'peek_definition' => ['label' => 'Peek Definition'],
        'reformat' => ['label' => 'Reformat File'],
        'ide_find_in_project' => ['label' => 'Find in Project', 'keys' => ['ctrl+shift+f']],
        'ide_goto_symbol' => ['label' => 'Go to Symbol in Project', 'keys' => ['ctrl+alt+shift+n', 'ctrl+alt+o']],
        'ide_problems' => ['label' => 'Show Problems', 'keys' => ['alt+6']],
        'ide_command_palette' => ['label' => 'Find Action', 'keys' => ['ctrl+shift+a']],
        'ide_local_history' => ['label' => 'Show Local History', 'keys' => ['ctrl+alt+h']],
        'ide_terminal' => ['label' => 'Open Terminal', 'keys' => ['ctrl+`', 'alt+f12']],
        'ide_run' => ['label' => 'Run…', 'keys' => ['ctrl+shift+r']],
        'ide_run_last' => ['label' => 'Run Last', 'keys' => ['shift+f10']],
        'ide_git' => ['label' => 'Git: Changes', 'keys' => ['ctrl+alt+d']],
        'ide_menu' => ['label' => 'Open Menu', 'keys' => ['f10']],
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
    ];

    /** @return list<string> every rebindable action id */
    public static function knownActions(): array
    {
        return array_keys(self::ACTIONS);
    }

    /** @return array<string, list<string>> default keymap for Ide-scope actions */
    public static function ideDefaults(): array
    {
        $defaults = [];
        foreach (self::ACTIONS as $id => $spec) {
            if (isset($spec['keys'])) {
                $defaults[$id] = $spec['keys'];
            }
        }

        return $defaults;
    }

    /** @return array<string, string> action id => palette label */
    public static function labels(): array
    {
        return array_map(static fn (array $spec): string => $spec['label'], self::ACTIONS);
    }

    /** Palette label for an action id; unknown actions fall back to a title-cased slug. */
    public static function label(string $action): string
    {
        return self::ACTIONS[$action]['label'] ?? ucwords(str_replace('_', ' ', $action));
    }
}
