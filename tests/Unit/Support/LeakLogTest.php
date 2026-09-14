<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\LeakLog;
use Porta\Tests\Support\TempProject;

/**
 * The leak sink both isolation extensions report through.
 *
 * It is load-bearing for `flake:scan`, which measures a run's leak count as the
 * delta over this file — so "it appended" is the property, and truncating or
 * overwriting would silently zero the measurement for every worker but one.
 */
final class LeakLogTest extends TestCase
{
    /** Held so the destructor does not remove the dir mid-test. */
    private TempProject $project;

    private string|false $previous = false;

    protected function setUp(): void
    {
        $this->previous = \getenv(LeakLog::PATH_ENV);
    }

    protected function tearDown(): void
    {
        \putenv(\is_string($this->previous) ? LeakLog::PATH_ENV.'='.$this->previous : LeakLog::PATH_ENV);
    }

    private function redirect(): string
    {
        $this->project = new TempProject([]);
        $path = $this->project->path('leaks/report.log');
        \putenv(LeakLog::PATH_ENV.'='.$path);

        return $path;
    }

    public function testItAppendsRatherThanOverwriting(): void
    {
        $path = $this->redirect();

        LeakLog::append("first\n");
        LeakLog::append("second\n");

        $this->assertSame("first\nsecond\n", (string) \file_get_contents($path), 'a second worker must not erase the first');
    }

    /** The directory will not exist on a clean checkout's first run. */
    public function testItCreatesTheDirectoryItWritesInto(): void
    {
        $path = $this->redirect();
        $this->assertDirectoryDoesNotExist(\dirname($path));

        LeakLog::append("line\n");

        $this->assertFileExists($path);
    }

    public function testThePathFollowsTheEnvironmentOverride(): void
    {
        $path = $this->redirect();

        $this->assertSame($path, LeakLog::path());
    }

    /** With no override it lands in the gitignored cache dir, not the tree. */
    public function testTheDefaultPathIsInsideTheIgnoredCacheDirectory(): void
    {
        \putenv(LeakLog::PATH_ENV);

        $this->assertStringEndsWith('/.phpunit.cache/leaks.log', LeakLog::path());
    }
}
