<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CodeScopeKind;
use Porta\Editor\ReferenceCopier;
use Porta\I18n\Translator;
use Porta\Lsp\DocumentScopes;
use Porta\Support\Clipboard;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * What lands on the clipboard. The reference is the interesting half: a symbol
 * when the caret is inside one, `path:line` when it is not.
 */
final class ReferenceCopierTest extends TestCase
{
    use StatusReads;

    private const string SOURCE = <<<'PHP_SRC'
        <?php

        namespace App\Http;

        class Svc
        {
            public function run(): void
            {
                $x = 1;
            }
        }
        PHP_SRC;

    private TempProject $project;
    private CodeEditorWidget $editor;
    private StatusBarWidget $status;
    private Tui $tui;

    /** @param array<string, string> $files */
    private function project(array $files, string $open): string
    {
        $this->project = new TempProject($files);

        return $this->project->path($open);
    }

    /** The copier over an editor fed the SERVER's scopes rather than the PHP scanner's. */
    private function copierFor(string $path, DocumentScopes $scopes): ReferenceCopier
    {
        $this->editor = new CodeEditorWidget(documentScopes: $scopes);
        $this->editor->openFile($path, (string) \file_get_contents($path));
        $this->status = new StatusBarWidget();
        $this->tui = TestTui::create(80, 24);

        return new ReferenceCopier(
            $this->editor,
            new ProjectPaths($this->project->dir),
            new Clipboard($this->tui),
            new Notifier($this->status, $this->tui),
            new Translator('en'),
        );
    }

    private function copier(string $source = self::SOURCE, string $file = 'src/Svc.php'): ReferenceCopier
    {
        $this->project = new TempProject([$file => $source]);
        $this->editor = new CodeEditorWidget();
        $this->editor->openFile($this->project->path($file), $source);
        $this->status = new StatusBarWidget();
        $this->tui = TestTui::create(80, 24);

        return new ReferenceCopier(
            $this->editor,
            new ProjectPaths($this->project->dir),
            new Clipboard($this->tui),
            new Notifier($this->status, $this->tui),
            new Translator('en'),
        );
    }

    /** What the flash reports is what was copied — the clipboard itself needs a terminal. */
    private function copied(): string
    {
        $flash = $this->flashText($this->status);

        return \str_starts_with($flash, 'Copied ') ? \substr($flash, offset: 7) : $flash;
    }

    public function testCopyPathIsRelativeToTheProject(): void
    {
        // The absolute one is noise in a message and a test filter alike.
        $copier = $this->copier();

        $copier->copyPath();

        $this->assertSame('src/Svc.php', $this->copied());
    }

    public function testWhatTheFLASHSaysIsWhatReachesTheClipboard(): void
    {
        // Every other test here reads the flash, which is the readable seam.
        // This one reads the OSC 52 the terminal actually received, so a
        // reported copy that never happened cannot pass the rest of them.
        $copier = $this->copier();
        $this->editor->getDocument()->setCursor(8, 8);

        $copier->copyReference();

        $terminal = $this->tui->getTerminal();
        $this->assertInstanceOf(VirtualTerminal::class, $terminal, 'TestTui builds a virtual terminal');
        $this->assertStringContainsString(
            "\x1b]52;c;".\base64_encode('App\\Http\\Svc::run')."\x07",
            $terminal->getOutput(),
            'the reference went out as an OSC 52 clipboard write',
        );
    }

    public function testAReferenceInsideAMethodIsTheQUALIFIEDSymbol(): void
    {
        $copier = $this->copier();
        $this->editor->getDocument()->setCursor(8, 8); // inside run()'s body

        $copier->copyReference();

        $this->assertSame('App\Http\Svc::run', $this->copied());
    }

    public function testTheSECONDMethodIsNamed_NotTheClassAlone(): void
    {
        // The scopes arrive in start-line order, so a sibling that does NOT
        // enclose the caret comes before the one that does. Skipping it has to
        // keep looking rather than give up on the rest of the list.
        $source = <<<'SRC'
            <?php

            namespace App\Http;

            class Svc
            {
                public function first(): void
                {
                }

                public function second(): void
                {
                    $x = 1;
                }
            }
            SRC;
        $copier = $this->copier($source);
        $this->editor->getDocument()->setCursor(12, 8); // inside second()

        $copier->copyReference();

        $this->assertSame('App\Http\Svc::second', $this->copied());
    }

    public function testAScopesFirstAndLASTLinesAreInsideIt(): void
    {
        // The boundaries, because everything else in this class sits
        // comfortably inside one: an off-by-one at either end would still name
        // a plausible symbol, just the wrong one.
        $copier = $this->copier();
        $cases = [
            6 => 'App\Http\Svc::run', // `public function run(): void` — run's first line
            9 => 'App\Http\Svc::run', // `    }` — run's last line
            10 => 'App\Http\Svc', // `}` — past run, still the class's last line
            4 => 'App\Http\Svc', // `class Svc` — the class's first line
        ];

        foreach ($cases as $line => $expected) {
            $this->editor->getDocument()->setCursor($line, 0);
            $copier->copyReference();
            $this->assertSame($expected, $this->copied(), "line {$line}");
        }
    }

    public function testOnTheCLASSItselfItIsTheFqcn(): void
    {
        $copier = $this->copier();
        $this->editor->getDocument()->setCursor(5, 4); // the `{` line of the class

        $copier->copyReference();

        $this->assertSame('App\Http\Svc', $this->copied());
    }

    public function testAReferenceFollowsTheLANGUAGESSpelling(): void
    {
        // The scopes for a non-PHP file come from the language server, in the
        // same shape the PHP scanner produces — so this reaches the same code
        // with a file that spells qualification differently. `Service::handle`
        // is not what a TypeScript project would search for.
        $scopes = new DocumentScopes();
        $path = $this->project(['src/a.ts' => "class Service {\n  handle() {\n    let x = 1;\n  }\n}\n"], 'src/a.ts');
        $scopes->set($path, [
            ['label' => 'Service', 'startLine' => 1, 'endLine' => 5, 'kind' => CodeScopeKind::Type],
            ['label' => 'handle', 'startLine' => 2, 'endLine' => 4, 'kind' => CodeScopeKind::Function],
        ]);
        $copier = $this->copierFor($path, $scopes);
        $this->editor->getDocument()->setCursor(2, 4);

        $copier->copyReference();

        $this->assertSame('Service.handle', $this->copied());
    }

    public function testWithONLYANamespaceAroundItItFallsBackToPathAndLine(): void
    {
        // `App\Http` names the file's neighbourhood, not a place in it. The
        // line is 1-based, like every compiler and stack trace prints it.
        $copier = $this->copier();
        $this->editor->getDocument()->setCursor(3, 0); // the blank line after `namespace`

        $copier->copyReference();

        $this->assertSame('src/Svc.php:4', $this->copied());
    }

    public function testAPlainFUNCTIONNeedsNoClassToBeNamed(): void
    {
        $source = "<?php\n\nnamespace App;\n\nfunction helper(): void\n{\n    return;\n}\n";
        $copier = $this->copier($source, 'src/helpers.php');
        $this->editor->getDocument()->setCursor(6, 4);

        $copier->copyReference();

        $this->assertSame('App\helper', $this->copied());
    }

    public function testAFunctionInAFileWithNONamespaceIsJustItsName(): void
    {
        // A script rather than a library file. There is no qualifier to put in
        // front, and `\helper` would be a lie about where it lives.
        $copier = $this->copier("<?php\n\nfunction helper(): void\n{\n    return;\n}\n", 'bin/tool.php');
        $this->editor->getDocument()->setCursor(4, 4);

        $copier->copyReference();

        $this->assertSame('helper', $this->copied());
    }

    public function testAFileWithNoStructureAtAllIsAlwaysPathAndLine(): void
    {
        $copier = $this->copier("<?php\n\necho 1;\necho 2;\n", 'bin/run.php');
        $this->editor->getDocument()->setCursor(3, 0);

        $copier->copyReference();

        $this->assertSame('bin/run.php:4', $this->copied());
    }

    public function testWithNOFileOpenNeitherVerbCopiesAnything(): void
    {
        // Reachable from the palette with an empty editor area, where a
        // reference to nothing is worse than silence.
        $this->status = new StatusBarWidget();
        $tui = TestTui::create(80, 24);
        $project = new TempProject([]);
        $copier = new ReferenceCopier(
            new CodeEditorWidget(),
            new ProjectPaths($project->dir),
            new Clipboard($tui),
            new Notifier($this->status, $tui),
            new Translator('en'),
        );

        $copier->copyPath();
        $copier->copyReference();

        $this->assertSame('', $this->flashText($this->status));
    }
}
