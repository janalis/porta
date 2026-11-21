<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\TestCase;
use Porta\Desktop\DesktopSession;
use Porta\Desktop\ProjectPicker;
use Porta\Highlight\ColorMode;
use Porta\History\RecentProjectsStore;
use Porta\I18n\Translator;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Role;
use Porta\Ui\Theme\Themes;
use Porta\Workbench\RecentProjectChoices;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/** The first screen of an icon click, driven with real keys on a virtual terminal. */
final class ProjectPickerTest extends TestCase
{
    use PreservesPortaHome;

    private const string DOWN = "\x1b[B";

    private const string SILENT = "\0silent:";

    /** The window's secret, as a host makes one. */
    private const string SESSION = '0123456789abcdef0123456789abcdef';

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
     * @param \Closure(list<string>): list<array{label: string, hint: string, value: string}> $found
     * @param list<string>                                                                    $keys
     */
    private function pickWith(\Closure $found, array $keys): ?string
    {
        $this->type($keys);

        return new ProjectPicker(new RecentProjectChoices(new RecentProjectsStore()), $this->terminal, found: $found)->pick();
    }

    /** What was drawn, its styling, cursor moves and the window's frames left out. */
    private function screen(): string
    {
        return AnsiUtils::stripAnsiCodes(self::withoutWindowFrames($this->terminal->getOutput()));
    }

    private static function withoutWindowFrames(string $output): string
    {
        return (string) \preg_replace('/\x1b\]7777;[^\x1b]*\x1b\\\\/', '', $output);
    }

    /**
     * Each key once the screen has drawn what the previous one did: a key
     * pressed before the picker is up would be a key nobody could press.
     * A key {@see silent()} draws nothing, so the next goes a turn after it.
     *
     * @param list<string> $keys
     */
    private function type(array $keys): void
    {
        $drawn = 0;
        $wait = true;
        EventLoop::repeat(0.005, function (string $id) use (&$keys, &$drawn, &$wait): void {
            $output = $this->terminal->getOutput();
            // A frame, not the cursor bookkeeping before it nor a word to the
            // window: some text has been drawn since.
            if ($wait && 1 !== \preg_match('/\pL{3}/u', self::withoutWindowFrames(\substr($output, $drawn)))) {
                return;
            }
            $drawn = \strlen($output);
            $key = (string) \array_shift($keys);
            $wait = !\str_starts_with($key, self::SILENT);
            $this->terminal->simulateInput($wait ? $key : \substr($key, \strlen(self::SILENT)));
            if ([] === $keys) {
                EventLoop::cancel($id);
            }
        });
    }

    /** $key, marked as one that draws nothing for {@see type()} to wait for. */
    private static function silent(string $key): string
    {
        return self::SILENT.$key;
    }

    private function recordRecent(): void
    {
        $store = new RecentProjectsStore();
        $store->record($this->disk->path('two'));
        $store->record($this->disk->path('one'));
    }

    /**
     * $text pasted into the field, whatever it held. Whole, not a key at a
     * time: {@see type()} waits for a frame with letters in it, and a field
     * one letter longer may redraw none.
     */
    private function typed(string $text): string
    {
        return "\x15\x1b[200~".$text."\x1b[201~";
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

        $this->assertSame($real, $this->pick([$this->typed($this->disk->path('typed')), "\r"]));
    }

    public function testWhatIsNotAFolderIsRefusedAndAskedAgain(): void
    {
        $real = (string) \realpath($this->disk->path('typed'));

        $picked = $this->pick([$this->typed($this->disk->path('a-file')), "\r", $this->typed($this->disk->path('typed')), "\r"]);

        $this->assertSame($real, $picked);
        // The path, cut to the card: it is long, under the system's temp folder.
        $this->assertStringContainsString('Not a folder: '.\substr($this->disk->path('a-file'), offset: 0, length: 20), $this->screen());
    }

    public function testEscapeOnAFirstRunLeaves(): void
    {
        $this->assertNull($this->pick(["\x1b"]));
    }

    public function testAFirstRunOffersTheProjectsFoundOnThisComputer(): void
    {
        $found = $this->disk->path('typed');

        $this->assertSame($found, $this->pickWith(static fn (array $except): array => [['label' => 'typed', 'hint' => '', 'value' => $found]], ["\r"]));
        $this->assertStringContainsString('Found on this computer', $this->screen());
    }

    public function testTheRecentProjectsComeFirstAndAreNotFoundAgain(): void
    {
        $this->recordRecent();
        $asked = [];
        $found = function (array $except) use (&$asked): array {
            $asked = $except;

            return [['label' => 'typed', 'hint' => '', 'value' => $this->disk->path('typed')]];
        };

        $this->assertSame($this->disk->path('typed'), $this->pickWith($found, [self::DOWN, self::DOWN, "\r"]));
        $this->assertSame([$this->disk->path('one'), $this->disk->path('two')], $asked);
    }

    public function testANameNarrowsTheList(): void
    {
        $this->recordRecent();

        $this->assertSame($this->disk->path('two'), $this->pick([$this->typed('tw'), "\r"]));
    }

    public function testANameThatMatchesNothingSaysSoAndEnterDoesNothing(): void
    {
        $this->recordRecent();

        // Enter and a letter in one chunk: an Enter that does nothing draws
        // nothing to wait for. Had it picked, the letter would never land.
        $this->assertNull($this->pick([$this->typed('zz'), "\rq", "\x1b"]));
        $this->assertStringContainsString('No project is called that', $this->screen());
        $this->assertStringNotContainsString('Not a folder', $this->screen());
    }

    public function testTabCompletesATypedFolder(): void
    {
        $real = (string) \realpath($this->disk->path('typed'));

        $this->assertSame($real, $this->pick([$this->typed($this->disk->path('ty')), "\t", "\r"]));
    }

    public function testTheFoldersATypedPathCouldNameAreListedAndOneOpens(): void
    {
        $real = (string) \realpath($this->disk->path('two'));

        // `t` names neither folder: the list holds two/ and typed/, natural order.
        $this->assertSame($real, $this->pick([$this->typed($this->disk->path('t')), "\r"]));
    }

    public function testTabOnAProjectTurnsItIntoItsPath(): void
    {
        $this->recordRecent();
        $real = (string) \realpath($this->disk->path('one'));

        $this->assertSame($real, $this->pick(["\t", "\r"]));
    }

    public function testTabOnAProjectEndsItsPathInASlashToGoOnFrom(): void
    {
        $this->recordRecent();

        $this->assertNull($this->pick(["\t", 'x', "\x1b"]));
        $this->assertStringContainsString('one/x', $this->screen());
        $this->assertStringNotContainsString('onex', $this->screen());
    }

    public function testAServersProjectOpensFromTheFolderThatMirrorsIt(): void
    {
        $picked = (string) $this->pick([$this->typed('ssh://deploy@example.com/srv/app'), "\r"]);

        $this->assertStringStartsWith((string) \realpath($this->disk->path('home')), $picked);
        $this->assertSame('ssh://deploy@example.com/srv/app', \json_decode((string) \file_get_contents($picked.'/.porta/remote.json'), associative: true)['url'] ?? null);
    }

    public function testWithoutAHomeAServersProjectHasNowhereToBeMirroredAndIsRefused(): void
    {
        $home = (string) \getenv('HOME');
        \putenv('PORTA_HOME');
        \putenv('HOME');
        try {
            $this->assertNull($this->pick([$this->typed('ssh://deploy@example.com/srv/app'), "\r", "\x1b"]));
        } finally {
            \putenv('HOME='.$home);
        }
        $this->assertStringContainsString('Not a folder: ssh://deploy@example.com/srv/app', $this->screen());
    }

    public function testAPathCopiedWithSpacesAroundItStillOpens(): void
    {
        $real = (string) \realpath($this->disk->path('typed'));

        $this->assertSame($real, $this->pick([$this->typed('  '.$this->disk->path('typed').' '), "\r"]));
    }

    public function testAWindowsPathOrADriveLetterIsNoServer(): void
    {
        $this->assertNull($this->pick([$this->typed('C:\\Users\\me\\shop'), "\x1b"]));
        $this->assertNull($this->pick([$this->typed('C:shop'), "\x1b"]));
        $this->assertStringNotContainsString('Remote project', $this->screen());
    }

    public function testAFolderHereWhoseNameHasAColonIsNoServer(): void
    {
        \mkdir($this->disk->path('host:dir'));
        $previous = (string) \getcwd();
        \chdir($this->disk->path());
        try {
            $this->assertNull($this->pick([$this->typed('host:dir'), "\x1b"]));
        } finally {
            \chdir($previous);
        }
        $this->assertStringNotContainsString('Remote project', $this->screen());
    }

    public function testAMirrorThatCannotBeMadeIsSaidAndThePickerStays(): void
    {
        // ~/.porta is a file: nothing can be made under it.
        $this->disk->write('home/.porta', '');

        $this->assertNull($this->pick([$this->typed('ssh://deploy@example.com/srv/app'), "\r", "\x1b"]));
        $this->assertStringContainsString('Failed to create', $this->screen());
    }

    public function testTheProjectsAreLookedForOnceNotOnEveryKey(): void
    {
        $asked = 0;
        $found = static function (array $except) use (&$asked): array {
            ++$asked;

            return [];
        };

        $this->assertNull($this->pickWith($found, [$this->typed('abc'), $this->typed('abcd'), $this->typed('abcde'), "\x1b"]));
        $this->assertSame(1, $asked);
    }

    public function testOpenRemoteAsksForTheServersLocation(): void
    {
        // A first run with nothing found: the remote row is the one to choose.
        $this->assertNull($this->pick(["\r", "\x1b"]));
        $this->assertStringContainsString('ftps:// URL', $this->screen());
        $this->assertStringContainsString('Type the path of a folder to open', $this->screen(), 'a first run says how to name a folder');
        $this->assertStringContainsString('Tab complete · Enter open', $this->screen());
    }

    public function testTheFoldersOfATypedPathEndInASlashAndSayWhichAreProjects(): void
    {
        $this->disk->write('typed/composer.json', '{}');

        $this->assertNull($this->pick([$this->typed($this->disk->path('t')), "\x1b"]));
        $this->assertMatchesRegularExpression('/two\/\s+typed\/\s+PHP project/', $this->screen());
    }

    public function testTabWithNothingToCompleteLeavesTheFieldAlone(): void
    {
        $this->assertNull($this->pick([$this->typed('zz'), self::silent("\t"), 'q', "\x1b"]));
        $this->assertStringContainsString('┃ zzq', $this->screen());
    }

    public function testANameMatchesWhateverItsCase(): void
    {
        $found = static fn (array $except): array => [['label' => 'École', 'hint' => '', 'value' => '/école']];

        $this->assertSame('/école', $this->pickWith($found, [$this->typed('éc'), "\r"]));
    }

    public function testAFolderTypedInFullIsOfferedOnce(): void
    {
        $this->assertNull($this->pick([$this->typed($this->disk->path('typed')), "\x1b"]));
        $this->assertStringContainsString('Open this folder', $this->screen());
        $this->assertStringNotContainsString('typed/', $this->screen(), 'not again as a folder it could go on to name');
    }

    public function testWhatFollowsStartsOnAClearScreen(): void
    {
        $this->assertNull($this->pick(["\x1b"]));
        $this->assertStringEndsWith("\x1b[?1000l\x1b[?1006l\x1b[H\x1b[2J\x1b[3J", $this->terminal->getOutput(), 'no click reports, a clear screen and scrollback');
    }

    public function testTabGoesIntoAFolderTypedInFullThatBeginsAnothersName(): void
    {
        \mkdir($this->disk->path('one-two'));

        // `one` names a folder and begins `one-two`: Tab on "Open this folder" goes into one.
        $this->assertNull($this->pick([$this->typed($this->disk->path('one')), "\t", 'x', "\x1b"]));
        $this->assertStringContainsString('one/x', $this->screen());
    }

    public function testAFolderThatIsTheSameOnDiskUnderAnotherNameIsStillOffered(): void
    {
        \symlink($this->disk->path('typed'), $this->disk->path('typed-alias'));

        $this->assertNull($this->pick([$this->typed($this->disk->path('typed')), "\x1b"]));
        $this->assertStringContainsString('typed-alias/', $this->screen());
    }

    public function testAPickerThatFailsStillStopsTheClickReports(): void
    {
        $found = static function (array $except): array {
            throw new \RuntimeException('the disk went away');
        };

        try {
            new ProjectPicker(new RecentProjectChoices(new RecentProjectsStore()), $this->terminal, found: $found)->pick();
            $this->fail('the failure is not swallowed');
        } catch (\RuntimeException) {
            $this->assertStringEndsWith("\x1b[?1000l\x1b[?1006l\x1b[H\x1b[2J\x1b[3J", $this->terminal->getOutput());
        }
    }

    public function testTheCardNamesPortaAndItsVersion(): void
    {
        $this->type(["\x1b"]);
        new ProjectPicker(new RecentProjectChoices(new RecentProjectsStore()), $this->terminal, version: '9.9.9')->pick();

        $this->assertStringContainsString('Porta 9.9.9', $this->screen());
    }

    /** @param list<string> $keys */
    private function pickInAWindow(array $keys): ?string
    {
        $this->type($keys);

        return new ProjectPicker(new RecentProjectChoices(new RecentProjectsStore()), $this->terminal, session: new DesktopSession(active: true, token: self::SESSION))->pick();
    }

    private static function frame(string $message, #[\SensitiveParameter] string $token = self::SESSION): string
    {
        return "\x1b]7777;porta-desktop;{$token};{$message}\x1b\\";
    }

    public function testInAWindowThePickerSaysItIsWaitingForAProject(): void
    {
        $this->assertNull($this->pickInAWindow(["\x1b"]));
        $this->assertStringContainsString(self::frame('picking'), $this->terminal->getOutput());
        $this->assertStringContainsString(self::frame('picked'), $this->terminal->getOutput(), 'leaving says the picker is done too');
        $this->assertStringContainsString('or drop a folder on this window', $this->screen());
    }

    public function testOutsideAWindowItSaysNothingAndOffersNoDialog(): void
    {
        $this->assertNull($this->pick(["\x1b"]));
        $this->assertStringNotContainsString('porta-desktop', $this->terminal->getOutput());
        $this->assertStringContainsString("\x1b[?1000h\x1b[?1006h", $this->terminal->getOutput(), 'clicks are reported while it is up');
        $this->assertGreaterThan(
            (int) \strrpos($this->terminal->getOutput(), "\x1b[?1000h"),
            (int) \strrpos($this->terminal->getOutput(), "\x1b[?1000l\x1b[?1006l"),
            'and no longer once it is done',
        );
        $this->assertStringNotContainsString('Choose a folder', $this->screen());
    }

    public function testChooseAFolderAsksTheWindowForItsDialog(): void
    {
        // A first run, nothing found: the dialog is the first row. The letter
        // rides with Enter, which draws nothing of its own to wait for.
        $this->assertNull($this->pickInAWindow(["\rq", "\x1b"]));
        $this->assertStringContainsString(self::frame('chooseFolder'), $this->terminal->getOutput());
    }

    public function testAFolderTheWindowOpensIsTheProject(): void
    {
        $folder = $this->disk->path('typed');

        $this->assertSame($folder, $this->pickInAWindow([self::frame('open;'.\base64_encode($folder))]));
        $this->assertStringContainsString(self::frame('picked'), $this->terminal->getOutput(), 'the window stops sending folders: the next is the IDE\'s');
    }

    public function testAnOpenForWhatIsNotThereOrWithAnotherTokenIsIgnoredAndNeverTyped(): void
    {
        $gone = self::frame('open;'.\base64_encode($this->disk->path('gone')));
        $forged = self::frame('open;'.\base64_encode($this->disk->path('typed')), \str_repeat('f', 32));

        $this->assertNull($this->pickInAWindow([self::silent($gone), self::silent($forged), 'q', "\x1b"]));
        $this->assertStringContainsString('┃ q ', $this->screen(), 'only the letter reached the field');
    }

    /** Porta's name in the header as $theme draws it: what tells one theme's card from another's. */
    private static function nameIn(string $theme): string
    {
        return Themes::createSwitchable(ColorMode::Ansi, $theme)->of(Role::Bright)->withBold()->apply('Porta');
    }

    /** The user's own picker, launched by a window on a $mode system, from $cwd. */
    private function forUserIn(string $cwd, string $mode): void
    {
        $previous = (string) \getcwd();
        \chdir($cwd);
        \putenv(DesktopSession::FLAG.'=1');
        \putenv(DesktopSession::TOKEN.'='.self::SESSION);
        \putenv(DesktopSession::APPEARANCE.'='.$mode);
        try {
            $this->type(["\x1b"]);
            ProjectPicker::forUser(terminal: $this->terminal)->pick();
        } finally {
            \chdir($previous);
            \putenv(DesktopSession::FLAG);
            \putenv(DesktopSession::TOKEN);
            \putenv(DesktopSession::APPEARANCE);
        }
    }

    public function testTheWindowsModeIsFollowedWhenNoThemeWasChosen(): void
    {
        $this->forUserIn($this->disk->path('one'), 'light');

        $this->assertStringContainsString(self::nameIn('light'), $this->terminal->getOutput());
        $this->assertStringNotContainsString(self::nameIn('dark'), $this->terminal->getOutput());
    }

    public function testAChosenThemeOutranksTheWindowsMode(): void
    {
        $this->disk->write('one/.porta.json', '{"settings": {"theme": "light"}}');

        $this->forUserIn($this->disk->path('one'), 'dark');

        $this->assertStringContainsString(self::nameIn('light'), $this->terminal->getOutput());
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
