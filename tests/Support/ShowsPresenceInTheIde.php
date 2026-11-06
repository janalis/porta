<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Assert;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Filesystem\ProjectLocation;
use Porta\Ide;
use Porta\Presence\Beacon;
use Porta\Presence\Participant;
use Porta\Presence\Participants;
use Porta\Presence\PresenceBoard;
use Porta\Presence\PresenceSync;
use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * {@see ExercisesPresence}, with the IDE on screen: the same server, a
 * headless Porta at work there, and the real IDE opened on the project
 * through the plugin that serves its scheme.
 */
trait ShowsPresenceInTheIde
{
    use ExercisesPresence;
    use RunsIde;

    /**
     * The real IDE, opened on the server's project through its plugin: Ada,
     * at work there from another Porta, is drawn in its editor by name and
     * counted in its status bar; it says where its own user is on the same
     * server, where Ada finds them; and a server that goes away and comes
     * back is shown as such. Needs {@see PreservesPortaHome} on the class.
     *
     * @param array<string, mixed> $portaJson the project's `.porta.json`: the plugin enabled, and how to reach the server
     */
    private function assertTheIdeShowsWhoElseIsThere(DummyServer $server, string $url, array $portaJson, PresencePeer $ada): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $ada->at('src/A.php', 1);
        $this->look($ada);
        $cache = new TempProject([
            ProjectLocation::MARKER => \json_encode(['url' => $url], \JSON_THROW_ON_ERROR),
            '.porta.json' => \json_encode($portaJson + ['settings' => ['displayName' => 'Hal']], \JSON_THROW_ON_ERROR),
        ]);
        $board = $server->root.'/'.PresenceBoard::DIR;
        $look = function (Ide $ide): void {
            $sync = Services::resolve($ide, PresenceSync::class);
            $this->await(static fn (): bool => true !== Probe::get($sync, 'busy'), what: 'the IDE\'s last look');
            $sync->tick();
        };

        $this->runIde($cache, null, [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($cache, $board, $ada, $look): void {
                $this->await(static fn (): bool => \is_file($cache->path('src/A.php')), timeout: 20.0, what: 'the server\'s tree in the cache');
                Services::resolve($ide, BufferService::class)->openFile($cache->path('src/A.php'), focus: OpenFocus::Editor);
                $this->awaitPainted($tui, $terminal, Glyphs::AGENT.' Ada', 20.0);
                $this->awaitPainted($tui, $terminal, Glyphs::PARTICIPANTS.' 1');
                $look($ide); // the file just opened is news: said at this look, not at the next timer's

                $hal = null;
                $this->await(
                    static function () use ($board, &$hal): bool {
                        foreach ((array) \glob($board.'/*.json') as $file) {
                            $beacon = Beacon::fromJson((string) \file_get_contents((string) $file));
                            $hal = 'Hal' === $beacon?->name ? $beacon : $hal;
                        }

                        return null !== $hal && 'src/A.php' === $hal->path;
                    },
                    timeout: 20.0,
                    what: 'the IDE\'s own beacon on the server, at its file',
                );
                $this->await(function () use ($ada): bool {
                    $this->look($ada);

                    return \array_key_exists('Hal', $ada->sees());
                }, what: 'Ada to see the IDE\'s user');
                Assert::assertSame('src/A.php', $hal?->path, 'the file open in the IDE, relative: Ada\'s copy is elsewhere');
                Assert::assertSame(['Ada'], \array_map(static fn (Participant $p): string => $p->name, Services::resolve($ide, Participants::class)->all()));
            },
            200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($server, $look): void {
                $server->stop();
                $look($ide);
                $this->awaitPainted($tui, $terminal, Glyphs::PARTICIPANTS.' 1 '.Glyphs::OUT_OF_TOUCH, 20.0);
                Assert::assertArrayHasKey(PresenceSync::SOURCE, Probe::array(Services::resolve($ide, Participants::class), 'lost'), 'Ada kept, as last seen');
            },
            300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($server, $look): void {
                $server->start();
                $look($ide);
                $this->await(
                    function () use ($tui, $terminal): bool {
                        $this->frame($tui);
                        $screen = AnsiUtils::stripAnsiCodes(\implode("\n", $this->screenGrid($tui, $terminal)));

                        return !\str_contains($screen, Glyphs::OUT_OF_TOUCH) && \str_contains($screen, Glyphs::AGENT.' Ada');
                    },
                    timeout: 20.0,
                    what: 'Ada back, in touch, her caret drawn again',
                );
                Assert::assertArrayNotHasKey(PresenceSync::SOURCE, Probe::array(Services::resolve($ide, Participants::class), 'lost'));
            },
            // Ada closes her file — her caret goes — and then quits, so that
            // nothing else changes on screen: the status bar repaints only
            // what is marked dirty, and the roster has to mark it.
            400 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($ada, $look): void {
                $ada->buffers->close((string) $ada->buffers->active()?->path);
                $this->look($ada);
                $look($ide);
                $this->await(
                    function () use ($tui, $terminal): bool {
                        $this->frame($tui);

                        return !\str_contains(AnsiUtils::stripAnsiCodes(\implode("\n", $this->screenGrid($tui, $terminal))), Glyphs::AGENT.' Ada');
                    },
                    timeout: 20.0,
                    what: 'Ada\'s caret gone with her file',
                );
                $ada->sync->stop();
                $look($ide);
                $this->await(
                    function () use ($tui, $terminal): bool {
                        $this->frame($tui);

                        return !\str_contains(AnsiUtils::stripAnsiCodes(\implode("\n", $this->screenGrid($tui, $terminal))), Glyphs::PARTICIPANTS);
                    },
                    timeout: 20.0,
                    what: 'the participant count gone from the status bar once Ada quit',
                );
                Assert::assertSame([], Services::resolve($ide, Participants::class)->all());
            },
        ]);

        $names = [];
        foreach ((array) \glob($board.'/*.json') as $file) {
            $names[] = Beacon::fromJson((string) \file_get_contents((string) $file))?->name;
        }
        Assert::assertSame([], $names, 'quitting took the IDE\'s user off the server, before the session ended — Ada had gone already');
    }
}
