<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Previews;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The graph drawn by a terminal that answered the kitty graphics query, in a
 * real session: the probe, the placeholders, and an app menu opened over the
 * picture — which is where both failures were: a dropdown cut short and padded
 * blank (placeholders measured three columns wide), and a closed dropdown left
 * painted on the pane (registered panes missing from the overlay clear).
 */
final class IdePixelGraphicsTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    private const string PLACEHOLDER = "\u{10EEEE}";

    public function testAMenuOverThePictureDrawsWholeAndLeavesNothingBehind(): void
    {
        $fakeDot = \dirname(__DIR__, levels: 3).'/tests/Support/fake-dot';
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => ['graph-viewer', 'image-viewer'], 'plugins' => ['graph-viewer' => ['dotPath' => $fakeDot]]], \JSON_THROW_ON_ERROR),
            'g.dot' => "digraph G { A -> B; }\n",
        ]);
        $bin = $project->path('.php-only-bin');
        \mkdir($bin);
        \symlink(\PHP_BINARY, $bin.'/php');
        \putenv('PATH='.$bin);

        $this->runIde(
            $project,
            $project->path('g.dot'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Previews::alone($ide); // the picture across the whole editor area, as the viewer tab was
                    $this->await(static fn (): bool => \str_contains($terminal->getOutput(), "\x1b_Gi=31,"), what: 'the graphics probe');
                    $terminal->simulateInput("\x1b_Gi=31;OK\x1b\\\x1b[6;16;8t\x1b[?62;22c");
                    $this->awaitPaintedRaw($tui, $terminal, self::PLACEHOLDER);
                    $this->assertStringContainsString("\x1b_Ga=t,f=100,", $terminal->getOutput(), 'the view is sent as an image');

                    $tui->handleInput("\x1b[21~"); // F10 arms the bar
                    for ($i = 0; $i < 6; ++$i) {
                        $tui->handleInput("\x1b[C"); // → View, which drops down over the middle of the picture
                    }
                    $tui->handleInput("\r");
                    $this->awaitPainted($tui, $terminal, 'Soft-Wrap');
                    $crossed = \array_values(\array_filter(
                        $this->screenGrid($tui, $terminal),
                        static fn (string $row): bool => \str_contains($row, self::PLACEHOLDER) && \str_contains($row, '┃'),
                    ));
                    $this->assertNotSame([], $crossed, 'the dropdown crosses the picture');
                    foreach ($crossed as $row) {
                        $this->assertSame(2, \substr_count($row, needle: '┃'), 'both borders survive a row of picture: '.$row);
                    }
                    // Whichever items the picture happens to cross, one of them
                    // carries a shortcut at the box's far edge — the cell a
                    // mismeasured placeholder row used to cut off.
                    $this->assertMatchesRegularExpression('/Alt\+[0-9A-Z]/', \implode("\n", $crossed), 'a shortcut at the dropdown\'s far edge is still drawn');

                    $tui->handleInput("\x1b");
                    $tui->handleInput("\x1b");
                    $this->frame($tui);
                    $grid = \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->assertStringNotContainsString('Soft-Wrap', $grid, 'the closed dropdown is gone from the picture');
                    $this->assertStringContainsString(self::PLACEHOLDER, $grid);
                },
            ],
            columns: 140,
            rows: 30,
        );
    }
}
