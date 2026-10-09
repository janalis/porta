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
final readonly class EditorActionRouter
{
    /**
     * @param \Closure(): EditorCallbacks $callbacks the current (swappable) hook set
     * @param \Closure(): array{int, int} $caret     the caret as [line, utf16 col]
     */
    public function __construct(
        private \Closure $callbacks,
        private \Closure $caret,
    ) {}

    /** Dispatch a keypress to an editor-level action. Returns true when it consumed the key. */
    public function dispatchKey(string $keys, Keybindings $kb): bool
    {
        // The two tables in order, the first bound action firing as {@see run} fires it.
        $action = \array_find(
            [...\array_keys($this->simpleHandlers()), ...\array_keys($this->positionalHandlers())],
            static fn (string $action): bool => $kb->matches($keys, $action),
        );
        // The remaining actions carry extra logic beyond firing a single hook.
        $cycle = null === $action && ($kb->matches($keys, 'next_buffer') || $kb->matches($keys, 'prev_buffer'));
        $find = null === $action && !$cycle && ($kb->matches($keys, 'find') || $kb->matches($keys, 'replace'));
        match (true) {
            null !== $action => $this->run($action),
            $cycle => ($this->callbacks)()->buffer?->cycleBuffer($kb->matches($keys, 'next_buffer') ? 1 : -1),
            $find => $this->openFind($kb->matches($keys, 'replace')),
            default => null,
        };

        return null !== $action || $cycle || $find;
    }

    /**
     * Invoke an editor action by its id (the menu-bar entry point). Returns
     * true when the id named a table action; unknown ids are left to the caller.
     */
    public function run(string $action): bool
    {
        $simple = $this->simpleHandlers()[$action] ?? null;
        $positional = $this->positionalHandlers()[$action] ?? null;
        if (null !== $simple) {
            $simple(($this->callbacks)());
        }
        if (null === $simple && null !== $positional) {
            [$line, $col] = ($this->caret)();
            $positional(($this->callbacks)(), $line, $col);
        }

        return null !== $simple || null !== $positional;
    }

    // @mago-expect lint:no-boolean-flag-parameter -- which of the two bars opens is the one fact the key carries.
    private function openFind(bool $replace): void
    {
        $file = ($this->callbacks)()->file;
        $replace ? $file?->openFindReplace() : $file?->openFind();
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
            'apply_quick_fix' => static fn (EditorCallbacks $c, int $line, int $col) => $c->refactor?->requestApplyQuickFix($line, $col),
            'refactor_this' => static fn (EditorCallbacks $c, int $line, int $col) => $c->refactor?->requestRefactorings($line, $col),
            'inline_refactoring' => static fn (EditorCallbacks $c, int $line, int $col) => $c->refactor?->requestInline($line, $col),
            'organize_imports' => static fn (EditorCallbacks $c, int $line, int $col) => $c->refactor?->requestOrganizeImports($line, $col),
            'quick_doc' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestHover($line, $col),
            'show_error_description' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestErrorDescription($line, $col),
            'find_usages' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestReferences($line, $col),
            'call_hierarchy' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestCallHierarchy($line, $col),
            'outgoing_calls' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestOutgoingCalls($line, $col),
            'goto_definition' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestDefinition($line, $col),
            'goto_implementation' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestImplementation($line, $col),
            'goto_type_definition' => static fn (EditorCallbacks $c, int $line, int $col) => $c->navigation?->requestTypeDefinition($line, $col),
        ];
    }
}
