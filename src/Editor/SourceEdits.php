<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Event\SourceEditRequested;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * Edits a preview asks of the text it was drawn from: a byte range of an open
 * buffer replaced as one undoable edit, the caret left where the user had it
 * ({@see DocumentReplacer}). Only while the range still holds what the preview
 * saw — a tick on a page drawn from an older text must not land on whatever
 * moved under it.
 */
final readonly class SourceEdits
{
    public function __construct(
        private BufferManager $buffers,
        private DocumentReplacer $replacer,
        private Tui $tui,
    ) {}

    #[AsEventListener]
    public function onSourceEditRequested(SourceEditRequested $event): void
    {
        $buffer = $this->buffers->get($event->path);
        if (null === $buffer || $buffer->document->readOnly) {
            return;
        }
        $text = $buffer->document->getText();
        if ($event->start < 0 || $event->end < $event->start || \substr($text, $event->start, $event->end - $event->start) !== $event->expected) {
            return;
        }
        [$line, $column] = self::position($text, $event->start);
        [$endLine, $endColumn] = self::position($text, $event->end);
        $this->replacer->replaceRange($buffer, $line, $column, $endLine, $endColumn, $event->replacement);
        $this->tui->requestRender();
    }

    /** @return array{int, int} the 0-based line and byte column of $offset in $text */
    private static function position(string $text, int $offset): array
    {
        $before = \substr($text, offset: 0, length: $offset);
        $lastNewline = \strrpos($before, needle: "\n");

        return [\substr_count($before, needle: "\n"), false === $lastNewline ? $offset : $offset - $lastNewline - 1];
    }
}
