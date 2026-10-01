<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Ui\TextEcho;

/**
 * The two floating input surfaces a feature can raise over the editor pane: a
 * pick-one list and a single-line prompt. Implemented by the editor widget,
 * which owns the {@see \Porta\Overlay\State\OverlayHost} they live in and closes
 * the sibling floaters first.
 *
 * Depended on by the features that only need to *ask the user something* —
 * choosing a branch, a run configuration, a stash — without touching the
 * document. {@see EditorHost} carries the same two verbs for the editing-verb
 * dispatcher, which needs the wider editing surface alongside them.
 */
interface ModalHost
{
    /**
     * Open a modal list overlay (recent files, later: hover docs etc.).
     *
     * @param list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool}> $items
     * @param callable(string): void                                  $onPick receives the picked item's value
     */
    public function openListOverlay(string $title, array $items, callable $onPick): void;

    /**
     * Open a single-line input prompt (go to line, rename symbol).
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    public function openPrompt(string $label, string $initial, callable $onSubmit, bool $danger = false, TextEcho $echo = TextEcho::Visible): void;
}
