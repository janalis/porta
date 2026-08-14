<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Widget\EditorCallbacks;
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
        foreach ($this->simpleHandlers() as $action => $handler) {
            if (!$kb->matches($keys, $action)) {
                continue;
            }

            $handler(($this->callbacks)());

            return true;
        }

        foreach ($this->positionalHandlers() as $action => $handler) {
            if (!$kb->matches($keys, $action)) {
                continue;
            }

            [$line, $col] = ($this->caret)();
            $handler(($this->callbacks)(), $line, $col);

            return true;
        }

        // The remaining actions carry extra logic beyond firing a single hook.
        if ($kb->matches($keys, 'next_buffer') || $kb->matches($keys, 'prev_buffer')) {
            ($this->callbacks)()->buffer?->cycleBuffer($kb->matches($keys, 'next_buffer') ? 1 : -1);

            return true;
        }

        if ($kb->matches($keys, 'find') || $kb->matches($keys, 'replace')) {
            $file = ($this->callbacks)()->file;
            $kb->matches($keys, 'replace') ? $file?->openFindReplace() : $file?->openFind();

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
        $simple = $this->simpleHandlers();
        if (\array_key_exists($action, $simple)) {
            $simple[$action](($this->callbacks)());

            return true;
        }

        $positional = $this->positionalHandlers();
        if (\array_key_exists($action, $positional)) {
            [$line, $col] = ($this->caret)();
            $positional[$action](($this->callbacks)(), $line, $col);

            return true;
        }

        return false;
    }

    /**
     * The no-argument action table. The ids are the keys, written once: the
     * same map drives the membership test and the dispatch at both entry
     * points, so an id can never be routable-but-unhandled or vice versa.
     *
     * @return array<string, \Closure(EditorCallbacks): mixed>
     */
    private function simpleHandlers(): array
    {
        return [
            'recent_files' => static fn (EditorCallbacks $c) => $c->buffer?->openRecentFiles(),
            'close_buffer' => static fn (EditorCallbacks $c) => $c->buffer?->closeBuffer(),
            'goto_file' => static fn (EditorCallbacks $c) => $c->file?->openGotoFile(),
            'reformat' => static fn (EditorCallbacks $c) => $c->refactor?->requestReformat(),
            'file_structure' => static fn (EditorCallbacks $c) => $c->navigation?->requestFileStructure(),
            'save_all' => static fn (EditorCallbacks $c) => $c->file?->saveAll(),
            'save' => static fn (EditorCallbacks $c) => $c->file?->save(),
            'navigate_back' => static fn (EditorCallbacks $c) => $c->navigation?->navigateBack(),
        ];
    }

    /**
     * The caret-position action table; same single-source contract as
     * {@see simpleHandlers}. The caret is passed in by the caller so it is
     * only read when an action actually fires.
     *
     * @return array<string, \Closure(EditorCallbacks, int, int): mixed>
     */
    private function positionalHandlers(): array
    {
        return [
            'peek_definition' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestPeek($line, $col),
            'code_action' => static fn (EditorCallbacks $c, int $line, int $col) => $c->refactor?->requestCodeActions($line, $col),
            'quick_doc' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestHover($line, $col),
            'find_usages' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestReferences($line, $col),
            'call_hierarchy' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestCallHierarchy($line, $col),
            'goto_definition' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestDefinition($line, $col),
            'goto_implementation' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestImplementation($line, $col),
            'goto_type_definition' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestTypeDefinition($line, $col),
        ];
    }
}
