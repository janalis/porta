<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\TestCase;
use Porta\Desktop\ProjectPicker;
use Porta\History\RecentProjectsStore;
use Porta\I18n\Translator;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\TempProject;
use Porta\Workbench\RecentProjectChoices;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/** The first screen of an icon click, driven with real keys on a virtual terminal. */
final class ProjectPickerTest extends TestCase
{
    use PreservesPortaHome;

    private const string DOWN = "\x1b[B";

    private TempProject $disk;

    private VirtualTerminal $terminal;

    #[\Override]
    protected function setUp(): void
    {
        $this->disk = new TempProject(['home/.keep' => '', 'one/a.php' => '', 'two/b.php' => '', 'typed/c.php' => '', 'a-file' => '']);
        \putenv('PORTA_HOME='.$this->disk->path('home'));
        $this->terminal = new VirtualTerminal(80, rows: 24);
    }

    /** @param list<string> $keys pressed one per loop turn, once the picker is on screen */
    private function pick(array $keys): ?string
    {
        $this->type($keys);

        return new ProjectPicker(new RecentProjectChoices(new RecentProjectsStore()), $this->terminal)->pick();
    }

    /**
     * Each key once the screen has drawn what the previous one did: a key
     * pressed before the picker is up would be a key nobody could press.
     *
     * @param list<string> $keys
     */
    private function type(array $keys): void
    {
        $drawn = 0;
        EventLoop::repeat(0.005, function (string $id) use (&$keys, &$drawn): void {
            $output = $this->terminal->getOutput();
            // A frame, not the cursor bookkeeping before it: some text has been drawn since.
            if (1 !== \preg_match('/\pL{3}/u', \substr($output, $drawn))) {
                return;
            }
            if ([] === $keys) {
                EventLoop::cancel($id);

                return;
            }
            $drawn = \strlen($output);
            $this->terminal->simulateInput((string) \array_shift($keys));
        });
    }

    private function recordRecent(): void
    {
        $store = new RecentProjectsStore();
        $store->record($this->disk->path('two'));
        $store->record($this->disk->path('one'));
    }

    private function typed(string $text): string
    {
        // Past the prefilled `~/`: the field is replaced, not appended to.
        return "\x15".$text;
    }

    public function testEnterOpensTheMostRecentProject(): void
    {
        $this->recordRecent();

        $this->assertSame($this->disk->path('one'), $this->pick(["\r"]));
        $this->assertStringContainsString('Open a project', $this->terminal->getOutput());
    }

    public function testTheArrowsReachTheOtherProjects(): void
    {
        $this->recordRecent();

        $this->assertSame($this->disk->path('two'), $this->pick([self::DOWN, "\r"]));
    }

    public function testAFoldersNameCannotDriveTheTerminal(): void
    {
        $hostile = $this->disk->path("evil\x1b]52;c;aGk=\x07name");
        \mkdir($hostile);
        new RecentProjectsStore()->record($hostile);

        $this->assertSame($hostile, $this->pick(["\r"]), 'the real path is still what opens');
        $this->assertStringNotContainsString("\x1b]52", $this->terminal->getOutput());
    }

    public function testEscapeLeavesWithoutChoosing(): void
    {
        $this->recordRecent();

        $this->assertNull($this->pick(["\x1b"]));
    }

    public function testAFolderCanBeTyped(): void
    {
        $this->recordRecent();
        $real = (string) \realpath($this->disk->path('typed'));

        $this->assertSame($real, $this->pick([self::DOWN, self::DOWN, "\r", $this->typed($this->disk->path('typed')), "\r"]));
    }

    public function testAFirstRunGoesStraightToTheFolderPrompt(): void
    {
        $real = (string) \realpath($this->disk->path('typed'));

        $this->assertSame($real, $this->pick([$this->typed($this->disk->path('typed')), "\r"]));
    }

    public function testWhatIsNotAFolderIsRefusedAndAskedAgain(): void
    {
        $real = (string) \realpath($this->disk->path('typed'));

        $picked = $this->pick([$this->typed($this->disk->path('a-file')), "\r", $this->typed($this->disk->path('typed')), "\r"]);

        $this->assertSame($real, $picked);
        $this->assertStringContainsString('Not a folder', $this->terminal->getOutput());
    }

    public function testEscapeFromTheFolderPromptGoesBackToTheList(): void
    {
        $this->recordRecent();

        $this->assertSame($this->disk->path('one'), $this->pick([self::DOWN, self::DOWN, "\r", "\x1b", "\r"]));
    }

    public function testEscapeFromAFirstRunPromptLeaves(): void
    {
        $this->assertNull($this->pick(["\x1b"]));
    }

    public function testThePickerSpeaksTheUsersLanguage(): void
    {
        $this->recordRecent();
        $this->type(["\r"]);

        new ProjectPicker(new RecentProjectChoices(new RecentProjectsStore()), $this->terminal, new Translator('fr'))->pick();

        $this->assertStringContainsString('Ouvrir un projet', $this->terminal->getOutput());
    }

    public function testTheUsersOwnPickerIsBuiltOnTheRealStore(): void
    {
        $this->assertInstanceOf(ProjectPicker::class, ProjectPicker::forUser());
    }
}
