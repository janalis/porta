<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestPluginCommand;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

/** What every plugin console command gets from its base: the project it was run in, and where to fail. */
final class PluginCommandTest extends TestCase
{
    /** @param \Closure(): void $test */
    private static function in(string $dir, \Closure $test): void
    {
        $cwd = (string) \getcwd();
        \chdir($dir);
        try {
            $test();
        } finally {
            \chdir($cwd);
        }
    }

    public function testTheProjectIsTheOneGivenElseTheNearestWithPortaOrGitElseTheWorkingDirectory(): void
    {
        $this->assertSame('/given', new TestPluginCommand('/given')->root(), 'a test, or a caller that knows, says which');

        $withPorta = new TempProject(['.porta/keep' => '', 'a/b/x' => '']);
        $withGit = new TempProject(['.git/HEAD' => '', 'sub/x' => '']);
        $neither = new TempProject(['x' => '']);
        $real = static fn (string $path): string => (string) \realpath($path);

        self::in($withPorta->path('a/b'), fn () => $this->assertSame($real($withPorta->dir), $real(new TestPluginCommand()->root())));
        self::in($withGit->path('sub'), fn () => $this->assertSame($real($withGit->dir), $real(new TestPluginCommand()->root())));
        self::in($neither->dir, fn () => $this->assertSame($real($neither->dir), $real(new TestPluginCommand()->root()), 'nothing above: where it was run'));
    }

    public function testAFailureGoesToStderrWhenThereIsOne(): void
    {
        $console = new ConsoleOutput();
        $plain = new BufferedOutput();

        $this->assertSame($console->getErrorOutput(), TestPluginCommand::errorsOf($console));
        $this->assertSame($plain, TestPluginCommand::errorsOf($plain), 'no stderr: the one stream there is');
    }
}
