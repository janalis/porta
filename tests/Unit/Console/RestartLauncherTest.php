<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\RestartLauncher;
use Porta\Editor\BufferManager;
use Porta\History\RestartSnapshot;
use Porta\Install\PortaBinary;
use Porta\Support\FileLock;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Process\Process;

final class RestartLauncherTest extends TestCase
{
    use PumpsLoop;

    public function testFreshProcessLoadsUpdatedNamedClassAndRecoversUnsavedEdits(): void
    {
        $class = 'PortaRestartProof'.\bin2hex(\random_bytes(6));
        $project = new TempProject(['document.php' => 'disk']);
        $before = '<?php final class '.$class.' { public const string VERSION = "before"; }';
        $after = \str_replace('before', replace: 'after', subject: $before);
        $project->write('Release.php', $before);
        require $project->path('Release.php');
        $this->assertSame('before', \constant($class.'::VERSION'));
        $project->write('update.php', '<?php file_put_contents($argv[1], $argv[2]);');
        $project->write('launch.php', <<<'PHP'
            <?php
            require $argv[1];
            require $argv[2];
            $buffers = new Porta\Editor\BufferManager();
            $directory = getenv('PORTA_RESTART_DIRECTORY');
            $conflicts = new Porta\History\RestartSnapshot()->restore($directory, getcwd(), $buffers);
            file_put_contents($argv[4], json_encode([
                'version' => constant($argv[3].'::VERSION'),
                'text' => $buffers->active()?->document->getText(),
                'dirty' => $buffers->active()?->document->isDirty(),
                'conflicts' => $conflicts,
            ], JSON_THROW_ON_ERROR));
            PHP);
        $buffers = new BufferManager();
        $buffers->open($project->path('document.php'), 'disk')->document->replaceAll('unsaved');
        $directory = $project->path('handoff');
        $this->assertTrue(new RestartSnapshot()->save($directory, $project->dir, $buffers));
        $helper = new RestartLauncher()->prepare(
            $directory,
            $project->dir,
            [\PHP_BINARY, $project->path('launch.php'), \dirname(__DIR__, levels: 3).'/vendor/autoload.php', $project->path('Release.php'), $class, $project->path('receipt.json')],
            [\PHP_BINARY, $project->path('update.php'), $project->path('Release.php'), $after],
            $project->path('install.lock'),
        );
        $this->assertNotNull($helper);
        $process = new Process([\PHP_BINARY, $helper, $directory.'/launch.json']);
        $this->assertSame(0, $process->run(), $process->getErrorOutput());
        $receipt = \json_decode((string) \file_get_contents($project->path('receipt.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(['version' => 'after', 'text' => 'unsaved', 'dirty' => true, 'conflicts' => []], $receipt);
        $this->assertSame('before', \constant($class.'::VERSION'), 'the parent still has the old class');
        $this->assertSame('disk', \file_get_contents($project->path('document.php')));
    }

    public function testFailedLaunchRetainsTheRecoveryData(): void
    {
        $project = new TempProject();
        $directory = $project->path('handoff');
        $this->assertTrue(new RestartSnapshot()->save($directory, $project->dir, new BufferManager()));
        $helper = new RestartLauncher()->prepare($directory, $project->dir, [\PHP_BINARY, '-r', 'exit(7);'], [], $project->path('lock'));
        $this->assertNotNull($helper);
        $process = new Process([\PHP_BINARY, $helper, $directory.'/launch.json']);
        $this->assertSame(7, $process->run());
        $this->assertStringContainsString('recovery data retained', $process->getErrorOutput());
        $this->assertFileExists($directory.'/session.json');
    }

    public function testUnencodableLaunchContextDoesNotStartAnUpdate(): void
    {
        $project = new TempProject();
        $this->assertNull(new RestartLauncher()->prepare($project->dir, "\xFF", [], [], $project->path('lock')));
    }

    public function testUpdateFailureReopensAndLeavesANotificationForTheRestoredIde(): void
    {
        $project = new TempProject();
        $helper = new RestartLauncher()->prepare($project->dir, $project->dir, [\PHP_BINARY, '-r', 'echo "reopened";'], [\PHP_BINARY, '-r', 'exit(5);'], $project->path('lease'));
        $this->assertNotNull($helper);
        $process = new Process([\PHP_BINARY, $helper, $project->path('launch.json')]);
        $this->assertSame(0, $process->run());
        $this->assertSame('reopened', $process->getOutput());
        $this->assertFileExists($project->path('update-failed'));
    }

    public function testTheHelperWaitsForAnotherInstallationWriterBeforeLaunching(): void
    {
        $project = new TempProject();
        $lock = new FileLock($project->path('lease'));
        $this->assertTrue($lock->acquire());
        $helper = new RestartLauncher()->prepare($project->dir, $project->dir, [\PHP_BINARY, '-r', 'echo "reopened";'], [\PHP_BINARY, '-r', 'echo "updated";'], $project->path('lease'));
        $this->assertNotNull($helper);
        $process = new Process([\PHP_BINARY, $helper, $project->path('launch.json')]);
        try {
            $process->start();
            $this->await(static fn (): bool => \str_contains($process->getErrorOutput(), 'Waiting'), what: 'the installation lease conflict');
            $this->assertSame('', $process->getOutput());
            $lock->release();
            $this->assertSame(0, $process->wait());
            $this->assertSame('updatedreopened', $process->getOutput());
        } finally {
            $lock->release();
            $process->stop();
        }
    }

    public function testRepointedInstallationEntrypointLaunchesTheNewVersion(): void
    {
        $project = new TempProject(['Cellar/porta/v1/porta.php' => '<?php echo "old";', 'Cellar/porta/v2/porta.php' => '<?php echo "new";']);
        \mkdir($project->path('bin'));
        \symlink($project->path('Cellar/porta/v1/porta.php'), $project->path('bin/porta'));
        $helper = new RestartLauncher()->prepare(
            $project->dir,
            $project->dir,
            [\PHP_BINARY, PortaBinary::entry($project->path('bin/porta'), $project->path('Cellar/porta/v1/porta.php'))],
            [
                \PHP_BINARY,
                '-r',
                'unlink($argv[1]); symlink($argv[2],$argv[1]); unlink($argv[3]);',
                $project->path('bin/porta'),
                $project->path('Cellar/porta/v2/porta.php'),
                $project->path('Cellar/porta/v1/porta.php'),
            ],
            $project->path('lease'),
        );
        $this->assertNotNull($helper);
        $process = new Process([\PHP_BINARY, $helper, $project->path('launch.json')]);
        $this->assertSame(0, $process->run(), $process->getErrorOutput());
        $this->assertSame('new', $process->getOutput());
    }
}
