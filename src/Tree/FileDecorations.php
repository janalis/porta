<?php

declare(strict_types=1);

namespace Porta\Tree;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DiagnosticSeverity;
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
    public function __construct(
        private BufferManager $buffers,
        private FileStatuses $statuses,
    ) {}

    /**
     * @return array<string, array{dirty: bool, severity: DiagnosticSeverity|null, status: ChangeMarkKind|null}> absolute path =>
     *                                                                                       state; a null severity means "nothing worth marking".
     *                                                                                       A file with nothing to say is absent entirely
     */
    public function snapshot(): array
    {
        $out = [];
        foreach ($this->statuses->statuses as $path => $status) {
            $out[$path] = ['dirty' => false, 'severity' => null, 'status' => $status];
        }
        foreach ($this->buffers->all() as $buffer) {
            $dirty = $buffer->isDirty();
            $severity = $this->worstMarkable($buffer->diagnostics);
            if (!$dirty && null === $severity) {
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
     * The worst severity in the file when it is worth a mark — an error or a
     * warning; information and hints would be noise on a file row — else null.
     * Stops at the first error: nothing outranks it, and this runs every frame
     * over every open buffer's whole diagnostic set.
     *
     * @param array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>}>> $byLine
     */
    private function worstMarkable(array $byLine): ?DiagnosticSeverity
    {
        $worst = DiagnosticSeverity::Information;
        foreach ($byLine as $diagnostics) {
            if ([] === $diagnostics) {
                continue;
            }
            $worst = $worst->worse(DiagnosticStyle::worst($diagnostics));
            if (DiagnosticSeverity::Error === $worst) {
                break;
            }
        }

        return $worst->outranks(DiagnosticSeverity::Information) ? $worst : null;
    }
}
