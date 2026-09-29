<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Api\PluginCommand;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/** What every plugin console command gets from its base: the project it was run in, and where to fail. */
final class PluginCommandTest extends TestCase
{
    private static function command(?string $root = null): PluginCommand
    {
        return new class($root) extends PluginCommand {
            public function root(): string
            {
                return $this->projectRoot();
            }

            public static function errorsOf(OutputInterface $output): OutputInterface
            {
                return self::errors($output);
            }
        };
    }

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
        $this->assertSame('/given', self::command('/given')->root(), 'a test, or a caller that knows, says which');

        $withPorta = new TempProject(['.porta/keep' => '', 'a/b/x' => '']);
        $withGit = new TempProject(['.git/HEAD' => '', 'sub/x' => '']);
        $neither = new TempProject(['x' => '']);
        $real = static fn (string $path): string => (string) \realpath($path);

        self::in($withPorta->path('a/b'), fn () => $this->assertSame($real($withPorta->dir), $real(self::command()->root())));
        self::in($withGit->path('sub'), fn () => $this->assertSame($real($withGit->dir), $real(self::command()->root())));
        self::in($neither->dir, fn () => $this->assertSame($real($neither->dir), $real(self::command()->root()), 'nothing above: where it was run'));
    }

    public function testAFailureGoesToStderrWhenThereIsOne(): void
    {
        $console = new ConsoleOutput();
        $plain = new BufferedOutput();

        $this->assertSame($console->getErrorOutput(), self::command()::errorsOf($console));
        $this->assertSame($plain, self::command()::errorsOf($plain), 'no stderr: the one stream there is');
    }
}
