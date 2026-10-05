<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Overlay\State\ToastSeverity;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Every floating surface casts its shadow bottom-right on screen, mirrored
 * layout or not.
 *
 * A mirrored surface is flipped across the row after it is composed, so its
 * shadow has to be cast the other way first. Cast to the right as usual, it
 * came out bottom-left on the toasts and the Welcome modal; on the context
 * menu and every app menu but the one at the edge it fell outside the cells
 * the flip claims and stayed behind as a stray column in the pane beneath.
 */
final class IdeShadowTest extends TestCase
{
    use RunsIde;
    use ScreenReads;

    /** @return iterable<string, array{string, string}> */
    public static function surfaceProvider(): iterable
    {
        foreach (['app menu', 'context menu', 'toast', 'welcome', 'find in files', 'picker'] as $surface) {
            foreach (['en', 'he'] as $locale) {
                yield "{$surface}, {$locale}" => [$surface, $locale];
            }
        }
    }

    /**
     * Each shadow cell sits diagonally below-right of the box or of more
     * shadow: its upper-left neighbour is a box-drawing glyph or a shadow
     * cell. A shadow on the left, or a stray column, has pane text there.
     */
    #[DataProvider('surfaceProvider')]
    public function testTheShadowFallsBottomRight(string $surface, string $locale): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['settings' => ['language' => $locale, 'theme' => 'dark']], \JSON_THROW_ON_ERROR),
            'demo.php' => "<?php\n".\str_repeat("echo 'abcdefghij klmnop';\n", times: 20),
        ]);

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($surface): void {
                    $this->frame($tui); // the rects the surfaces lay out against
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    match ($surface) {
                        // A menu mid-bar: the one at the edge casts no side shadow.
                        'app menu' => \array_map($tui->handleInput(...), ["\x1b[21~", "\x1b[D", "\x1b[D", "\x1b[D", "\r"]),
                        'context menu' => $tui->handleInput("\x1b[21;5~"),
                        'toast' => Services::resolve($ide, Notifier::class)->notify('A toast with a shadow', ToastSeverity::Info, seconds: 30),
                        'welcome' => $commands->run('ide_welcome'),
                        'find in files' => $commands->run('ide_find_in_project'),
                        'picker' => $commands->run('ide_search_everywhere'),
                    };

                    $cells = [];
                    $grid = [];
                    $this->await(
                        function () use ($tui, $terminal, &$cells, &$grid): bool {
                            $grid = \array_map(\mb_str_split(...), $this->shadowGrid($tui, $terminal));
                            $cells = [];
                            foreach ($grid as $row => $glyphs) {
                                foreach (\array_keys($glyphs, '░', strict: true) as $col) {
                                    $cells[] = [$row, $col];
                                }
                            }

                            return \count($cells) >= 3;
                        },
                        what: "the {$surface} to cast its shadow",
                    );

                    foreach ($cells as [$row, $col]) {
                        $upLeft = $grid[$row - 1][$col - 1] ?? '';
                        $this->assertMatchesRegularExpression(
                            '/^[\x{2500}-\x{257F}░]$/u',
                            $upLeft,
                            "the shadow cell at {$row}:{$col} is not below-right of the box:\n".\implode("\n", \array_map(\implode(...), \array_slice($grid, \max(0, $row - 3), length: 5))),
                        );
                    }
                },
            ],
            columns: 100,
        );
    }
}
