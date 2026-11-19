<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\TestCase;
use Porta\Desktop\DesktopRecovery;
use Porta\Desktop\DesktopSession;
use Porta\Editor\BufferManager;
use Porta\History\RestartSnapshot;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\TempProject;

/** What was unsaved when a desktop session ended without asking. */
final class DesktopRecoveryTest extends TestCase
{
    use PreservesPortaHome;

    private TempProject $disk;

    private DesktopSession $desktop;

    #[\Override]
    protected function setUp(): void
    {
        $this->disk = new TempProject(['home/.keep' => '', 'shop/a.php' => "<?php\n"]);
        \putenv('PORTA_HOME='.$this->disk->path('home'));
        $this->desktop = new DesktopSession(active: true, token: \str_repeat('a', 32));
    }

    private function edited(): BufferManager
    {
        $buffers = new BufferManager();
        $buffers->open($this->disk->path('shop/a.php'), content: "<?php\n")->document->insert('unsaved');

        return $buffers;
    }

    public function testUnsavedWorkComesBackWhenTheProjectOpensAgain(): void
    {
        new DesktopRecovery($this->desktop, $this->edited(), $this->disk->path('shop'))->preserve();

        $directory = DesktopRecovery::pending($this->disk->path('shop'), $this->desktop);
        $this->assertNotNull($directory);
        $restored = new BufferManager();
        $this->assertSame([], new RestartSnapshot()->restore($directory, $this->disk->path('shop'), $restored));
        $this->assertStringContainsString('unsaved', $restored->get($this->disk->path('shop/a.php'))?->document->getText() ?? '');
        $this->assertSame("<?php\n", \file_get_contents($this->disk->path('shop/a.php')), 'the project file is left alone');
    }

    public function testTwoWindowsEndedTogetherKeepBoth(): void
    {
        new DesktopRecovery($this->desktop, $this->edited(), $this->disk->path('shop'))->preserve();
        new DesktopRecovery($this->desktop, $this->edited(), $this->disk->path('shop'))->preserve();

        $first = DesktopRecovery::pending($this->disk->path('shop'), $this->desktop);
        $this->assertNotNull($first);
        new RestartSnapshot()->acknowledge($first);
        $second = DesktopRecovery::pending($this->disk->path('shop'), $this->desktop);
        $this->assertNotNull($second, 'the other window\'s work is still there');
        $this->assertNotSame($first, $second);
    }

    public function testNothingUnsavedKeepsNothing(): void
    {
        new DesktopRecovery($this->desktop, new BufferManager(), $this->disk->path('shop'))->preserve();

        $this->assertNull(DesktopRecovery::pending($this->disk->path('shop'), $this->desktop));
    }

    public function testATerminalLaunchNeitherKeepsNorResumes(): void
    {
        $terminal = new DesktopSession(active: false, token: '');

        new DesktopRecovery($terminal, $this->edited(), $this->disk->path('shop'))->preserve();
        $this->assertNull(DesktopRecovery::pending($this->disk->path('shop'), $this->desktop));

        new DesktopRecovery($this->desktop, $this->edited(), $this->disk->path('shop'))->preserve();
        $this->assertNull(DesktopRecovery::pending($this->disk->path('shop'), $terminal));
    }

    public function testAnotherProjectDoesNotResumeThisOne(): void
    {
        new DesktopRecovery($this->desktop, $this->edited(), $this->disk->path('shop'))->preserve();

        $this->assertNull(DesktopRecovery::pending($this->disk->path('home'), $this->desktop));
    }
}
