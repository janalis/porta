<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Overlay;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Overlay\OverlayHost;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

final class OverlayHostTest extends TestCase
{
    private int $invalidations = 0;

    private function host(): OverlayHost
    {
        $this->invalidations = 0;

        return new OverlayHost(function (): void {
            ++$this->invalidations;
        });
    }

    private function keybindings(): Keybindings
    {
        return new Keybindings([
            'select_cancel' => [Key::ESCAPE],
            'accept_completion' => [Key::ENTER],
            'cursor_up' => [Key::UP],
            'cursor_down' => [Key::DOWN],
            'recent_files' => ['ctrl+e'],
            'delete_char_backward' => [Key::BACKSPACE],
        ]);
    }

    public function testOpenListIgnoresAnEmptyList(): void
    {
        $host = $this->host();
        $host->openList('Empty', [], static fn () => null);
        $this->assertFalse($host->isListOpen());
        $this->assertSame(0, $this->invalidations);
    }

    public function testInputFallsThroughWhenNothingIsOpen(): void
    {
        $host = $this->host();
        $this->assertFalse($host->handleInput('x', $this->keybindings()));
        $this->assertFalse($host->clickAt(0, 0));
        $this->assertFalse($host->hoverAt(0, 0));
    }

    public function testPromptWinsOverTheListOverlay(): void
    {
        $host = $this->host();
        $picked = null;
        $submitted = null;
        $host->openList('L', [['label' => 'a', 'hint' => '', 'value' => 'A']], function (string $v) use (&$picked): void {
            $picked = $v;
        });
        $host->openPrompt('Rename to', 'old', function (string $v) use (&$submitted): void {
            $submitted = $v;
        });

        // Enter submits the prompt, not the overlay pick.
        $this->assertTrue($host->handleInput("\r", $this->keybindings()));
        $this->assertSame('old', $submitted);
        $this->assertNull($picked);
        $this->assertTrue($host->isListOpen(), 'the list overlay survives the prompt');
    }

    public function testCloseListLeavesThePromptOpen(): void
    {
        $host = $this->host();
        $host->openPrompt('Go to line', '', static fn () => null);
        $host->openList('L', [['label' => 'a', 'hint' => '', 'value' => 'A']], static fn () => null);

        $host->closeList();
        $this->assertFalse($host->isListOpen());
        // The prompt still swallows and edits input.
        $this->assertTrue($host->handleInput('4', $this->keybindings()));
        $lines = $host->composite(['', ''], 40, 2, true);
        $this->assertStringContainsString('Go to line: 4', $lines[0]);
    }

    public function testPromptBackspaceAndCancel(): void
    {
        $host = $this->host();
        $submitted = null;
        $host->openPrompt('P', 'ab', function (string $v) use (&$submitted): void {
            $submitted = $v;
        });
        $kb = $this->keybindings();

        $host->handleInput("\x7f", $kb); // backspace → 'a'
        $host->handleInput("\x7f", $kb); // backspace → ''
        $host->handleInput("\x7f", $kb); // backspace on empty: no-op
        $host->handleInput("\x1b", $kb); // escape cancels without submitting
        $this->assertNull($submitted);
        $this->assertFalse($host->handleInput('x', $kb), 'prompt closed, input falls through');
    }

    public function testCompositeRecordsTheBoxForHitTesting(): void
    {
        $host = $this->host();
        $host->openList(
            'Title',
            [
                ['label' => 'a', 'hint' => '', 'value' => 'A'],
                ['label' => 'b', 'hint' => '', 'value' => 'B'],
            ],
            static fn () => null,
        );

        $host->composite(array_fill(0, 20, str_repeat(' ', 80)), 80, 20, true);

        // Hovering the second item row moves the selection exactly once.
        $moved = false;
        for ($row = 0; $row < 20 && !$moved; ++$row) {
            $moved = $host->hoverAt($row, 40);
        }
        $this->assertTrue($moved, 'some row hover-selects the second item');
        $this->assertFalse($host->hoverAt($row - 1, 40), 'same-row hover is a no-op');
    }
}
