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
    public function testRegisterWiresTheTextLoaderIntoTheMarkdownPane(): void
    {
        [$panes] = $this->registered();

        $project = new TempProject(['notes.md' => "# Hi\n"]);

        $this->assertSame("# Hi\n", $panes->load(MarkdownPreviewWidget::PANE_ID, $project->path('notes.md')));
    }

    public function testAMissingPageIsAnEmptyStringNotAWarning(): void
    {
        [$panes] = $this->registered();

        $this->assertSame('', $panes->load(MarkdownPreviewWidget::PANE_ID, '/does/not/exist.md'));
    }

    /** A file that is not text is refused rather than rendered as mojibake. */
    public function testAPageThatIsNotTextIsRefused(): void
    {
        [$panes] = $this->registered();
        $project = new TempProject(['binary.md' => "\xff\xfe\x00# not text"]);

        $this->expectExceptionMessageIsOrContains('Not a UTF-8 text file');
        $panes->load(MarkdownPreviewWidget::PANE_ID, $project->path('binary.md'));
    }

    /** The docs open in their own window, never as an editor pane. */
    public function testTheHelpViewerIsNotAnEditorPane(): void
    {
        [$panes] = $this->registered();

        $this->assertNull($panes->paneFor(new HelpViewerWidget()->paneId()));
    }

    public function testMarkdownIsPreviewedByTheMarkdownPaneWhateverTheCaseOfItsExtension(): void
    {
        [$panes, $routes] = $this->registered();

        $this->assertInstanceOf(MarkdownPreviewWidget::class, $panes->paneFor(MarkdownPreviewWidget::PANE_ID));
        $this->assertSame(MarkdownPreviewWidget::PANE_ID, $routes->previewFor('/p/README.md'));
        $this->assertSame(MarkdownPreviewWidget::PANE_ID, $routes->previewFor('/p/notes.MARKDOWN'));
        $this->assertNull($routes->previewFor('/p/app.php'), 'only markdown is core\'s to preview');
    }

    /** @return array{EditorPanes, PreviewRoutes} */
    private function registered(): array
    {
        $panes = new EditorPanes();
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
        new CorePanes($panes, new TextPreviewWidget(new Translator()), new MarkdownPreviewWidget(), $routes, new Translator())->register();

        return [$panes, $routes];
    }
}
