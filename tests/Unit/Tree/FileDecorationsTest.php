<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Tree;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DiagnosticSeverity;
use Porta\Event\AgentActivity;
use Porta\Tests\Support\TestAgentPresence;
use Porta\Tree\FileDecorations;
use Porta\Tree\FileStatuses;
use Porta\Workbench\AgentActivityKind;
use Porta\Workbench\AgentPresence;

final class FileDecorationsTest extends TestCase
{
    private AgentPresence $agents;

    /** @return array{FileDecorations, BufferManager, FileStatuses} */
    private function make(): array
    {
        $buffers = new BufferManager();
        $statuses = new FileStatuses();

        return [new FileDecorations($buffers, $statuses, $this->agents = TestAgentPresence::create()), $buffers, $statuses];
    }

    /**
     * @param list<int> $severities one per line, on the LSP scale
     *
     * @return array<int, list<array{severity: DiagnosticSeverity, message: string, col: int}>>
     */
    private function diagnostics(array $severities): array
    {
        $out = [];
        foreach ($severities as $line => $severity) {
            $out[$line] = [['severity' => DiagnosticSeverity::from($severity), 'message' => 'm', 'col' => 0]];
        }

        return $out;
    }

    /**
     * A clean buffer does not stop the scan at the buffers after it.
     *
     * The loop skips a buffer with nothing to report, and turning that
     * `continue` into a `break` left every test here green: each opened ONE
     * buffer, or opened the interesting one first. Two tabs where the first is
     * clean is the ordinary case, and the second tab's dirty mark would simply
     * not be drawn.
     */
    public function testACleanBufferDoesNotHideTheDirtyOneAfterIt(): void
    {
        [$decorations, $buffers] = $this->make();
        $buffers->open('/p/clean.php', "<?php\n");
        $dirty = $buffers->open('/p/dirty.php', "<?php\n");
        $dirty->document->insert('x');

        $snapshot = $decorations->snapshot();

        $this->assertArrayHasKey('/p/dirty.php', $snapshot, 'the second buffer was still reached');
        $this->assertTrue($snapshot['/p/dirty.php']['dirty']);
        $this->assertArrayNotHasKey('/p/clean.php', $snapshot, 'and the first still says nothing');
    }

    /**
     * A line with no diagnostics does not stop the search for the worst one.
     *
     * `worstMarkable()` skips empty lines, and turning that `continue` into a
     * `break` left the suite green because every fixture put its diagnostic on
     * the first line. A warning on line 5 of a file whose earlier lines are
     * clean is the ordinary case, and its mark would vanish.
     */
    public function testADiagnosticBelowACleanLineIsStillFound(): void
    {
        [$decorations, $buffers] = $this->make();
        $buffer = $buffers->open('/p/a.php', "<?php\n");
        $buffer->diagnostics = [0 => [], 5 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'm', 'col' => 0]]];

        $snapshot = $decorations->snapshot();

        $this->assertArrayHasKey('/p/a.php', $snapshot, 'the diagnostic below the empty line counts');
        $this->assertSame(DiagnosticSeverity::Warning, $snapshot['/p/a.php']['severity']);
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

        $this->assertSame(['/p/a.php' => ['dirty' => true, 'severity' => null, 'status' => null, 'agents' => []]], $decorations->snapshot());
    }

    public function testItReportsTheWorstSeverityAcrossEveryLine(): void
    {
        [$decorations, $buffers] = $this->make();
        $buffer = $buffers->open('/p/a.php', "<?php\n");
        // The error is NOT on the first line: a scan that stopped at the first
        // one it found would report the warning.
        $buffer->diagnostics = $this->diagnostics([2, 2, 1, 2]);

        $this->assertSame(['/p/a.php' => ['dirty' => false, 'severity' => DiagnosticSeverity::Error, 'status' => null, 'agents' => []]], $decorations->snapshot());
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
                '/p/a.php' => ['dirty' => false, 'severity' => DiagnosticSeverity::Warning, 'status' => null, 'agents' => []],
                '/p/b.php' => ['dirty' => true, 'severity' => null, 'status' => null, 'agents' => []],
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
            ['/p/never-opened.php' => ['dirty' => false, 'severity' => null, 'status' => ChangeMarkKind::Modified, 'agents' => []]],
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
            ['/p/a.php' => ['dirty' => true, 'severity' => null, 'status' => ChangeMarkKind::Added, 'agents' => []]],
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

    public function testAFileAnAgentIsAtWorkOnCarriesWhoAndWhatEvenUnopened(): void
    {
        [$decorations, $buffers] = $this->make();
        $buffers->open('/p/a.php', "<?php\n")->document->insert('x');
        $this->agents->onAgentActivity(new AgentActivity('mcp-1', 'Claude', '/p/a.php', AgentActivityKind::Editing, 0, 0, 10.0));
        $this->agents->onAgentActivity(new AgentActivity('mcp-1', 'Claude', '/p/closed.php', AgentActivityKind::Reading, 0, 0, 10.0));

        $this->assertSame(
            [
                '/p/a.php' => ['dirty' => true, 'severity' => null, 'status' => null, 'agents' => [['name' => 'Claude', 'kind' => AgentActivityKind::Editing]]],
                '/p/closed.php' => ['dirty' => false, 'severity' => null, 'status' => null, 'agents' => [['name' => 'Claude', 'kind' => AgentActivityKind::Reading]]],
            ],
            $decorations->snapshot(),
        );
    }
}
