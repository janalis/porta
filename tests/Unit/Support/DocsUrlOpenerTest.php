<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Support\DocsUrlOpener;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Notifier;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class DocsUrlOpenerTest extends TestCase
{
    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = PORTA_TEST_PRISTINE_PATH;
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    private function flashText(StatusBarWidget $status): string
    {
        return $status->activeFlash() ?? '';
    }

    public function testWithoutAnOpenerOnPathTheUrlItselfIsFlashed(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject();
        $status = new StatusBarWidget();
        $opener = new DocsUrlOpener(new Notifier($status, new Tui(terminal: new VirtualTerminal(columns: 80, rows: 10))), new Translator('en'), $project->dir);

        $opener->open('https://docs.test/str_pad');

        $this->assertStringContainsString('Docs: https://docs.test/str_pad', $this->flashText($status), 'most terminals auto-link the flashed URL');
    }

    public function testWithAnOpenerTheBrowserIsLaunchedAndTheOpenFlashed(): void
    {
        $project = new TempProject();
        $bin = $project->path('bin');
        mkdir($bin);
        $opener = 'Darwin' === \PHP_OS_FAMILY ? 'open' : 'xdg-open';
        file_put_contents($bin.'/'.$opener, data: "#!/bin/sh\nexit 0\n");
        chmod($bin.'/'.$opener, permissions: 0o755);
        putenv('PATH='.$bin);

        $status = new StatusBarWidget();
        $docs = new DocsUrlOpener(new Notifier($status, new Tui(terminal: new VirtualTerminal(columns: 80, rows: 10))), new Translator('en'), $project->dir);

        $docs->open('https://docs.test/str_pad');

        $this->assertStringContainsString('Opened https://docs.test/str_pad', $this->flashText($status));
    }
}
