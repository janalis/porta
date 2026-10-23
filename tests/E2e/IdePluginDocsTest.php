<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Help\HelpViewController;
use Porta\Help\RenderedDoc;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Widget\HelpViewerWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A bundled plugin's guide lives in the plugin, and the shipped front page
 * links into it: the real docs, the real plugins directory, the real wiring
 * from the installer's docs roots to the browser's containment — with every
 * plugin DISABLED, because reading what a plugin does comes before turning it
 * on.
 */
final class IdePluginDocsTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    public function testTheFrontPageLeadsIntoAPluginsOwnGuide(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => []], \JSON_THROW_ON_ERROR),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $window = Services::resolve($ide, HelpViewController::class);
                    $viewer = Services::resolve($ide, HelpViewerWidget::class);
                    $window->showPage(\dirname(__DIR__, levels: 2).'/docs/README.md');
                    $window->open();
                    $this->awaitPainted($tui, $terminal, 'Porta documentation');

                    // Tab to the Git row: the link's place among the page's
                    // links, read from the page as rendered.
                    /** @var RenderedDoc $doc */
                    $doc = Probe::get($viewer, 'doc');
                    $group = \array_find(\range(0, $doc->groupCount() - 1), static fn (int $g): bool => '../plugins/git/docs/README.md' === $doc->target($g));
                    $this->assertIsInt($group, 'the front page links the git guide');
                    foreach (\range(0, $group) as $_) {
                        $tui->handleInput("\t");
                    }
                    $tui->handleInput("\r");

                    $this->awaitPainted($tui, $terminal, 'Documentation › git');
                    $this->assertSame(\realpath(\dirname(__DIR__, levels: 2).'/plugins/git/docs/README.md'), $viewer->path(), "the plugin's own page, not a refusal");
                },
            ],
        );
    }
}
