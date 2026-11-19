<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\TestCase;
use Porta\Desktop\DesktopRecovery;
use Porta\Desktop\DesktopSession;
use Porta\Editor\BufferManager;
use Porta\History\RestartSnapshot;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/** What was unsaved when a desktop session ended without asking. */
final class DesktopRecoveryTest extends TestCase
{
    use PreservesPortaHome;
    use PumpsLoop;

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

    private function recovery(BufferManager $buffers, ?DesktopSession $session = null): DesktopRecovery
    {
        return new DesktopRecovery($session ?? $this->desktop, $buffers, $this->disk->path('shop'));
    }

    private function pending(?DesktopSession $session = null): ?string
    {
        return DesktopRecovery::pending($this->disk->path('shop'), $session ?? $this->desktop);
    }

    public function testUnsavedWorkComesBackWhenTheProjectOpensAgain(): void
    {
        $this->recovery($this->edited())->keep();

        $directory = $this->pending();
        $this->assertNotNull($directory);
        $restored = new BufferManager();
        $this->assertSame([], new RestartSnapshot()->restore($directory, $this->disk->path('shop'), $restored));
        $this->assertStringContainsString('unsaved', $restored->get($this->disk->path('shop/a.php'))?->document->getText() ?? '');
        $this->assertSame("<?php\n", \file_get_contents($this->disk->path('shop/a.php')), 'the project file is left alone');
    }

    public function testACopyIsKeptWhileTheSessionRunsSoACrashLosesLittle(): void
    {
        $recovery = $this->recovery($this->edited());

        $recovery->preserve();
        $recovery->preserve();

        $this->assertCount(1, (array) \glob($this->disk->path('home').'/.porta/recovery/*/*/session.json'), 'each copy replaces the last');
    }

    public function testEndingOnPurposeForgetsTheCopy(): void
    {
        $recovery = $this->recovery($this->edited());
        $recovery->preserve();

        $recovery->stop();

        $this->assertNull($this->pending(), 'work discarded on purpose does not return');
    }

    public function testAHangupKeepsTheCopyThroughTheTeardown(): void
    {
        $recovery = $this->recovery($this->edited());

        $recovery->keep();
        $recovery->stop();

        $this->assertNotNull($this->pending());
    }

    public function testSavingEverythingLeavesNoCopy(): void
    {
        $buffers = $this->edited();
        $recovery = $this->recovery($buffers);
        $recovery->preserve();

        $buffers->get($this->disk->path('shop/a.php'))?->document->markSaved();
        $recovery->preserve();

        $this->assertNull($this->pending());
    }

    public function testTheCopyIsTakenOnItsOwnWhileTheSessionRuns(): void
    {
        $recovery = new DesktopRecovery($this->desktop, $this->edited(), $this->disk->path('shop'), everySeconds: 0.05);

        $recovery->start();
        $recovery->start();
        $this->pump(0.2);

        $this->assertNotNull($this->pending());
        $recovery->stop();
        $this->assertNull($this->pending());
    }

    public function testTwoWindowsEndedTogetherKeepBoth(): void
    {
        $this->recovery($this->edited())->keep();
        $this->recovery($this->edited())->keep();

        $first = $this->pending();
        $this->assertNotNull($first);
        new RestartSnapshot()->acknowledge($first);
        $second = $this->pending();
        $this->assertNotNull($second, 'the other window\'s work is still there');
        $this->assertNotSame($first, $second);
    }

    public function testNothingUnsavedKeepsNothing(): void
    {
        $this->recovery(new BufferManager())->keep();

        $this->assertNull($this->pending());
    }

    public function testATerminalLaunchNeitherCopiesNorResumes(): void
    {
        $terminal = new DesktopSession(active: false, token: '');
        $recovery = $this->recovery($this->edited(), $terminal);

        $recovery->start();
        $recovery->keep();
        $this->assertNull($this->pending());

        $this->recovery($this->edited())->keep();
        $this->assertNull($this->pending($terminal));
    }

    public function testAnotherProjectDoesNotResumeThisOne(): void
    {
        $this->recovery($this->edited())->keep();

        $this->assertNull(DesktopRecovery::pending($this->disk->path('home'), $this->desktop));
    }
}
