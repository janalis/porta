<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Ui\Graphics\PixelRatio;
use Porta\Ui\Graphics\TerminalGraphics;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * A {@see TerminalGraphics} over a virtual terminal, for the viewer panes'
 * pixel path: {@see answered()} is one whose probe a kitty terminal has
 * answered, {@see unanswered()} one still waiting (the half-block path).
 * The terminal is returned too, because what the pane SENT is the assertion.
 */
final class PixelGraphics
{
    /** @return array{TerminalGraphics, VirtualTerminal} */
    public static function answered(int $cellWidth = 8, int $cellHeight = 16): array
    {
        [$graphics, $terminal] = self::unanswered();
        self::answer($graphics, "\x1b_Gi=31;OK\x1b\\", "\x1b[6;{$cellHeight};{$cellWidth}t", "\x1b[?62;22c");
        $terminal->clearOutput();

        return [$graphics, $terminal];
    }

    /** @return array{TerminalGraphics, VirtualTerminal} */
    public static function unanswered(): array
    {
        $tui = TestTui::create();
        $terminal = $tui->getTerminal();
        \assert($terminal instanceof VirtualTerminal, description: 'TestTui runs on a virtual terminal');
        $graphics = new TerminalGraphics($tui, new FakeScheduler());
        $graphics->probe([]);
        $terminal->clearOutput();

        return [$graphics, $terminal];
    }

    /** A {@see PixelRatio} over a graphics channel nobody reads: for a service that only passes settings on. */
    public static function ratio(): PixelRatio
    {
        return new PixelRatio(self::unanswered()[0]);
    }

    /** Feed the terminal's replies to $graphics, as the input listener would. */
    public static function answer(TerminalGraphics $graphics, string ...$replies): void
    {
        foreach ($replies as $reply) {
            $graphics->onInput(new InputEvent($reply));
        }
    }

    /**
     * Every image the terminal was sent, decoded — what a pane drew, to the pixel.
     *
     * @return list<\GdImage>
     */
    public static function sentImages(VirtualTerminal $terminal): array
    {
        $commands = [];
        \preg_match_all('/\x1b_G([^;\x1b]*);([^\x1b]*)\x1b\\\\/', $terminal->getOutput(), $commands, \PREG_SET_ORDER);
        $images = [];
        $payload = null;
        foreach ($commands as [, $keys, $data]) {
            if (\str_starts_with($keys, 'a=t,')) {
                $payload = '';
            }
            if (null === $payload || !\str_contains($keys, 'm=')) {
                continue;
            }
            $payload .= $data;
            if (\str_ends_with($keys, 'm=0')) {
                $gd = \imagecreatefromstring((string) \base64_decode($payload, strict: true));
                \assert($gd instanceof \GdImage, description: 'a sent image decodes');
                $images[] = $gd;
                $payload = null;
            }
        }

        return $images;
    }

    /** The image ids a pane's cells name, in order of appearance. */
    public static function imageIdsIn(string $text): array
    {
        $matches = [];
        \preg_match_all('/\x1b\[38;2;(\d+);(\d+);(\d+)m[\x{100000}-\x{10DF20}]/u', $text, $matches, \PREG_SET_ORDER);

        return \array_values(\array_unique(\array_map(static fn (array $m): int => ((int) $m[1] << 16) | ((int) $m[2] << 8) | (int) $m[3], $matches)));
    }
}
