<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BreadcrumbTrailProvider;
use Porta\Editor\Document;
use Porta\Lsp\DocumentScopes;
use Porta\Widget\CodeEditorWidget;

/**
 * Which scopes the breadcrumbs bar reads from.
 *
 * PHP is scanned offline — instant, and right with no server running. Every
 * other language was simply BLANK, however much the language server knew,
 * until its symbols were kept in the shape the selector already reads.
 */
final class BreadcrumbTrailProviderTest extends TestCase
{
    private CodeEditorWidget $editor;
    private DocumentScopes $scopes;

    private function provider(): BreadcrumbTrailProvider
    {
        $this->scopes = new DocumentScopes();
        // The widget chooses the scope source now, so the store goes to IT.
        $this->editor = new CodeEditorWidget(documentScopes: $this->scopes);

        return new BreadcrumbTrailProvider($this->editor);
    }

    public function testATypescriptBufferGetsATrailFromTheServersSymbols(): void
    {
        $provider = $this->provider();
        $this->editor->showDocument('/p/a.ts', new Document("class Svc {\n  run() {\n    let x = 1;\n  }\n}\n"));
        $this->scopes->set('/p/a.ts', [
            ['label' => 'Svc', 'startLine' => 1, 'endLine' => 5, 'kind' => 'type'],
            ['label' => 'run', 'startLine' => 2, 'endLine' => 4, 'kind' => 'function'],
        ]);
        $this->editor->getDocument()->setCursor(2, 0);

        $this->assertSame(
            [['label' => 'Svc', 'line' => 0], ['label' => 'run', 'line' => 1]],
            $provider->trail(),
        );
    }

    public function testPhpStillUsesTheOfflineScanRatherThanTheServer(): void
    {
        // The stored scopes name something the scanner would never produce, so
        // a trail mentioning it would prove PHP had taken the LSP path.
        $provider = $this->provider();
        $this->editor->showDocument('/p/a.php', new Document("<?php\n\nclass Real\n{\n    public function go(): void\n    {\n    }\n}\n"));
        $this->scopes->set('/p/a.php', [['label' => 'FromTheServer', 'startLine' => 1, 'endLine' => 99, 'kind' => 'type']]);
        $this->editor->getDocument()->setCursor(5, 0);

        $labels = \array_column($provider->trail(), 'label');

        $this->assertNotContains('FromTheServer', $labels);
        $this->assertSame(['Real', 'go'], $labels);
    }

    public function testAFileWithNoStoredScopesHasNoTrail(): void
    {
        $provider = $this->provider();
        $this->editor->showDocument('/p/a.ts', new Document("let x = 1;\n"));

        $this->assertSame([], $provider->trail());
    }

    public function testNoOpenFileHasNoTrail(): void
    {
        $this->assertSame([], $this->provider()->trail());
    }
}
