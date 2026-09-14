<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\BreakpointStore;
use Porta\Debug\DebugStatePersistence;
use Porta\Debug\DebugStateStore;
use Porta\Debug\ExceptionFilterStore;
use Porta\Debug\WatchStore;
use Porta\Tests\Support\TempProject;

final class DebugStateStoreTest extends TestCase
{
    public function testRoundTripPreservesConditionsWatchesAndFilters(): void
    {
        $project = new TempProject();
        $store = new DebugStateStore($project->dir);

        $breakpoints = [
            '/p/a.php' => [
                ['line' => 4, 'condition' => '$x > 1', 'hitCondition' => null, 'logMessage' => null],
                ['line' => 9, 'condition' => null, 'hitCondition' => '3', 'logMessage' => null],
                ['line' => 11, 'condition' => null, 'hitCondition' => null, 'logMessage' => 'here x={$x}'],
            ],
        ];
        $this->assertTrue($store->save($breakpoints, ['$x'], ['exception']));
        $this->assertFileExists($project->path('.porta/debug.json'));

        $this->assertSame(
            ['breakpoints' => $breakpoints, 'watches' => ['$x'], 'exceptionFilters' => ['exception']],
            $store->load(),
        );
    }

    public function testNullFilterSelectionIsOmittedAndRestoredAsNull(): void
    {
        $project = new TempProject();
        $store = new DebugStateStore($project->dir);
        $store->save(['/p/a.php' => [['line' => 1, 'condition' => null, 'hitCondition' => null]]], [], null);

        $this->assertStringNotContainsString('exceptionFilters', (string) \file_get_contents($project->path('.porta/debug.json')));
        $this->assertNull($store->load()['exceptionFilters']);
    }

    public function testEmptyStateRemovesTheFile(): void
    {
        $project = new TempProject();
        $store = new DebugStateStore($project->dir);
        $store->save(['/p/a.php' => [['line' => 1, 'condition' => null, 'hitCondition' => null]]], [], null);
        $this->assertFileExists($project->path('.porta/debug.json'));

        $this->assertTrue($store->save([], [], null));
        $this->assertFileDoesNotExist($project->path('.porta/debug.json'));
    }

    public function testAbsentCorruptAndMalformedFilesDegrade(): void
    {
        $project = new TempProject();
        $store = new DebugStateStore($project->dir);
        $this->assertNull($store->load(), 'no file');

        $project->write('.porta/debug.json', '{not json');
        $this->assertNull($store->load(), 'corrupt file');

        $project->write('.porta/debug.json', \json_encode([
            'breakpoints' => [
                // An invalid path (or entry list) must be skipped, not abort
                // the rest of the loop — so a valid path/entry is placed
                // AFTER each invalid one below.
                '/p/none.php' => 'not-a-list',
                '/p/ok.php' => [['line' => 'nope'], ['line' => 2], 'junk', ['line' => 5]],
                3 => [['line' => 1]], // non-string path key... json round-trips it back to an int — dropped
            ],
            'watches' => ['$a', 7, '', '$b'],
            'exceptionFilters' => ['a', 42, 'b'],
        ]));
        $state = $store->load();
        $this->assertSame(
            [
                ['line' => 2, 'condition' => null, 'hitCondition' => null, 'logMessage' => null],
                ['line' => 5, 'condition' => null, 'hitCondition' => null, 'logMessage' => null],
            ],
            $state['breakpoints']['/p/ok.php'],
        );
        $this->assertArrayNotHasKey('/p/none.php', $state['breakpoints']);
        $this->assertSame(['$a', '$b'], $state['watches'], 'non-strings and empties dropped, without leaving key gaps');
        $this->assertSame(['a', 'b'], $state['exceptionFilters'], 'filtered out values must not leave key gaps');
    }

    public function testEmptyBreakpointsStillEncodeAsAJsonObject(): void
    {
        // Breakpoints keyed by path are conceptually a map, not a list — an
        // empty map must round-trip through JSON as `{}`, not `[]`, or a
        // strict schema reader downstream would reject it.
        $project = new TempProject();
        $store = new DebugStateStore($project->dir);
        $store->save([], ['$x'], null);

        $decoded = \json_decode((string) \file_get_contents($project->path('.porta/debug.json')), associative: false, flags: \JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $decoded->breakpoints, 'an empty map must decode as an object, not an array');
    }

    public function testIoFailureReportsFalse(): void
    {
        $project = new TempProject();
        $project->write('.porta', ''); // a FILE where the directory must go
        $store = new DebugStateStore($project->dir);
        $this->assertFalse($store->save(['/p/a.php' => [['line' => 1, 'condition' => null, 'hitCondition' => null]]], [], null));
    }

    public function testPersistenceRestoreHydratesAndSkipsVanishedFiles(): void
    {
        $project = new TempProject(['real.php' => "<?php\n"]);
        $store = new DebugStateStore($project->dir);
        $real = $project->path('real.php');
        $store->save(
            [
                // The vanished file sits BEFORE the real one: restore() must
                // skip it and keep going, not abort the rest of the loop.
                $project->path('gone.php') => [['line' => 3, 'condition' => null, 'hitCondition' => null]],
                $real => [['line' => 0, 'condition' => '$x', 'hitCondition' => null]],
            ],
            ['$watched'],
            ['exception'],
        );

        $breakpoints = new BreakpointStore();
        $watches = new WatchStore();
        $filters = new ExceptionFilterStore();
        $persistence = new DebugStatePersistence($store, $breakpoints, $watches, $filters);
        $persistence->restore();

        $this->assertSame([0], $breakpoints->linesFor($real));
        $this->assertSame(['condition' => '$x', 'hitCondition' => null, 'logMessage' => null], $breakpoints->conditionAt($real, 0));
        $this->assertSame([], $breakpoints->linesFor($project->path('gone.php')), 'vanished files are skipped');
        $this->assertSame(['$watched'], $watches->expressions);
        $this->assertSame(['exception'], $filters->selection());

        // Save round-trips through the live stores (the vanished file is gone for good).
        $watches->remove(0);
        $persistence->save();
        $reloaded = $store->load();
        $this->assertSame([$real], \array_keys($reloaded['breakpoints']));
        $this->assertSame([], $reloaded['watches']);
    }

    public function testAnUnencodablePayloadDegradesToFalseInsteadOfThrowing(): void
    {
        // A breakpoint path or watch expression can carry invalid UTF-8;
        // json_encode(JSON_THROW_ON_ERROR) then throws during TEARDOWN
        // (DebugStatePersistence::save runs in Ide's finally), where an escape
        // would abort the rest of the shutdown sequence.
        $project = new TempProject();

        $saved = new DebugStateStore($project->dir)->save([], ["\$x\xB1"], null);

        $this->assertFalse($saved);
        $this->assertFalse(\is_file($project->path('.porta/debug.json')), 'nothing half-written');
    }

    public function testPersistenceRestoreWithoutAFileIsANoOp(): void
    {
        $project = new TempProject();
        $breakpoints = new BreakpointStore();
        $persistence = new DebugStatePersistence(new DebugStateStore($project->dir), $breakpoints, new WatchStore(), new ExceptionFilterStore());
        $persistence->restore();
        $this->assertSame([], $breakpoints->all());
    }
}
