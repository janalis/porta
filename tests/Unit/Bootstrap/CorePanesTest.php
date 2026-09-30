<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use Porta\Bootstrap\CorePanes;
use Porta\Editor\EditorPanes;
use Porta\Editor\PreviewRoutes;
use Porta\Editor\ViewerProviderSource;
use Porta\I18n\Translator;
use Porta\Tests\Support\TempProject;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\MarkdownPreviewWidget;
use Porta\Widget\TextPreviewWidget;

final class CorePanesTest extends TestCase
{
    public function testRegisterWiresTheHelpViewerLoaderIntoEditorPanes(): void
    {
        [$panes, $helpViewer] = $this->registered();

        $project = new TempProject(['help.md' => "# Hi\n"]);

        $this->assertSame("# Hi\n", $panes->load($helpViewer->paneId(), $project->path('help.md')));
    }

    public function testAMissingHelpPageIsAnEmptyStringNotAWarning(): void
    {
        [$panes, $helpViewer] = $this->registered();

        $this->assertSame('', $panes->load($helpViewer->paneId(), '/does/not/exist.md'));
    }

    public function testMarkdownIsPreviewedByTheMarkdownPaneWhateverTheCaseOfItsExtension(): void
    {
        [$panes, , $routes] = $this->registered();

        $this->assertInstanceOf(MarkdownPreviewWidget::class, $panes->paneFor(MarkdownPreviewWidget::PANE_ID));
        $this->assertSame(MarkdownPreviewWidget::PANE_ID, $routes->previewFor('/p/README.md'));
        $this->assertSame(MarkdownPreviewWidget::PANE_ID, $routes->previewFor('/p/notes.MARKDOWN'));
        $this->assertNull($routes->previewFor('/p/app.php'), 'only markdown is core\'s to preview');
    }

    /** @return array{EditorPanes, HelpViewerWidget, PreviewRoutes} */
    private function registered(): array
    {
        $panes = new EditorPanes();
        $helpViewer = new HelpViewerWidget();
        $routes = new PreviewRoutes(new class implements ViewerProviderSource {
            public function viewerFor(string $path): ?string
            {
                return null;
            }

            public function previewFor(string $path): ?string
            {
                return null;
            }
        });
        new CorePanes($panes, $helpViewer, new TextPreviewWidget(new Translator()), new MarkdownPreviewWidget(), $routes, new Translator())->register();

        return [$panes, $helpViewer, $routes];
    }
}
