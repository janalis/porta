<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Tree;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\ChangeMarkKind;
use Porta\Tree\FileDecorations;
use Porta\Tree\FileStatuses;

final class FileDecorationsTest extends TestCase
{
    /** @return array{FileDecorations, BufferManager, FileStatuses} */
    private function make(): array
    {
        $buffers = new BufferManager();
        $statuses = new FileStatuses();

        return [new FileDecorations($buffers, $statuses), $buffers, $statuses];
    }

    /**
     * @param list<int> $severities one per line
     *
     * @return array<int, list<array{severity: int, message: string, col: int}>>
     */
    private function diagnostics(array $severities): array
    {
        $out = [];
        foreach ($severities as $line => $severity) {
            $out[$line] = [['severity' => $severity, 'message' => 'm', 'col' => 0]];
        }

        return $out;
    }

    public function testAnUntouchedCleanBufferIsAbsentEntirely(): void
    {
        [$decorations, $buffers] = $this->make();
        $buffers->open('/p/a.php', "<?php\n");

        $this->assertSame([], $decorations->snapshot(), 'a file with nothing to say must not cost the tree a mark');
    }

    public function testAnEditedBufferReportsDirty(): void
    {
        [$decorations, $buffers] = $this->make();
        $buffer = $buffers->open('/p/a.php', "<?php\n");
        $buffer->document->insert('x');

        $this->assertSame(['/p/a.php' => ['dirty' => true, 'severity' => 0, 'status' => null]], $decorations->snapshot());
    }

    public function testItReportsTheWorstSeverityAcrossEveryLine(): void
    {
        [$decorations, $buffers] = $this->make();
        $buffer = $buffers->open('/p/a.php', "<?php\n");
        // The error is NOT on the first line: a scan that stopped at the first
        // one it found would report the warning.
        $buffer->diagnostics = $this->diagnostics([2, 2, 1, 2]);

        $this->assertSame(['/p/a.php' => ['dirty' => false, 'severity' => 1, 'status' => null]], $decorations->snapshot());
    }

    /** Info and hint are real diagnostics but not worth a file-row mark. */
    public function testInfoAndHintDiagnosticsLeaveTheFileUnmarked(): void
    {
        [$decorations, $buffers] = $this->make();
        $buffer = $buffers->open('/p/a.php', "<?php\n");
        $buffer->diagnostics = $this->diagnostics([3, 4]);

        $this->assertSame([], $decorations->snapshot());
    }

    /** An emptied line list is not a diagnostic — the file must fall back out of the snapshot. */
    public function testALineWhoseDiagnosticsClearedDoesNotMarkTheFile(): void
    {
        [$decorations, $buffers] = $this->make();
        $buffer = $buffers->open('/p/a.php', "<?php\n");
        $buffer->diagnostics = [3 => []];

        $this->assertSame([], $decorations->snapshot());
    }

    public function testEveryOpenBufferIsReportedUnderItsOwnPath(): void
    {
        [$decorations, $buffers] = $this->make();
        $buffers->open('/p/a.php', "<?php\n")->diagnostics = $this->diagnostics([2]);
        $buffers->open('/p/b.php', "<?php\n")->document->insert('x');
        $buffers->open('/p/c.php', "<?php\n");

        $this->assertSame(
            [
                '/p/a.php' => ['dirty' => false, 'severity' => 2, 'status' => null],
                '/p/b.php' => ['dirty' => true, 'severity' => 0, 'status' => null],
            ],
            $decorations->snapshot(),
        );
    }

    /**
     * The version-control half covers the WHOLE working tree, so a file nothing
     * has ever opened is still marked — that is the difference between this and
     * the buffer-shaped marks beside it.
     */
    public function testAChangedFileWithNoBufferIsStillReported(): void
    {
        [$decorations, , $statuses] = $this->make();
        $statuses->applyFileStatuses(['/p/never-opened.php' => ChangeMarkKind::Modified]);

        $this->assertSame(
            ['/p/never-opened.php' => ['dirty' => false, 'severity' => 0, 'status' => ChangeMarkKind::Modified]],
            $decorations->snapshot(),
        );
    }

    /** A file can be both changed against HEAD and unsaved right now; neither mark may eat the other. */
    public function testAFileCarriesItsStatusAndItsBufferStateTogether(): void
    {
        [$decorations, $buffers, $statuses] = $this->make();
        $statuses->applyFileStatuses(['/p/a.php' => ChangeMarkKind::Added]);
        $buffers->open('/p/a.php', "<?php\n")->document->insert('x');

        $this->assertSame(
            ['/p/a.php' => ['dirty' => true, 'severity' => 0, 'status' => ChangeMarkKind::Added]],
            $decorations->snapshot(),
        );
    }

    /** A file leaving the map is the news that it went back to unmodified. */
    public function testAFileDroppingOutOfTheStatusMapLosesItsMark(): void
    {
        [$decorations, , $statuses] = $this->make();
        $statuses->applyFileStatuses(['/p/a.php' => ChangeMarkKind::Modified]);
        $statuses->applyFileStatuses([]);

        $this->assertSame([], $decorations->snapshot());
    }
}
