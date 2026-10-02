<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BreadcrumbTrailProvider;
use Porta\Editor\BufferManager;
use Porta\Editor\CodeScopeKind;
use Porta\Editor\Document;
use Porta\Lsp\DocumentScopes;
use Porta\Support\ProjectPaths;
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
    private BufferManager $buffers;

    private function provider(): BreadcrumbTrailProvider
    {
        $this->scopes = new DocumentScopes();
        // The widget chooses the scope source now, so the store goes to IT.
        $this->editor = new CodeEditorWidget(documentScopes: $this->scopes);

        $this->buffers = new BufferManager();

        return new BreadcrumbTrailProvider($this->editor, $this->buffers, new ProjectPaths('/p'));
    }

    public function testATypescriptBufferGetsATrailFromTheServersSymbols(): void
    {
        $provider = $this->provider();
        $this->editor->showDocument('/p/a.ts', new Document("class Svc {\n  run() {\n    let x = 1;\n  }\n}\n"));
        $this->scopes->set('/p/a.ts', [
            ['label' => 'Svc', 'startLine' => 1, 'endLine' => 5, 'kind' => CodeScopeKind::Type],
            ['label' => 'run', 'startLine' => 2, 'endLine' => 4, 'kind' => CodeScopeKind::Function],
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
        $this->scopes->set('/p/a.php', [['label' => 'FromTheServer', 'startLine' => 1, 'endLine' => 99, 'kind' => CodeScopeKind::Type]]);
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

    /**
     * The trail starts where the file is: a crumb per folder from the
     * project root down, then the file, each carrying the path a click
     * reveals — then the scopes, as before.
     */
    public function testTheActiveFilesPathLeadsTheTrail(): void
    {
        $provider = $this->provider();
        $this->buffers->open('/p/src/Http/Svc.php', '');

        $this->assertSame(
            [
                ['label' => 'src', 'line' => null, 'path' => '/p/src'],
                ['label' => 'Http', 'line' => null, 'path' => '/p/src/Http'],
                ['label' => 'Svc.php', 'line' => null, 'path' => '/p/src/Http/Svc.php'],
            ],
            $provider->trail(),
        );
    }

    public function testAFileOutsideTheProjectIsItsNameAlone(): void
    {
        $provider = $this->provider();
        $this->buffers->open('/elsewhere/lib/Note.md', '');

        $this->assertSame([['label' => 'Note.md', 'line' => null, 'path' => '/elsewhere/lib/Note.md']], $provider->trail());
    }

    public function testAViewerTabOrATitledBufferHasNoPath(): void
    {
        $provider = $this->provider();
        $this->buffers->openPane('/p/logo.png', 'image', null);
        $this->assertSame([], $provider->trail(), 'a viewer tab');

        $this->buffers->open('/p/graph.svg', '')->title = 'Project Architecture';
        $this->assertSame([], $provider->trail(), 'a titled buffer names no file the tree holds');
    }
}
