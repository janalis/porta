<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Widgets;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A server that answers diagnostics only when asked — as phpantom does, never
 * publishing one — still has its findings on screen: asked about as the file
 * opens, and again as it is edited.
 */
final class IdeLspPullDiagnosticsTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    public function testWhatTheServerSaysWhenAskedIsShownAndFollowsEdits(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => \json_encode(['lsp' => \PHP_BINARY.' '.\dirname(__DIR__).'/Support/fixtures/pull-diagnostics-lsp-server.php'], \JSON_THROW_ON_ERROR),
            'a.php' => "<?php\nnew UnknownClass();\n\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $decorations = Widgets::editor($tui)->decorations;
                    $this->await(static fn (): bool => null !== $decorations->diagnosticAt(1), what: 'the opened file\'s finding');
                    $this->assertSame("Class 'UnknownClass' not found", $decorations->diagnosticAt(1)['message'] ?? null);
                    $this->assertNull($decorations->diagnosticAt(0));

                    $tui->handleInput('UnknownClass'); // typed at the caret, line 0
                    $this->await(static fn (): bool => null !== $decorations->diagnosticAt(0), what: 'the edit\'s finding');
                    $this->assertNotNull($decorations->diagnosticAt(1), 'the first still stands');
                },
            ],
        );
    }
}
