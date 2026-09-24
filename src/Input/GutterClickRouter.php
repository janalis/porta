<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Config\CommandRegistry;
use Porta\Container\AsIdeService;
use Porta\Debug\DebugController;
use Porta\Widget\CodeEditorWidget;

/**
 * A plain click in the editor's gutter, by column: the fold column folds that
 * region; a version-control mark shows its change — the caret moves to it and
 * `git_show_change` runs, when a plugin provides it; every other column
 * toggles a breakpoint.
 *
 * Its own class because {@see MouseRouter}'s branching is the lint threshold:
 * the change mark is one more target, and it could not be added there.
 */
#[AsIdeService]
final readonly class GutterClickRouter
{
    /** The command a change mark's click runs — the git plugin's, when it is active. */
    public const string SHOW_CHANGE = 'git_show_change';

    public function __construct(
        private CodeEditorWidget $editor,
        private DebugController $debug,
        private CommandRegistry $commands,
    ) {}

    public function click(int $col, int $docLine): void
    {
        if ($col === $this->editor->viewport()->foldCol) {
            // `toggle_fold` works at the caret, so the caret lands on the line
            // first — which is what a fold click should do anyway.
            $this->editor->jumpTo($docLine, 0);
            $this->editor->runAction('toggle_fold');

            return;
        }
        if ($this->editor->changeMarkAt($col, $docLine) && $this->commands->has(self::SHOW_CHANGE)) {
            $this->editor->jumpTo($docLine, 0);
            $this->commands->run(self::SHOW_CHANGE);

            return;
        }
        $this->debug->toggleBreakpointAtDocLine($docLine);
    }
}
