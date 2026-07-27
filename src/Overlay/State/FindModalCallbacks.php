<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

/**
 * The Find-in-Files modal's outbound hooks, wired in one call instead of nine
 * fluent setters. Modelled on {@see \Porta\Widget\EditorCallbacks}, the same
 * shape the editor uses.
 *
 * Every slot is nullable: a modal driven in isolation (a test, or the dialog
 * opened before its controller exists) fires nothing.
 */
final readonly class FindModalCallbacks
{
    /**
     * @param (callable(string): void)|null            $onQuery            the query text changed
     * @param (callable(string): void)|null            $onMaskChanged      the file mask changed
     * @param (callable(int): void)|null               $onScopeCycle       the scope choice index
     * @param (callable(string): void)|null            $onToggleOption     option short key
     * @param (callable(string, int): void)|null       $onJump             path, line
     * @param (callable(): void)|null                  $onOpenInFindWindow promote the dialog to the results pane
     * @param (callable(string, list<int>): void)|null $onReplaceAll       replace kept matches (excluding the given indices)
     * @param (\Closure(string, string): string)|null  $previewReplacement renders a match line's old → new preview
     * @param (callable(): void)|null                  $onClose            the dialog was dismissed
     */
    public function __construct(
        public mixed $onQuery = null,
        public mixed $onMaskChanged = null,
        public mixed $onScopeCycle = null,
        public mixed $onToggleOption = null,
        public mixed $onJump = null,
        public mixed $onOpenInFindWindow = null,
        public mixed $onReplaceAll = null,
        public mixed $previewReplacement = null,
        public mixed $onClose = null,
    ) {}
}
