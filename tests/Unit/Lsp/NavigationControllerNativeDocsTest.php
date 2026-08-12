<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\NavigationController;
use Porta\Support\DocsUrlOpener;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestBottomDock;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The native-docs short-circuit's syntax guard: F12 on a bare native symbol
 * still answers with its official docs (the feature, decided before any LSP
 * involvement), but a word that merely SHARES a native's name — a method on a
 * receiver, a namespaced or use-imported class, a declaration — must fall
 * through to the LSP. Before the guard, F12 on `$collection->count()` opened
 * php.net instead of jumping to the project's method.
 */
final class NavigationControllerNativeDocsTest extends TestCase
{
    /** The docs URL the controller would open for the word at (line, col) of $content, or null = the LSP answers. */
    private function urlAt(string $file, string $content, int $line, int $col): ?string
    {
        $editor = new CodeEditorWidget();
        $editor->openFile($file, $content);
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $notifier = new Notifier(new StatusBarWidget(), $tui);
        $translator = new Translator('en');
        $controller = new NavigationController(
            new LspSessionPool('/p', new FixedActiveFile(), $translator),
            $editor,
            $tui,
            $notifier,
            $translator,
            new BufferManager(),
            TestBottomDock::create($tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
            '/p',
            new class implements FileOpener {
                public function openFile(string $path, OpenFocus $focus = OpenFocus::Keep): void {}
            },
            new DocsUrlOpener($notifier, $translator, '/p'),
        );

        /** @var string|null $url */
        $url = Probe::invoke($controller, 'nativeDocsUrl', $line, $col);

        return $url;
    }

    public function testABareNativeFunctionStillAnswersWithItsDocs(): void
    {
        $this->assertSame(
            'https://www.php.net/manual/en/function.count.php',
            $this->urlAt('x.php', "<?php\ncount(\$x);\n", 1, 2),
            'the feature stands: a bare native call has no project declaration to jump to',
        );
    }

    public function testAnExplicitGlobalBackslashStaysNative(): void
    {
        $this->assertSame(
            'https://www.php.net/manual/en/function.count.php',
            $this->urlAt('x.php', "<?php\n\\count(\$x);\n", 1, 3),
            'a bare leading backslash IS the explicit global',
        );
    }

    public function testAMethodCallOnAReceiverGoesToTheLsp(): void
    {
        $this->assertNull(
            $this->urlAt('x.php', "<?php\n\$collection->count();\n", 1, 14),
            '->count() is the project method, whatever its name matches',
        );
        $this->assertNull(
            $this->urlAt('x.php', "<?php\nFoo::date();\n", 1, 6),
            '::date() is a static member of Foo',
        );
    }

    public function testANamespacedNameGoesToTheLsp(): void
    {
        $this->assertNull(
            $this->urlAt('x.php', "<?php\nnew App\\Exception();\n", 1, 12),
            'App\\Exception is a project class, not the native one',
        );
        $this->assertNull(
            $this->urlAt('x.php', "<?php\nException\\Helper::x();\n", 1, 3),
            'a namespace SEGMENT is not the class it is named after',
        );
    }

    public function testADeclarationSiteGoesToTheLsp(): void
    {
        $this->assertNull(
            $this->urlAt('x.php', "<?php\nnamespace App;\nfunction count(): int\n{\n}\n", 2, 11),
            'F12 on a declaration must not leave the file',
        );
    }

    public function testAUseImportedNameGoesToTheLsp(): void
    {
        $this->assertNull(
            $this->urlAt('x.php', "<?php\nuse App\\Exception;\n\nthrow new Exception('x');\n", 3, 12),
            'the import rebinds the bare name for the whole file',
        );
        $this->assertNull(
            $this->urlAt('x.php', "<?php\nuse App\\Err as Exception;\n\nthrow new Exception('x');\n", 3, 12),
            'and an alias rebinds it just the same',
        );
    }

    public function testADotReceiverGoesToTheLspOutsidePhp(): void
    {
        $this->assertNull(
            $this->urlAt('app.py', "df.all()\n", 0, 4),
            'python: .all() is a member of df, not the builtin',
        );
        // In PHP the dot is CONCATENATION, where the global really answers.
        $this->assertSame(
            'https://www.php.net/manual/en/function.count.php',
            $this->urlAt('x.php', "<?php\n\$s = 'n='.count(\$x);\n", 1, 12),
            "php: 'n='.count() is the native function after a concat dot",
        );
    }
}
