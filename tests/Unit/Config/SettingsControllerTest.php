<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\SettingsController;
use Porta\Ide;
use Porta\Tests\Support\TempProject;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class SettingsControllerTest extends TestCase
{
    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        putenv('PATH=/nonexistent'); // keep git/LSP out of these unit runs
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    public function testUnknownKeyFlashesInsteadOfTogglingAnything(): void
    {
        $project = new TempProject(['composer.json' => '{}']);
        $ide = new Ide($project->dir, null, new VirtualTerminal(columns: 80, rows: 24));
        $settings = new \ReflectionProperty($ide, 'settings')->getValue($ide);
        \assert($settings instanceof SettingsController);

        $settings->toggle('doesNotExist');

        $status = $ide->getTui()->getById('status');
        \assert($status instanceof StatusBarWidget);
        $flash = new \ReflectionProperty($status, 'flash')->getValue($status);
        $this->assertStringContainsString('Unknown setting "doesNotExist"', (string) ($flash['text'] ?? ''));
        $this->assertFalse(is_file($project->path('.porta.json')), 'nothing was persisted');
    }
}
