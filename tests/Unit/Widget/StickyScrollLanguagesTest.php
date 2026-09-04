<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Lsp\DocumentScopes;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The sticky band reads the same scopes the breadcrumb trail does, so it works
 * wherever the trail does.
 *
 * It used to be PHP-only while breadcrumbs had already gained every language —
 * an asymmetry `decisions.md` recorded rather than hid, because closing it
 * meant getting a dependency into the codebase's widest class without moving a
 * ratchet.
 */
final class StickyScrollLanguagesTest extends TestCase
{
    private const string TS = "class Service {\n  handle() {\n";

    private function editor(DocumentScopes $scopes, string $path): CodeEditorWidget
    {
        $editor = new CodeEditorWidget(documentScopes: $scopes);
        $editor->showDocument($path, new Document(self::TS.str_repeat("    let x = 1;\n", times: 20)."  }\n}\n"));
        $editor->getDocument()->setCursor(18, 4); // deep inside handle(), headers scrolled off

        return $editor;
    }

    /** The scopes a server would report for the fixture, 1-based like the scanner's. */
    private static function serverScopes(string $path, DocumentScopes $scopes): void
    {
        $scopes->set($path, [
            ['label' => 'Service', 'startLine' => 1, 'endLine' => 24, 'kind' => 'type'],
            ['label' => 'handle', 'startLine' => 2, 'endLine' => 23, 'kind' => 'function'],
        ]);
    }

    public function testATypescriptBufferGetsAPinnedBandFromTheServersScopes(): void
    {
        $scopes = new DocumentScopes();
        self::serverScopes('/p/a.ts', $scopes);
        $editor = $this->editor($scopes, '/p/a.ts');

        $top = AnsiUtils::stripAnsiCodes($editor->render(new RenderContext(60, 12))[0]);

        self::assertStringContainsString('Service', $top, 'the enclosing class is pinned');
    }

    public function testWithNoServerScopesTheBandIsEmptyRatherThanWrong(): void
    {
        // The no-server and unsupported-language paths, which must render
        // exactly as they did before.
        $editor = $this->editor(new DocumentScopes(), '/p/a.ts');

        $top = AnsiUtils::stripAnsiCodes($editor->render(new RenderContext(60, 12))[0]);

        self::assertStringNotContainsString('Service', $top);
    }

    public function testScopesStoredForANOTHERFileAreNotPinnedOverThisOne(): void
    {
        // A band naming another file's classes is worse than no band.
        $scopes = new DocumentScopes();
        self::serverScopes('/p/other.ts', $scopes);
        $editor = $this->editor($scopes, '/p/a.ts');

        $top = AnsiUtils::stripAnsiCodes($editor->render(new RenderContext(60, 12))[0]);

        self::assertStringNotContainsString('Service', $top);
    }
}
