<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Search\ResultContextProvider;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\TempProject;

final class ResultContextProviderTest extends TestCase
{
    public function testReadsFromDiskWhenTheFileIsNotOpen(): void
    {
        $project = new TempProject(['a.php' => "0\n1\n2\n3\n4\n5\n"]);
        $provider = new ResultContextProvider(new BufferManager(), new ProjectPaths($project->dir));

        $context = $provider->around('a.php', 2);

        $this->assertSame(
            [['line' => 0, 'text' => '0'], ['line' => 1, 'text' => '1'], ['line' => 3, 'text' => '3'], ['line' => 4, 'text' => '4']],
            $context,
            'the match line itself is omitted',
        );
    }

    public function testReadsFromTheLiveBufferWhenOpen(): void
    {
        $project = new TempProject(['a.php' => "old0\nold1\nold2"]);
        $buffers = new BufferManager();
        $buffers->open($project->path('a.php'), "new0\nnew1\nnew2");
        $provider = new ResultContextProvider($buffers, new ProjectPaths($project->dir));

        $context = $provider->around('a.php', 1);

        $this->assertSame(['new0', 'new2'], array_column($context, 'text'), 'unsaved buffer content wins over disk');
    }

    public function testBufferLookupFallsBackToARealpathMatch(): void
    {
        // The buffer is keyed by its canonical path, but the plain
        // concatenation of root + relative path carries a redundant
        // "sub/../sub/" — an exact-key lookup misses, so only the realpath
        // fallback finds the live buffer instead of falling through to disk.
        $project = new TempProject(['sub/a.php' => "x0\nx1\nx2"]);
        $buffers = new BufferManager();
        $buffers->open((string) realpath($project->path('sub/a.php')), "y0\ny1\ny2");
        $provider = new ResultContextProvider($buffers, new ProjectPaths($project->dir));

        $context = $provider->around('sub/../sub/a.php', 1);

        $this->assertSame(['y0', 'y2'], array_column($context, 'text'), 'the realpath fallback finds the live buffer');
    }

    public function testClampsAtTheStartOfTheFile(): void
    {
        $project = new TempProject(['a.php' => "0\n1\n2\n3"]);
        $provider = new ResultContextProvider(new BufferManager(), new ProjectPaths($project->dir));

        $context = $provider->around('a.php', 0);

        $this->assertSame([1, 2], array_column($context, 'line'), 'no negative lines before the start');
    }

    public function testClampsAtTheEndOfTheFile(): void
    {
        $project = new TempProject(['a.php' => "0\n1\n2\n3"]);
        $provider = new ResultContextProvider(new BufferManager(), new ProjectPaths($project->dir));

        $context = $provider->around('a.php', 3);

        $this->assertSame([1, 2], array_column($context, 'line'), 'no lines requested past the last one');
    }

    public function testTrimsTrailingWhitespaceFromEachContextLine(): void
    {
        $project = new TempProject(['a.php' => "0  \n1\n2"]);
        $provider = new ResultContextProvider(new BufferManager(), new ProjectPaths($project->dir));

        $context = $provider->around('a.php', 1);

        $this->assertSame('0', $context[0]['text'], 'trailing whitespace is trimmed');
    }
}
