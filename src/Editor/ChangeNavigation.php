<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Notifier;

/**
 * PhpStorm's Ctrl+Alt+Shift+Down / Up: step between the file's changes — the
 * runs of lines the gutter's change strip marks — wrapping past either end.
 *
 * Core's, not a version-control plugin's: the marks are the gutter's own and
 * they follow the edits made since a plugin last computed them, so a walk
 * over the plugin's copy would land as many lines off as were typed. Whatever
 * paints the strip, this walks it.
 */
#[AsIdeService]
final readonly class ChangeNavigation
{
    public function __construct(
        private CodeEditorWidget $editor,
        private Notifier $notifier,
        private Translator $translator,
    ) {}

    public function next(): void
    {
        $this->step(1);
    }

    public function previous(): void
    {
        $this->step(-1);
    }

    private function step(int $direction): void
    {
        $starts = self::changeStarts($this->editor->decorations->vcsMarks);
        if ([] === $starts) {
            $this->notifier->flash($this->translator->trans('No changes in this file'));

            return;
        }
        $line = $this->editor->getDocument()->cursorLine;
        $target = $direction > 0
            ? \array_find($starts, static fn (int $start): bool => $start > $line) ?? $starts[0]
            : \array_find(\array_reverse($starts), static fn (int $start): bool => $start < $line) ?? \array_last($starts);
        $this->editor->jumpTo((int) $target, 0);
    }

    /**
     * The first line of each run of marked lines — one change each.
     *
     * @param array<int, mixed> $marks 0-based line => the mark
     *
     * @return list<int>
     */
    public static function changeStarts(array $marks): array
    {
        \ksort($marks);
        $starts = [];
        $previous = null;
        foreach (\array_keys($marks) as $line) {
            if (null === $previous || $line !== ($previous + 1)) {
                $starts[] = $line;
            }
            $previous = $line;
        }

        return $starts;
    }
}
