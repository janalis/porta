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

        $this->assertStringNotContainsString('exceptionFilters', (string) file_get_contents($project->path('.porta/debug.json')));
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

        $project->write('.porta/debug.json', json_encode([
            'breakpoints' => [
                '/p/ok.php' => [['line' => 2], ['line' => 'nope'], 'junk'],
                3 => [['line' => 1]], // non-string path key... json makes it "3", still a string — kept
                '/p/none.php' => 'not-a-list',
            ],
            'watches' => ['$x', 7, ''],
            'exceptionFilters' => ['ok', 42],
        ]));
        $state = $store->load();
        $this->assertSame([['line' => 2, 'condition' => null, 'hitCondition' => null, 'logMessage' => null]], $state['breakpoints']['/p/ok.php']);
        $this->assertArrayNotHasKey('/p/none.php', $state['breakpoints']);
        $this->assertSame(['$x'], $state['watches'], 'non-strings and empties dropped');
        $this->assertSame(['ok'], $state['exceptionFilters']);
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
                $real => [['line' => 0, 'condition' => '$x', 'hitCondition' => null]],
                $project->path('gone.php') => [['line' => 3, 'condition' => null, 'hitCondition' => null]],
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
        $this->assertSame(['$watched'], $watches->all());
        $this->assertSame(['exception'], $filters->selection());

        // Save round-trips through the live stores (the vanished file is gone for good).
        $watches->remove(0);
        $persistence->save();
        $reloaded = $store->load();
        $this->assertSame([$real], array_keys($reloaded['breakpoints']));
        $this->assertSame([], $reloaded['watches']);
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
