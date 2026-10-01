<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Porta\Event\DebugStatusChanged;
use Porta\Event\ParticipantsChanged;
use Porta\Tests\Support\TestTui;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\StatusBarRefreshListener;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Why a status source has to say it changed: the bar re-reads its cells in
 * beforeRender(), and a frame skips beforeRender() for every widget under a
 * subtree whose render is cached. A plain render request leaves the bar as
 * it was; the listener marks it dirty, and the next frame reads it again.
 */
final class StatusBarRefreshListenerTest extends TestCase
{
    public function testAChangedSourceRepaintsTheBarAPlainRenderRequestDoesNot(): void
    {
        $cell = 'before';
        $bar = new StatusBarWidget();
        $bar->setProvider(static function () use (&$cell): array {
            return ['cells' => [['text' => $cell, 'menu' => null]], 'left' => '', 'right' => ''];
        });
        $tui = TestTui::create(60, 3);
        $terminal = $tui->getTerminal();
        Assert::assertInstanceOf(VirtualTerminal::class, $terminal, 'the test rig\'s terminal');
        $tui->add(new ContainerWidget()->add($bar));
        $frame = static function () use ($tui, $terminal): string {
            $terminal->clearOutput();
            $tui->requestRender();
            $tui->processRender();

            return AnsiUtils::stripAnsiCodes($terminal->getOutput());
        };
        $this->assertStringContainsString('before', $frame());

        $cell = 'after!';
        $this->assertStringNotContainsString('after!', $frame(), 'the trap: a cached subtree never asks the bar again');

        $listener = new StatusBarRefreshListener($bar, $tui);
        $listener->onStatusChanged(new ParticipantsChanged());
        $this->assertStringContainsString('after!', $frame(), 'marked dirty: read again, and painted');

        $cell = 'debug';
        $listener->onStatusChanged(new DebugStatusChanged());
        $this->assertStringContainsString('debug', $frame());
    }
}
