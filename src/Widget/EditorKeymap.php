<?php

declare(strict_types=1);

namespace Porta\Widget;

use Symfony\Component\Tui\Input\Key;

/**
 * The code editor's default keybinding table, split out of
 * {@see CodeEditorWidget} so the widget is not padded by ~90 lines of pure
 * data. It is the PhpStorm-first keymap; deliberate deviations (terminal
 * constraints): quit stays Ctrl+Q on the app level (PhpStorm quick-doc moves to
 * Shift+F1 — plain F1 opens the IDE's documentation browser), Ctrl+W stays
 * delete-word, Ctrl+K stays delete-to-line-end.
 */
final class EditorKeymap
{
    /** @return array<string, string[]> */
    public static function defaults(): array
    {
        return [
            'cursor_up' => [Key::UP],
            'cursor_down' => [Key::DOWN],
            'cursor_left' => [Key::LEFT],
            'cursor_right' => [Key::RIGHT],
            'cursor_word_left' => ['alt+left', 'alt+b'],
            'cursor_word_right' => ['alt+right', 'alt+f'],
            'cursor_line_start' => [Key::HOME],
            'cursor_line_end' => [Key::END],
            'document_start' => ['ctrl+home', 'alt+<'],
            'document_end' => ['ctrl+end', 'alt+>'],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],

            'select_up' => ['shift+up'],
            'select_down' => ['shift+down'],
            'select_left' => ['shift+left'],
            'select_right' => ['shift+right'],
            'select_word_left' => ['alt+shift+left'],
            'select_word_right' => ['alt+shift+right'],
            'select_line_start' => ['shift+home'],
            'select_line_end' => ['shift+end'],
            'select_all' => ['ctrl+a'],

            'copy' => ['ctrl+c'],
            'cut' => ['ctrl+x'],
            'paste' => ['ctrl+v'],

            'delete_char_backward' => [Key::BACKSPACE, 'shift+backspace'],
            'delete_char_forward' => [Key::DELETE],
            'delete_word_backward' => ['alt+backspace'],
            'delete_to_line_end' => ['ctrl+k'],
            'delete_line' => ['ctrl+y', 'ctrl+shift+k'],
            'duplicate_line' => ['ctrl+d'],
            'move_line_up' => ['alt+shift+up'],
            'move_line_down' => ['alt+shift+down'],
            'move_statement_up' => ['ctrl+shift+up'],
            'move_statement_down' => ['ctrl+shift+down'],
            'sort_lines' => ['ctrl+shift+s'],
            'join_lines' => ['ctrl+shift+j'],
            'toggle_case' => ['ctrl+shift+u'],
            'expand_selection' => ['ctrl+w'],
            'shrink_selection' => ['ctrl+shift+w'],
            'surround_with' => ['ctrl+alt+t'],
            'next_conflict' => [Key::F7],
            'prev_conflict' => ['shift+f7'],
            'resolve_conflict' => ['alt+m'],
            'toggle_comment' => ['ctrl+/', 'ctrl+-'],
            'toggle_block_comment' => ['ctrl+shift+/'],
            'toggle_fold' => ['ctrl+.'],
            'goto_matching_bracket' => ['ctrl+shift+m'],

            // PhpStorm's Shift+Enter is Start New Line — a line BELOW, not a
            // second way to split this one. Plain Enter still splits, so
            // nothing is lost by giving the chord its real meaning.
            'new_line' => [Key::ENTER],
            'paste_history' => ['ctrl+shift+v'],
            'insert_line_below' => ['shift+enter'],
            'insert_line_above' => ['ctrl+alt+enter'],
            'complete_statement' => ['ctrl+shift+enter'],
            'indent' => [Key::TAB],
            'prev_tab_stop' => ['shift+tab'],
            'accept_completion' => [Key::ENTER],
            'trigger_completion' => ['ctrl+space'],
            // PhpStorm's Ctrl+P, which Go to File already owns here; Ctrl+P
            // stays where it is and the mnemonic moves rather than the verb.
            'parameter_info' => ['ctrl+shift+p'],
            'select_cancel' => [Key::ESCAPE],

            'save' => ['ctrl+s'],
            'save_all' => ['ctrl+alt+s'],
            'undo' => ['ctrl+z'],
            'redo' => ['ctrl+shift+z'],
            'goto_definition' => ['ctrl+b', Key::F12],
            'goto_implementation' => ['ctrl+alt+b'],
            'goto_type_definition' => ['ctrl+shift+b'],
            'navigate_back' => ['ctrl+alt+left'],

            'add_caret_above' => ['ctrl+alt+up'],
            'add_caret_below' => ['ctrl+alt+down'],
            'add_next_occurrence' => ['alt+j'],
            'select_all_occurrences' => ['ctrl+alt+shift+j'],
            'toggle_column_mode' => ['alt+c'],

            'next_buffer' => ['ctrl+right'],
            'prev_buffer' => ['ctrl+left'],
            'recent_files' => ['ctrl+e'],
            'close_buffer' => ['ctrl+f4', 'alt+w'],

            'find' => ['ctrl+f'],
            'replace' => ['ctrl+r'],

            'quick_doc' => ['shift+f1'], // plain F1 is the IDE-level docs browser
            'code_action' => ['alt+enter'],
            // PhpStorm's: the problem's first fix, no list. Only a terminal
            // that reports modifiers on Enter (kitty's protocol, xterm's
            // modifyOtherKeys) can send it; elsewhere it arrives as Alt+Enter,
            // which opens the list the fix is the first row of.
            'apply_quick_fix' => ['alt+shift+enter'],
            // PhpStorm's Error Description. macOS keeps Ctrl+F1 for keyboard
            // access and XFCE for its workspaces, so Alt+Q stands by.
            'show_error_description' => ['ctrl+f1', 'alt+q'],
            // PhpStorm's Refactor This. Ctrl+Alt+M (extract method) is the
            // phpantom plugin's fix command and Ctrl+F6 is focus_previous,
            // so extraction is reached through this list rather than by
            // taking a binding that is already someone's.
            'refactor_this' => ['ctrl+alt+shift+t'],
            'inline_refactoring' => ['ctrl+alt+n'],
            'organize_imports' => ['ctrl+alt+o'],
            'file_structure' => ['ctrl+f12'],
            'peek_definition' => ['ctrl+shift+i'],
            'reformat' => ['ctrl+alt+l'],
            'goto_line' => ['ctrl+g'],
            'rename_symbol' => ['shift+f6'],
            'find_usages' => ['alt+f7'],
            'call_hierarchy' => ['ctrl+shift+h'],
            'outgoing_calls' => ['ctrl+alt+shift+h'],
        ];
    }
}
