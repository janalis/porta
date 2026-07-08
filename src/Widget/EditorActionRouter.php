<?php

declare(strict_types=1);

namespace Porta\Widget;

use Symfony\Component\Tui\Input\Keybindings;

/**
 * Editor-level action dispatch, split out of {@see CodeEditorWidget}: the two
 * lookup tables that map an action id to an {@see EditorCallbacks} hook (fired
 * with no arguments, or with the caret position), plus the buffer-cycle and
 * find/replace actions that carry a little extra logic. The same tables drive
 * key dispatch ({@see dispatchKey}) and menu dispatch ({@see run}) so a menu
 * item fires exactly the same logic as pressing its key.
 */
final class EditorActionRouter
{
    /** Actions that fire a no-argument callback. */
    private const array SIMPLE_ACTIONS = [
        'recent_files' => 'onRecentFiles',
        'close_buffer' => 'onCloseBuffer',
        'goto_file' => 'onGotoFile',
        'reformat' => 'onReformat',
        'file_structure' => 'onFileStructure',
        'save_all' => 'onSaveAllRequested',
        'save' => 'onSaveRequested',
        'navigate_back' => 'onNavigateBack',
    ];

    /** Actions that fire a callback with the caret (line, utf16 col). */
    private const array POSITIONAL_ACTIONS = [
        'peek_definition' => 'onPeek',
        'code_action' => 'onCodeAction',
        'quick_doc' => 'onQuickDoc',
        'find_usages' => 'onFindUsages',
        'goto_definition' => 'onDefinitionRequested',
        'goto_implementation' => 'onImplementationRequested',
        'goto_type_definition' => 'onTypeDefinitionRequested',
    ];

    /**
     * @param \Closure(): EditorCallbacks $callbacks the current (swappable) hook set
     * @param \Closure(): array{int, int} $caret     the caret as [line, utf16 col]
     */
    public function __construct(
        private readonly \Closure $callbacks,
        private readonly \Closure $caret,
    ) {}

    /** Dispatch a keypress to an editor-level action. Returns true when it consumed the key. */
    public function dispatchKey(string $keys, Keybindings $kb): bool
    {
        foreach (array_keys(self::SIMPLE_ACTIONS) as $action) {
            if ($kb->matches($keys, $action)) {
                $this->fireSimple($action);

                return true;
            }
        }

        foreach (array_keys(self::POSITIONAL_ACTIONS) as $action) {
            if ($kb->matches($keys, $action)) {
                $this->firePositional($action);

                return true;
            }
        }

        // The remaining actions carry extra logic beyond firing a single callback.
        if ($kb->matches($keys, 'next_buffer') || $kb->matches($keys, 'prev_buffer')) {
            $callbacks = ($this->callbacks)();
            if (null !== $callbacks->onBufferCycle) {
                ($callbacks->onBufferCycle)($kb->matches($keys, 'next_buffer') ? 1 : -1);
            }

            return true;
        }

        if ($kb->matches($keys, 'find') || $kb->matches($keys, 'replace')) {
            $callbacks = ($this->callbacks)();
            if (null !== $callbacks->onFindRequested) {
                ($callbacks->onFindRequested)($kb->matches($keys, 'replace'));
            }

            return true;
        }

        return false;
    }

    /**
     * Invoke an editor action by its id (the menu-bar entry point). Returns
     * true when the id named a table action; unknown ids are left to the caller.
     */
    public function run(string $action): bool
    {
        if (isset(self::SIMPLE_ACTIONS[$action])) {
            $this->fireSimple($action);

            return true;
        }
        if (isset(self::POSITIONAL_ACTIONS[$action])) {
            $this->firePositional($action);

            return true;
        }

        return false;
    }

    private function fireSimple(string $action): void
    {
        $callback = ($this->callbacks)()->{self::SIMPLE_ACTIONS[$action]};
        if (null !== $callback) {
            $callback();
        }
    }

    private function firePositional(string $action): void
    {
        $callback = ($this->callbacks)()->{self::POSITIONAL_ACTIONS[$action]};
        if (null !== $callback) {
            [$line, $col] = ($this->caret)();
            $callback($line, $col);
        }
    }
}
