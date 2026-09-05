<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Workbench\Dock\BottomDock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;

/**
 * Find-in-Project (Ctrl+Shift+F) full-app scenarios: scope selection, context
 * lines, multiline replace, the modal, export and search-history recall. Split
 * out of the oversized IdeEdgeCasesTest.
 */
final class IdeFindInProjectTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    /**
     * Promote the open Find-in-Files modal to the results pane (Alt+Enter =
     * "Open in Find Window"), so a test can drive the persistent pane as before.
     * Call it after the initial query has settled.
     */
    private function promote(Tui $tui): void
    {
        $tui->handleInput("\x1b\r"); // alt+enter
        $tui->tick();
    }

    /** The Find-in-Files modal's text (it composites over the editor pane). */
    private function modalText(Tui $tui): string
    {
        // The modal is span-delivered per frame by its presenter — force a
        // frame so a mutation made this step is on the pane before reading.
        $this->frame($tui);

        return AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 40))));
    }

    /** The editor pane's raw (styled) rendered rows (after a frame — see modalText). */
    private function editorRows(Tui $tui): array
    {
        $this->frame($tui);

        return $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 40));
    }

    /** How many hardware-cursor markers the rendered rows carry (there must be exactly one). */
    private function caretMarkerCount(array $rows): int
    {
        return array_sum(array_map(static fn (string $l): int => substr_count($l, AnsiUtils::CURSOR_MARKER_PREFIX), $rows));
    }

    /** The index of the row carrying the hardware-cursor marker, or null. */
    private function caretMarkerRow(array $rows): ?int
    {
        foreach ($rows as $i => $line) {
            if (str_contains($line, AnsiUtils::CURSOR_MARKER_PREFIX)) {
                return $i;
            }
        }

        return null;
    }

    public function testFindInProjectScopeSelectorNarrowsResults(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// tok here\n",
            'src/C.php' => "<?php\n// tok here\n",
            'other/B.php' => "<?php\n// tok here\n",
        ]);
        $panelText = $this->modalText(...); // scope is configured in the modal

        $this->runIde(
            $project,
            $project->path('src/A.php'), // the active file drives Directory/Current File scope
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                200 => function ($tui) use ($panelText): void {
                    // Each scope change re-runs the grep subprocess, and a
                    // stale result set isn't cleared until the new one lands —
                    // so await the COMBINED presence/absence the new scope
                    // uniquely produces, not just a fixed gap after the keypress.
                    $this->await(static fn (): bool => str_contains($panelText($tui), 'other/B.php'), what: 'the whole-project search to land');
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('src/A.php', $panel, 'whole project finds all three');
                    $this->assertStringContainsString('src/C.php', $panel);
                    $this->assertStringContainsString('other/B.php', $panel);
                    $tui->handleInput("\x1bs"); // alt+s → Directory (src/)
                },
                300 => function ($tui) use ($panelText): void {
                    $this->await(static fn (): bool => str_contains($panelText($tui), 'src/C.php') && !str_contains($panelText($tui), 'other/B.php'), what: 'the directory-scoped search to land');
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: Directory', $panel);
                    $this->assertStringContainsString('src/A.php', $panel);
                    $this->assertStringContainsString('src/C.php', $panel);
                    $this->assertStringNotContainsString('other/B.php', $panel, 'directory scope drops the sibling folder');
                    $tui->handleInput("\x1bs"); // alt+s → Current File (src/A.php)
                },
                400 => function ($tui) use ($panelText): void {
                    $this->await(static fn (): bool => str_contains($panelText($tui), 'src/A.php') && !str_contains($panelText($tui), 'src/C.php'), what: 'the current-file-scoped search to land');
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: Current File', $panel);
                    $this->assertStringContainsString('src/A.php', $panel);
                    $this->assertStringNotContainsString('src/C.php', $panel, 'current-file scope keeps only the open file');
                    $tui->handleInput("\x1bs"); // alt+s → Open Files (only src/A.php is open)
                },
                500 => function ($tui) use ($panelText): void {
                    // Open Files and Current File list identical FILES here
                    // (only src/A.php is open either way), which is why this
                    // step used to settle for a bare tick. The scope LABEL is
                    // still a new observable, and it is the one thing the
                    // keypress has to produce — so await that.
                    $this->await(
                        static fn (): bool => str_contains($panelText($tui), 'Scope: Open Files'),
                        what: 'the open-files scope to take effect',
                    );
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('src/A.php', $panel);
                    $this->assertStringNotContainsString('src/C.php', $panel, 'open-files scope keeps only the open buffers');
                    $tui->handleInput("\x1bs"); // alt+s → Module (no composer.json → whole project)
                },
                600 => function ($tui) use ($panelText): void {
                    $this->await(static fn (): bool => str_contains($panelText($tui), 'other/B.php'), what: 'the module-scoped (whole-project fallback) search to land');
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: Module', $panel);
                    $this->assertStringContainsString('other/B.php', $panel, 'no package root → module falls back to the whole project');
                    $tui->handleInput("\x1bs"); // alt+s → Whole Project (wraps)
                },
                700 => function ($tui) use ($panelText): void {
                    // Same again: Module (fallback) and Whole Project list the
                    // same files, and the label is what changes.
                    $this->await(
                        static fn (): bool => str_contains($panelText($tui), 'Scope: Whole Project'),
                        what: 'the whole-project scope to wrap back round',
                    );
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('other/B.php', $panel, 'wrapping back to Whole Project widens again');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAddToSearchScopesFromTreeThenSelectItInTheCycle(): void
    {
        $project = new TempProject([
            'lib/Inside.php' => "<?php\n// mark inside\n",
            'other/Outside.php' => "<?php\n// mark at the root\n",
        ]);

        $rightClickFolder = static function ($tui, $terminal, $ide): void {
            $tui->requestRender(force: true); // force a frame so the tree rect is live under load
            $tui->processRender();
            $renderer = Services::resolve($ide, Renderer::class);
            $rect = $renderer->getWidgetRect($tui->getById('tree'));
            // `lib` sorts first among the directories → the first tree entry (row 1).
            $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 2));
        };
        $panelText = $this->modalText(...); // scope is configured in the modal

        $this->runIde(
            $project,
            null, // tree focused
            [
                200 => $rightClickFolder,
                280 => function ($tui): void {
                    $tui->tick();
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'folder context menu opened');
                    // New File → New Folder → Find in Files → Replace in Files → Add to Search Scopes.
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // Add to Search Scopes
                },
                400 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('mark') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui) use ($panelText): void {
                    // The grep is a subprocess: await its rows instead of
                    // asserting a fixed number of ms after the query was typed.
                    $this->await(static fn (): bool => str_contains($panelText($tui), 'other/Outside.php'), what: 'the whole-project search to land');
                    $this->assertStringContainsString('other/Outside.php', $panelText($tui), 'whole project finds both first');
                    for ($i = 0; $i < 5; ++$i) {
                        $tui->handleInput("\x1bs"); // cycle past the 5 built-ins to the saved lib scope
                    }
                },
                1000 => function ($tui) use ($panelText): void {
                    // Cycling the scope re-runs the search, so await the new rows.
                    $this->await(static fn (): bool => str_contains($panelText($tui), 'lib/Inside.php'), what: 'the folder-scoped search to land');
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: lib', $panel, 'the saved scope is selectable in the Alt+S cycle');
                    $this->assertStringContainsString('lib/Inside.php', $panel);
                    $this->assertStringNotContainsString('other/Outside.php', $panel, 'the saved scope narrows to its folder');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectModuleScopeUsesComposerPackageRoot(): void
    {
        $project = new TempProject([
            'pkg/composer.json' => "{}\n",
            'pkg/src/A.php' => "<?php\n// tok here\n",
            'other/B.php' => "<?php\n// tok here\n",
        ]);
        $panelText = $this->modalText(...); // scope is configured in the modal

        $this->runIde(
            $project,
            $project->path('pkg/src/A.php'), // a file inside the pkg/ package
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1bs"); // → Directory
                    $tui->handleInput("\x1bs"); // → Current File
                    $tui->handleInput("\x1bs"); // → Open Files
                    $tui->handleInput("\x1bs"); // → Module
                },
                1000 => function ($tui) use ($panelText): void {
                    // Four rapid scope changes each cancel the previous grep
                    // (ProjectSearch::search() cancels on every call), so only
                    // the last (Module) ever completes — await ITS combined
                    // presence/absence, not a fixed gap, so a stale or still-
                    // empty result set can't satisfy the assertions below.
                    $this->await(static fn (): bool => str_contains($panelText($tui), 'pkg/src/A.php') && !str_contains($panelText($tui), 'other/B.php'), what: 'the module-scoped search to land');
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: Module', $panel);
                    $this->assertStringContainsString('pkg/src/A.php', $panel, 'the module scope keeps the package');
                    $this->assertStringNotContainsString('other/B.php', $panel, 'files outside the package are excluded');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testScopeSelectorWithNoFileOpenStaysWholeProject(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// tok\n", 'other/B.php' => "<?php\n// tok\n"]);
        $panelText = $this->modalText(...); // scope is configured in the modal

        $this->runIde(
            $project,
            null, // no file open → Directory/Current File resolve to the whole project
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1bs"); // alt+s → Directory, but no file → whole project
                },
                800 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: Directory', $panel);
                    $this->assertStringContainsString('src/A.php', $panel);
                    $this->assertStringContainsString('other/B.php', $panel, 'no open file → the scope stays the whole project');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectInCommentsStringsScopeNarrowsMatches(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$needle = 1;\n// needle here\n"]);
        $panelText = $this->modalText(...); // the token-scope option is in the modal

        $this->runIde(
            $project,
            null, // no open file → the modal composites over an empty pane (no file text leaks into asserts)
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('needle') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('$needle = 1;', $panel, 'the code occurrence matches first');
                    $this->assertStringContainsString('// needle here', $panel);
                    $tui->handleInput("\x1bt"); // alt+t → in comments/strings
                },
                1000 => function ($tui) use ($panelText): void {
                    // The token-scope re-search is a subprocess: await the code
                    // match actually dropping out instead of a fixed gap.
                    $this->await(static fn (): bool => !str_contains($panelText($tui), '$needle = 1;'), what: 'the comments/strings scope to land');
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('// needle here', $panel, 'the comment match is kept');
                    $this->assertStringNotContainsString('$needle = 1;', $panel, 'the code match is filtered out');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectContextLinesFromOpenBuffer(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$x = 1;\n// tok here\n\$y = 2;\n"]);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 20))));

        $this->runIde(
            $project,
            $project->path('src/A.php'), // open → context reads from the buffer
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui) use ($panelText): void {
                    $this->promote($tui); // modal → results pane (context lines live on the pane)
                    // The grep is a subprocess: await the match landing before
                    // expanding context on it.
                    $this->await(static fn (): bool => str_contains($panelText($tui), 'tok here'), what: 'the match to land');
                    $tui->handleInput("\x1bm"); // alt+m → expand context on the first match
                },
                800 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('$x = 1;', $panel, 'the preceding line is shown');
                    $this->assertStringContainsString('$y = 2;', $panel, 'the following line is shown');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectContextLinesFromDisk(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// alpha\n// tok here\n// beta\n"]);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 20))));

        $this->runIde(
            $project,
            null, // nothing open → context reads from disk
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui) use ($panelText): void {
                    $this->promote($tui); // modal → results pane (context lines live on the pane)
                    // The grep is a subprocess: await the match landing before
                    // expanding context on it.
                    $this->await(static fn (): bool => str_contains($panelText($tui), 'tok here'), what: 'the match to land');
                    $tui->handleInput("\x1bm"); // alt+m → expand context
                },
                800 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('// alpha', $panel, 'the preceding line is read from disk');
                    $this->assertStringContainsString('// beta', $panel, 'the following line is read from disk');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectMultilineSearchAndReplace(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\nfunction foo()\n{\n    return 1;\n}\n", // opened (active buffer)
            'src/B.php' => "<?php\nfunction foo()\n{\n    return 2;\n}\n", // only on disk
        ]);
        $panelText = $this->modalText(...); // find + replace happen in the modal

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('foo()') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1b[13;2u"); // shift+enter → newline in the query
                    $tui->handleInput('{');
                },
                600 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('foo()⏎{', $panel, 'the multiline query renders the newline as ⏎');
                    $this->assertStringContainsString('2 results', $panel, 'the pattern matches across the line break in both files');
                    $this->assertStringContainsString('foo() ⏎…', $panel, 'the match preview shows its first line + continuation');
                    // Replace the multiline match across the whole file (open buffer + disk).
                    $tui->handleInput("\t"); // query → replace
                    foreach (str_split('renamed') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1ba"); // alt+a → replace all
                },
                1000 => function ($tui) use ($project): void {
                    $tui->tick();
                    $text = $tui->getById('editor')->getDocument()->getText();
                    $this->assertStringContainsString('function renamed', $text, 'the open buffer is replaced across the line break');
                    $this->assertStringNotContainsString('foo()', $text);
                    $this->assertStringContainsString('return 1;', $text, 'the rest of the file is intact');
                    $this->assertStringContainsString('function renamed', (string) file_get_contents($project->path('src/B.php')), 'the on-disk file is replaced too');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectMultilineReplaceWithNoMatchesFlashes(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\nfoo\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('zzz') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1b[13;2u"); // shift+enter
                    foreach (str_split('qqq') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // query → replace
                    $tui->handleInput('x');
                    $tui->handleInput("\x1ba"); // alt+a with a multiline query but no matches
                },
                900 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Nothing to replace');
                    $this->assertStringContainsString('Nothing to replace', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectModalMaskFilters(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// token here\n",
            'src/B.js' => "// token here\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → modal
                    foreach (str_split('token') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    // In the modal: Tab query → replace → Mask, then restrict to PHP.
                    $tui->handleInput("\t");
                    $tui->handleInput("\t");
                    foreach (str_split('*.php') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                1050 => function ($tui): void {
                    $tui->tick();
                    $modal = $this->modalText($tui);
                    $this->assertStringContainsString('A.php', $modal, 'the PHP file still matches in the modal preview');
                    $this->assertStringNotContainsString('B.js', $modal, 'the *.php mask excludes the JS file');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectReplaceInTheModal(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$foo = 1;\n", // opened (active buffer)
            'src/B.php' => "<?php\n\$foo = 2;\n", // only on disk
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → modal
                    foreach (str_split('foo') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui): void {
                    $tui->tick();
                    // Replace directly in the modal (no promote): the preview flips to old → new.
                    $this->assertStringContainsString('$foo = 1;', $this->modalText($tui), 'the modal previews the matches');
                    $tui->handleInput("\t"); // query → replace
                    foreach (str_split('bar') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $this->assertStringContainsString('→', $this->modalText($tui), 'the modal shows the replacement preview');
                    $tui->handleInput("\x1ba"); // alt+a → replace all
                },
                1000 => function ($tui) use ($project): void {
                    $tui->tick();
                    $this->assertFalse($tui->getById('editor')->isFindModalOpen(), 'the modal closes after replacing');
                    $this->assertStringContainsString('$bar = 1;', $tui->getById('editor')->getDocument()->getText(), 'open buffer replaced');
                    $this->assertStringContainsString('$bar = 2;', (string) file_get_contents($project->path('src/B.php')), 'on-disk file replaced');
                },
            ],
        );
    }

    public function testFindInProjectExportWritesAndOpensResults(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// tok here\n",
            'src/B.php' => "<?php\n// tok too\n", // a second file → the export separates file groups
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui): void {
                    $this->promote($tui); // modal → results pane (export runs from the pane)
                    // The grep is a subprocess: await both matches landing
                    // before exporting them.
                    $this->await(static function () use ($tui): bool {
                        $panel = AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 20))));

                        return str_contains($panel, 'src/A.php') && str_contains($panel, 'src/B.php');
                    }, what: 'both matches to land');
                    $tui->handleInput("\x1bx"); // alt+x → export
                },
                900 => function ($tui) use ($project): void {
                    $tui->tick();
                    $exportPath = $project->path('.porta/search-results.txt');
                    $this->assertFileExists($exportPath);
                    $content = (string) file_get_contents($exportPath);
                    $this->assertStringContainsString('src/A.php', $content);
                    $this->assertStringContainsString('src/B.php', $content, 'every matched file is listed');
                    $this->assertStringContainsString('2: // tok here', $content, 'line numbers are 1-based');
                    $this->assertStringContainsString('search-results.txt', (string) $tui->getById('editor')->getFilePath(), 'the export opens in the editor');
                },
                1000 => static function ($tui, $terminal, $ide): void {
                    // A grouped panel that is NOT a search. Alt+X is offered on
                    // it because the key is gated on grouping, which Problems
                    // turns on to read its list by file.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_problems');
                },
                1100 => function ($tui, $terminal, $ide): void {
                    $this->awaitPainted($tui, $terminal, 'Problems');
                    $tui->handleInput("\x1bx"); // alt+x on the Problems panel
                },
                1250 => function ($tui, $terminal, $ide): void {
                    // It used to export the last GREP — which is still in
                    // memory from the step above — close the panel you were
                    // reading and open that file over it.
                    $this->frame($tui);
                    $this->assertNotNull(
                        Services::resolve($ide, BottomDock::class)->visiblePanel(),
                        'the panel you were reading is still open',
                    );
                    $this->assertStringContainsString(
                        'Problems',
                        AnsiUtils::stripAnsiCodes($terminal->getOutput()),
                        'and it is still the Problems list',
                    );
                },
            ],
        );
    }

    public function testFindInProjectRestoresScopeAndMaskOnReopen(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// tok here\n",
            'other/B.php' => "<?php\n// tok here\n",
        ]);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 16))));

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                500 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // query → replace
                    $tui->handleInput("\t"); // replace → mask
                    foreach (str_split('*.php') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1bs"); // alt+s → Directory scope
                },
                800 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b"); // esc → close (persists scope + mask)
                },
                1000 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // reopen
                },
                1200 => function ($tui): void {
                    $tui->tick();
                    $modal = $this->modalText($tui); // the reopened modal restores the config
                    $this->assertStringContainsString('Scope: Directory', $modal, 'the last scope is restored');
                    $this->assertStringContainsString('Mask: *.php', $modal, 'the last mask is restored');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectF5RerunsPicksUpDiskChanges(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// tok here\n"]);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 16))));

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui) use ($panelText, $project): void {
                    $tui->tick();
                    $this->promote($tui); // modal → results pane (F5 rerun lives on the pane)
                    $this->assertStringContainsString('1 results', $tui->getById('results')->statusText());
                    $project->write('src/B.php', "// tok added later\n"); // a new match on disk
                    $tui->handleInput("\x1b[15~"); // F5 → rerun
                },
                900 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('2 results', $tui->getById('results')->statusText(), 'rerun sees the new file');
                    $this->assertStringContainsString('src/B.php', $panel);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectPreserveCaseReplace(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// oldname Oldname OLDNAME\n"]);

        $this->runIde(
            $project,
            null, // no open buffer → replace-all writes the file on disk
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('oldname') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui): void {
                    // The grep is a subprocess: await its match landing in the
                    // MODAL before acting on it, instead of a fixed gap. (The
                    // query field itself already echoes "oldname", so await
                    // the file path — only the results preview carries it.)
                    $this->await(fn (): bool => str_contains($this->modalText($tui), 'src/A.php'), what: 'the search to land');
                    $tui->handleInput("\x1bv"); // alt+v → preserve case (modal option)
                    $tui->handleInput("\t"); // query → replace
                    foreach (str_split('newname') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1ba"); // alt+a → replace all
                },
                900 => function ($tui) use ($project): void {
                    $this->assertStringContainsString(
                        'newname Newname NEWNAME',
                        (string) file_get_contents($project->path('src/A.php')),
                        'each occurrence keeps its original casing',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectSearchHistoryRecall(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// tok here\n"]);

        $this->runIde(
            $project,
            null, // no open buffer → the editor never bleeds "tok" behind the modal
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                500 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b"); // esc → close (records "tok" in history)
                },
                700 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // reopen (fresh, empty query)
                },
                900 => function ($tui): void {
                    $tui->tick();
                    $this->assertStringNotContainsString('tok', $this->modalText($tui), 'reopens empty');
                    $tui->handleInput("\x1bp"); // legacy alt+up → recall the prior query in the modal
                },
                1100 => function ($tui): void {
                    $tui->tick();
                    $this->assertStringContainsString('tok', $this->modalText($tui), 'the prior search is recalled');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The modal's corner resize and title-bar move, through the real mouse
     * routing: press, drag, release — the box follows the pointer both ways
     * and a fresh press disarms cleanly (MouseRouter's continue chain +
     * cancel sweep).
     */
    public function testTheModalResizesByItsCornerAndMovesByItsTitleBar(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// tok here\n"]);

        $this->runIde($project, $project->path('src/A.php'), [
            200 => static function ($tui): void {
                $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
            },
            500 => function ($tui, $terminal, $ide): void {
                $this->frame($tui);
                $editor = $tui->getById('editor');
                $box = $editor->findModal()->lastBox();
                $this->assertNotNull($box);

                // The box is SCREEN-space (the modal floats over the whole
                // workspace); SGR coordinates are 1-based.
                $row = $box->startRow + $box->rows - 1 + 1;
                $col = $box->col + $box->width - 1 + 1;
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col, $row)); // press the corner
                $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $col + 6, $row + 1)); // drag out
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col + 6, $row + 1)); // release
                $this->frame($tui);

                $grown = $editor->findModal()->lastBox();
                $this->assertNotNull($grown);
                $this->assertSame($box->width + 6, $grown->width, 'the drag widened the box through the router');
                $this->assertTrue($editor->isFindModalOpen(), 'resizing never dismissed it');

                // Now drag it by the title bar: press left of the ×, move
                // down two rows, release — the box follows, still open.
                $barRow = $grown->startRow + 1;
                $barCol = $grown->col + 2 + 1;
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $barCol, $barRow));
                $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $barCol, $barRow + 2));
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $barCol, $barRow + 2));
                $this->frame($tui);

                $movedBox = $editor->findModal()->lastBox();
                $this->assertNotNull($movedBox);
                $this->assertSame($grown->startRow + 2, $movedBox->startRow, 'the title-bar drag moved the box through the router');
                $this->assertSame($grown->col, $movedBox->col, 'a vertical drag leaves the column');
                $this->assertTrue($editor->isFindModalOpen(), 'moving never dismissed it');

                // Drag it all the way left: the modal floats over the whole
                // workspace, so its box now paints over the NAVIGATION TREE
                // (which is dimmed beneath it) — the reason it is span-
                // delivered instead of composited inside the editor pane.
                $barRow2 = $movedBox->startRow + 1;
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $movedBox->col + 2 + 1, $barRow2));
                $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", 3, $barRow2));
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", 3, $barRow2));
                $this->frame($tui);

                $left = $editor->findModal()->lastBox();
                $this->assertNotNull($left);
                $this->assertSame(0, $left->col, 'clamped to the screen edge — over the tree');
                $renderer = Services::resolve($ide, Renderer::class);
                $treeRect = $renderer->getWidgetRect($tui->getById('tree'));
                $this->assertNotNull($treeRect);
                $treeRows = $tui->getById('tree')->render(new \Symfony\Component\Tui\Render\RenderContext($treeRect->columns, $treeRect->rows));
                $treeText = AnsiUtils::stripAnsiCodes(implode("\n", $treeRows));
                $this->assertStringContainsString('Find in Files', $treeText, "the modal's title bar paints over the tree pane");
                $this->assertStringContainsString("\x1b[2m", implode('', $treeRows), 'the tree renders dimmed beneath the modal');

                // A bare hover-move (mouse mode 1003) over the × routes to the
                // floating modal in screen coordinates and brightens it.
                $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $left->col + $left->width - 2 + 1, $left->startRow + 1));
                $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $left->col + 3 + 1, $left->startRow + 3 + 1)); // and off it again
                $tui->tick();
                $this->assertTrue($editor->isFindModalOpen(), 'hovering never dismissed it');
            },
        ]);

        $this->addToAssertionCount(1);
    }
}
