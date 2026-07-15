<?php

declare(strict_types=1);

namespace Porta\Widget;

use Symfony\Component\Tui\Input\Keybindings;

/**
 * Editor-level action dispatch, split out of {@see CodeEditorWidget}: the two
 * action tables that route an action id to its {@see EditorCallbacks} handler
 * method (fired with no arguments, or with the caret position), plus the
 * buffer-cycle and find/replace actions that carry a little extra logic. The
 * same tables drive key dispatch ({@see dispatchKey}) and menu dispatch
 * ({@see run}) so a menu item fires exactly the same logic as pressing its key.
 */
final class EditorActionRouter
{
    /** Actions that fire a no-argument handler method. */
    private const array SIMPLE_ACTIONS = [
        'recent_files',
        'close_buffer',
        'goto_file',
        'reformat',
        'file_structure',
        'save_all',
        'save',
        'navigate_back',
    ];

    /** Actions that fire a handler method with the caret (line, utf16 col). */
    private const array POSITIONAL_ACTIONS = [
        'peek_definition',
        'code_action',
        'quick_doc',
        'find_usages',
        'call_hierarchy',
        'goto_definition',
        'goto_implementation',
        'goto_type_definition',
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
        foreach (self::SIMPLE_ACTIONS as $action) {
            if ($kb->matches($keys, $action)) {
                $this->fireSimple($action);

                return true;
            }
        }

        foreach (self::POSITIONAL_ACTIONS as $action) {
            if ($kb->matches($keys, $action)) {
                $this->firePositional($action);

                return true;
            }
        }

        // The remaining actions carry extra logic beyond firing a single hook.
        if ($kb->matches($keys, 'next_buffer') || $kb->matches($keys, 'prev_buffer')) {
            ($this->callbacks)()->buffer?->cycleBuffer($kb->matches($keys, 'next_buffer') ? 1 : -1);

            return true;
        }

        if ($kb->matches($keys, 'find') || $kb->matches($keys, 'replace')) {
            ($this->callbacks)()->file?->openFind($kb->matches($keys, 'replace'));

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
        if (\in_array($action, self::SIMPLE_ACTIONS, true)) {
            $this->fireSimple($action);

            return true;
        }
        if (\in_array($action, self::POSITIONAL_ACTIONS, true)) {
            $this->firePositional($action);

            return true;
        }

        return false;
    }

    private function fireSimple(string $action): void
    {
        $callbacks = ($this->callbacks)();
        // Guarded by SIMPLE_ACTIONS at both entry points, so the lookup always hits.
        $handlers = [
            'recent_files' => static fn () => $callbacks->buffer?->openRecentFiles(),
            'close_buffer' => static fn () => $callbacks->buffer?->closeBuffer(),
            'goto_file' => static fn () => $callbacks->file?->openGotoFile(),
            'reformat' => static fn () => $callbacks->refactor?->requestReformat(),
            'file_structure' => static fn () => $callbacks->navigation?->requestFileStructure(),
            'save_all' => static fn () => $callbacks->file?->saveAll(),
            'save' => static fn () => $callbacks->file?->save(),
            'navigate_back' => static fn () => $callbacks->navigation?->navigateBack(),
        ];
        ($handlers[$action] ?? static fn (): null => null)();
    }

    private function firePositional(string $action): void
    {
        $callbacks = ($this->callbacks)();
        [$line, $col] = ($this->caret)();
        // Guarded by POSITIONAL_ACTIONS at both entry points, so the lookup always hits.
        $handlers = [
            'peek_definition' => static fn () => $callbacks->navigation?->requestPeek($line, $col),
            'code_action' => static fn () => $callbacks->refactor?->requestCodeActions($line, $col),
            'quick_doc' => static fn () => $callbacks->navigation?->requestHover($line, $col),
            'find_usages' => static fn () => $callbacks->navigation?->requestReferences($line, $col),
            'call_hierarchy' => static fn () => $callbacks->navigation?->requestCallHierarchy($line, $col),
            'goto_definition' => static fn () => $callbacks->navigation?->requestDefinition($line, $col),
            'goto_implementation' => static fn () => $callbacks->navigation?->requestImplementation($line, $col),
            'goto_type_definition' => static fn () => $callbacks->navigation?->requestTypeDefinition($line, $col),
        ];
        ($handlers[$action] ?? static fn (): null => null)();
    }
}
