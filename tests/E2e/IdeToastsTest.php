<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The corner toast surface end-to-end: a real notification renders as a
 * framed card in the workspace's top-right corner, the × dismisses it through
 * the mouse router, a hover only tints, and the auto-expiry timer clears the
 * stack without outliving the loop.
 */
final class IdeToastsTest extends TestCase
{
    use RunsIde;

    public function testAFlashRendersAsACornerCardDismissableByItsClose(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tui->handleInput('X'); // dirty
                    $tui->handleInput("\x13"); // ctrl+s → "Saved a.php"
                    $tui->tick();

                    $this->assertStringContainsString('Saved a.php', Toasts::last($ide));
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Saved a.php', $screen, 'the card text reaches the terminal');
                    $this->assertStringContainsString('✓ ', $screen, 'the success glyph on the card border — a save is a success');
                },
                400 => function ($tui, $terminal, $ide): void {
                    // The presenter's regions place the card; its × sits at the
                    // top border's width − 2 (ModalBox::closeHit).
                    $notifier = Services::resolve($ide, Notifier::class);
                    $center = \Porta\Tests\Support\Probe::get($notifier, 'toasts');
                    $boxes = new \ReflectionProperty($center, 'boxes')->getValue($center);
                    $this->assertNotSame([], $boxes, 'the presenter recorded the card box');
                    $box = $boxes[0]['box'];

                    // Hover the body first: swallowed, nothing dismissed.
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $box->col + 3, $box->startRow + 2));
                    $this->assertNotSame([], Toasts::all($ide), 'a body hover dismisses nothing');

                    // Hover then click the ×.
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $box->col + $box->width - 2, $box->startRow + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $box->col + $box->width - 2, $box->startRow + 1));
                    $this->assertSame([], Toasts::all($ide), 'the × dismissed the toast');
                },
                500 => function ($tui, $terminal, $ide): void {
                    // A short-lived toast expires on its own timer.
                    $notifier = Services::resolve($ide, Notifier::class);
                    $notifier->error('boom', 0.15);
                    $this->assertSame(['boom'], Toasts::all($ide));
                    $tui->tick(); // paint the card before sampling the terminal
                    $this->assertStringContainsString('✗', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the error glyph renders');
                    $this->await(static fn (): bool => [] === Toasts::all($ide));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
