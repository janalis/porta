<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use Porta\Bootstrap\CorePanes;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Tests\Support\TempProject;
use Porta\Widget\HelpViewerWidget;

final class CorePanesTest extends TestCase
{
    public function testRegisterWiresTheHelpViewerLoaderIntoEditorPanes(): void
    {
        $panes = new EditorPanes();
        $helpViewer = new HelpViewerWidget();
        new CorePanes($panes, $helpViewer, new Translator())->register();

        $project = new TempProject(['help.md' => "# Hi\n"]);

        $this->assertSame("# Hi\n", $panes->load($helpViewer->paneId(), $project->path('help.md')));
    }

    public function testAMissingHelpPageIsAnEmptyStringNotAWarning(): void
    {
        $panes = new EditorPanes();
        $helpViewer = new HelpViewerWidget();
        new CorePanes($panes, $helpViewer, new Translator())->register();

        $this->assertSame('', $panes->load($helpViewer->paneId(), '/does/not/exist.md'));
    }
}
