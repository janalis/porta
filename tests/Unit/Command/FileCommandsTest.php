<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * FileCommands' open_as_text gate: true only for a viewer tab (one opened
 * via BufferManager::openPane, e.g. an image/graph/svg), never for no
 * active buffer or a plain text one. BufferManager is a final class the
 * project's hard rules forbid mocking, so this boots a real IDE.
 */
final class FileCommandsTest extends TestCase
{
    private function boot(): Ide
    {
        $project = new TempProject(['a.php' => "<?php\n"]);
        new OnboardingState($project->dir)->markWelcomeSeen();

        return new Ide($project->dir, null, new VirtualTerminal(columns: 100, rows: 30));
    }

    private function closeIde(Ide $ide): void
    {
        Services::resolve($ide, ViewRegistry::class)->closeAll();
    }

    public function testOpenAsTextIsDisabledWithNoActiveBuffer(): void
    {
        $ide = $this->boot();
        try {
            $commands = Services::resolve($ide, CommandRegistry::class);

            $this->assertFalse($commands->isEnabled('open_as_text'), 'no active buffer at all — a null-safe read, not a crash');
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testOpenAsTextIsDisabledForAPlainTextBuffer(): void
    {
        $ide = $this->boot();
        try {
            $buffers = Services::resolve($ide, BufferManager::class);
            $commands = Services::resolve($ide, CommandRegistry::class);

            $buffers->open('a.php', "<?php\n");

            $this->assertFalse($commands->isEnabled('open_as_text'), 'a plain text buffer is not a viewer — the predicate must be exactly true===..., not its negation');
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testOpenAsTextIsEnabledOnlyForAViewerBuffer(): void
    {
        $ide = $this->boot();
        try {
            $buffers = Services::resolve($ide, BufferManager::class);
            $commands = Services::resolve($ide, CommandRegistry::class);

            $buffers->openPane('a.png', 'image', payload: null);

            $this->assertTrue($commands->isEnabled('open_as_text'), 'a viewer tab must enable open_as_text');
        } finally {
            $this->closeIde($ide);
        }
    }
}
