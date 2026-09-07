<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A key the catalog binds, that nothing answers to.
 *
 * `enabledPlugins` REPLACES the bundled defaults rather than adding to them —
 * deliberate, and settled — so a list written to add a language server drops
 * every default it does not repeat. The verb's menu entry hides itself, but the
 * KEY had no such courtesy: it matched nothing and the press did nothing, in
 * silence. This is the sentence that makes the trap visible, at the moment the
 * missing verb is reached for.
 *
 * The suite pins `PORTA_EMBEDDED_PLUGINS` to `/nonexistent`, which would make
 * every bundled plugin absent for the wrong reason and both tests below pass
 * vacuously — {@see UsesBundledPlugins} points it at the real `plugins/` dir so
 * the only thing deciding is the config under test.
 */
final class IdeUnimplementedVerbTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    /** Alt+8 — Show TODOs, whose implementation lives in the bundled todo plugin. */
    private const string ALT_8 = "\x1b8";

    private function pressAlt8(TempProject $project, \Closure $assert): void
    {
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput(self::ALT_8);
                },
                340 => function ($tui, $terminal) use ($assert): void {
                    $this->frame($tui);
                    $assert(AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testAVerbWhoseBundledPluginIsUnlistedExplainsItself(): void
    {
        // This repo's own `.porta.json` shape: a list written to add language
        // servers, keeping four of the five bundled defaults and dropping todo.
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n// TODO: something\n",
            '.porta.json' => json_encode(['enabledPlugins' => ['git', 'image-viewer', 'graph-viewer', 'local-history']], \JSON_THROW_ON_ERROR),
        ]);

        $this->pressAlt8($project, function (string $screen): void {
            $this->assertStringContainsString('Show TODOs comes from a bundled plugin', $screen, 'the key explains itself instead of doing nothing');
            $this->assertStringContainsString('enabledPlugins', $screen, 'and names the setting that turned it off');
        });
    }

    public function testTheSameVerbIsSilentWhenItsPluginIsListed(): void
    {
        // The control, and it is what caught the first version of this test:
        // with the embedded dir pinned to `/nonexistent` the explanation fired
        // here too, because `todo` was missing for a reason that had nothing to
        // do with the config.
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n// TODO: something\n",
            '.porta.json' => json_encode(['enabledPlugins' => ['todo']], \JSON_THROW_ON_ERROR),
        ]);

        $this->pressAlt8($project, function (string $screen): void {
            $this->assertStringNotContainsString('comes from a bundled plugin', $screen, 'nothing is missing, so nothing is explained');
            $this->assertStringContainsString('TODO', $screen, 'and the scan it opened is on screen');
        });
    }
}
