<?php

declare(strict_types=1);

namespace Porta\Tree;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DiagnosticStyle;

/**
 * Per-file state the file tree marks its rows with, read straight off the open
 * buffers: unsaved changes, and the worst diagnostic the file carries. Pure
 * over {@see BufferManager} the way {@see \Porta\Search\ProblemRows} is, so the
 * tree and the Problems tool can never disagree about which files are broken.
 *
 * The buffer-shaped half only covers files something has opened; the
 * version-control status covers the whole working tree, because a plugin
 * publishes that separately ({@see FileStatusSink}). Nothing here walks the
 * project or reads disk either way — the tree repaints on a frame budget.
 */
#[AsIdeService]
final readonly class FileDecorations
{
    /** Severities worth a tree mark: an error or a warning. Info and hint would be noise on a file row. */
    private const int WORST_UNMARKED = 3;

    public function __construct(
        private BufferManager $buffers,
        private FileStatuses $statuses,
    ) {}

    /**
     * @return array<string, array{dirty: bool, severity: int, status: ChangeMarkKind|null}> absolute path =>
     *                                                                                       state; severity 0 means "nothing worth marking".
     *                                                                                       A file with nothing to say is absent entirely
     */
    public function snapshot(): array
    {
        $out = [];
        foreach ($this->statuses->all() as $path => $status) {
            $out[$path] = ['dirty' => false, 'severity' => 0, 'status' => $status];
        }
        foreach ($this->buffers->all() as $buffer) {
            $dirty = $buffer->isDirty();
            $severity = $this->worstMarkable($buffer->diagnostics);
            if (!$dirty && 0 === $severity) {
                continue;
            }
            $out[$buffer->path] = [
                'dirty' => $dirty,
                'severity' => $severity,
                // A file can be both: changed against HEAD and unsaved right now.
                'status' => $out[$buffer->path]['status'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * The worst severity in the file, or 0 when none is worth a mark. Stops at
     * the first error: nothing outranks it, and this runs every frame over every
     * open buffer's whole diagnostic set.
     *
     * @param array<int, list<array{severity: int, message: string, col: int, endCol?: int}>> $byLine
     */
    private function worstMarkable(array $byLine): int
    {
        $worst = self::WORST_UNMARKED;
        foreach ($byLine as $diagnostics) {
            if ([] === $diagnostics) {
                continue;
            }
            $worst = min($worst, DiagnosticStyle::worst($diagnostics));
            if (1 === $worst) {
                break;
            }
        }

        return self::WORST_UNMARKED === $worst ? 0 : $worst;
    }
}
