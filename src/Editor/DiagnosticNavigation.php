<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Notifier;

/**
 * PhpStorm's F2 / Shift+F2: walk the current file's diagnostics without
 * leaving the editor.
 *
 * The error stripe already shows where they are and the Problems tool already
 * lists them; this is the keyboard verb between the two — you stay on the code
 * and step through what is wrong with it.
 *
 * No language-server round trip: the diagnostics are already on the buffer,
 * published by whatever produced them. **Wraps** at either end, and says so,
 * because a silent no-op at the last error reads as a broken key.
 */
#[AsIdeService]
final readonly class DiagnosticNavigation
{
    public function __construct(
        private BufferManager $buffers,
        private CodeEditorWidget $editor,
        private Notifier $notifier,
        private Translator $translator,
    ) {}

    public function next(): void
    {
        $this->go(ProblemStep::Next);
    }

    public function previous(): void
    {
        $this->go(ProblemStep::Previous);
    }

    /**
     * The nearest diagnostic line strictly past the caret in that direction,
     * or the far end when there is none — one pass over the lines, since a
     * file's diagnostics are few and unordered as they arrive.
     */
    private function go(ProblemStep $step): void
    {
        $buffer = $this->buffers->active();
        if (null === $buffer || $buffer->isViewer()) {
            return;
        }
        $lines = array_keys(array_filter($buffer->diagnostics, static fn (array $d): bool => [] !== $d));
        if ([] === $lines) {
            $this->notifier->flash($this->translator->trans('No problems in this file'));

            return;
        }
        sort($lines);
        if (ProblemStep::Previous === $step) {
            // One direction of travel, read backwards: everything below works
            // on "the next line in $lines past the caret".
            $lines = array_reverse($lines);
        }
        $caret = $buffer->document->getCursorLine();
        $past = static fn (int $line): bool => ProblemStep::Next === $step ? $line > $caret : $line < $caret;
        $ahead = array_values(array_filter($lines, $past));

        // Wrapping to $lines[0] IS the far end, because $lines runs the way we
        // are travelling.
        $wrapped = [] === $ahead;
        $target = $wrapped ? $lines[0] : $ahead[0];

        $this->editor->jumpTo($target, 0);
        $text = self::problemText($buffer->diagnostics[$target], $target);
        $this->notifier->flash($wrapped ? $this->translator->trans('Wrapped to %problem%', ['%problem%' => $text]) : $text);
    }

    /**
     * The diagnostic's own text, so the flash answers "what is wrong here"
     * rather than only "you have arrived". Numbered the way the gutter numbers
     * it, which is one-based.
     *
     * @param list<array{severity: int, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>}> $onLine
     */
    private static function problemText(array $onLine, int $line): string
    {
        return \sprintf('%d: %s', $line + 1, $onLine[0]['message']);
    }
}
