<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Editor\LineNumberMode;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A user's own config, with no project file anywhere, actually steers the IDE.
 *
 * This is the feature's most basic promise and it was the least tested part of
 * it: every other global-scope test either drives the settings screen or reads
 * `IdeConfig::load()` back. Neither exercises the chain the whole design rests
 * on — global file → the merge inside `load()` → the `$_ENV` JSON channels →
 * the compiled container → a service that behaves differently.
 *
 * Merging at the bottom was chosen precisely so that chain would need no
 * changes. That reasoning was recorded, measured against the container's cache
 * key, and never actually run end to end.
 */
final class GlobalConfigBootTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use PreservesPortaHome;
    use RunsIde;
    use ScreenReads;

    /** Held so the destructors do not remove the dirs mid-test. */
    private TempProject $home;

    private TempProject $project;

    public function testAGlobalConfigAloneSteersTheRunningIde(): void
    {
        \putenv('PATH=/nonexistent');
        $this->home = new TempProject(['.porta/config.json' => \json_encode(
            [
                'settings' => ['language' => 'fr', 'lineNumbers' => 'relative'],
            ],
            \JSON_THROW_ON_ERROR,
        )]);
        // No .porta.json at all: everything here comes from the user's file.
        $this->project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);
        \putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // Through the env channel into a service: the editor's
                    // gutter mode is not something the settings screen set
                    // this session, it is what the IDE booted with.
                    $editor = $tui->getById('editor');
                    $this->assertSame(
                        LineNumberMode::Relative,
                        EditorState::display($editor)->lineNumbers(),
                        'the editor booted from the user file',
                    );

                    // And through the Translator into painted chrome.
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'Fichier'), 'the menu bar is in the language the user chose');
                    $this->assertNull($this->rowOf($grid, 'Navigate'), 'and not in the default one');

                    $this->assertFileDoesNotExist(
                        $this->project->path('.porta.json'),
                        'none of which required a file in the project',
                    );
                },
            ],
        );
    }

    /**
     * And the project file still wins where it says anything.
     *
     * The pair matters more than either half: a global layer that could not be
     * overridden would be worse than none, and one that never applied would be
     * invisible. This is the same boot with one key contested.
     */
    public function testTheProjectFileStillOverridesTheUsersOnBoot(): void
    {
        \putenv('PATH=/nonexistent');
        $this->home = new TempProject(['.porta/config.json' => \json_encode(
            [
                'settings' => ['language' => 'fr', 'lineNumbers' => 'relative'],
            ],
            \JSON_THROW_ON_ERROR,
        )]);
        $this->project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
            '.porta.json' => \json_encode(['settings' => ['lineNumbers' => 'absolute']], \JSON_THROW_ON_ERROR),
        ]);
        \putenv('PORTA_HOME='.$this->home->dir);

        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $editor = $tui->getById('editor');
                    $this->assertSame(
                        LineNumberMode::Absolute,
                        EditorState::display($editor)->lineNumbers(),
                        'the project overrode the key it names',
                    );

                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull(
                        $this->rowOf($grid, 'Fichier'),
                        'and left the key it does not name to the user file',
                    );
                },
            ],
        );
    }

    /** The merge the running IDE used is the merge a fresh load produces. */
    public function testTheLoaderAgreesWithWhatTheIdeBootedWith(): void
    {
        \putenv('PATH=/nonexistent');
        $this->home = new TempProject(['.porta/config.json' => \json_encode(
            [
                'settings' => ['language' => 'fr', 'lineNumbers' => 'relative'],
            ],
            \JSON_THROW_ON_ERROR,
        )]);
        $this->project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);
        \putenv('PORTA_HOME='.$this->home->dir);

        $config = IdeConfig::load($this->project->dir);

        $this->assertSame('fr', $config->settings['language']);
        $this->assertSame('relative', $config->settings['lineNumbers']);
        $this->assertSame([], $config->warnings, 'and a user file that is merely present warns about nothing');
    }
}
