<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Presence\Beacon;
use Porta\Presence\PresenceBoard;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Another Porta on the same folder, end to end: what it left on the board
 * becomes a named caret in the editor and a count in the status bar, this
 * one leaves its own beside it — and takes it back on the way out.
 */
final class IdeParticipantsTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    private const string ADA = 'aaaaaaaaaaaaaaaa';

    public function testAnotherInstanceIsShownAndThisOneSaysWhereItIs(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n\$b = 2;\n"]);
        $board = $project->path(PresenceBoard::DIR);
        $project->write(PresenceBoard::DIR.'/'.self::ADA.'.json', new Beacon(self::ADA, 'Ada', 'local', 'a.php', 1, 0, -1, 0, \time())->toJson());

        $this->runIde($project, $project->path('a.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal) use ($board): void {
                $this->awaitPainted($tui, $terminal, Glyphs::AGENT.' Ada');
                $this->awaitPainted($tui, $terminal, Glyphs::PARTICIPANTS.' 1');
                $this->await(static fn (): bool => 2 === \count((array) \glob($board.'/*.json')), what: 'this side\'s own beacon');
                $mine = \array_values(\array_filter((array) \glob($board.'/*.json'), static fn (string $file): bool => !\str_contains($file, self::ADA)));
                $beacon = Beacon::fromJson((string) \file_get_contents($mine[0]));
                $this->assertSame('a.php', $beacon?->path, 'the file open here, relative: the others\' roots are not this one');
            },
            200 => function (Tui $tui, VirtualTerminal $terminal) use ($board): void {
                \unlink($board.'/'.self::ADA.'.json');
                $screen = '';
                $this->await(function () use ($tui, $terminal, &$screen): bool {
                    $this->frame($tui);
                    $screen = \implode("\n", $this->screenGrid($tui, $terminal));

                    return !\str_contains($screen, Glyphs::PARTICIPANTS.' 1');
                }, what: 'Ada gone from the status bar');
                $this->assertStringNotContainsString(Glyphs::AGENT.' Ada', $screen, 'and her caret with her');
            },
        ]);

        $this->assertSame([], \glob($board.'/*.json'), 'quitting takes this side off the board');
    }
}
