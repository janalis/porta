<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\Document;
use Porta\Editor\PreviewMode;

final class PreviewModeTest extends TestCase
{
    public function testTheKeyStepsThroughTheArrangementsInTheOrderTheTogglesRead(): void
    {
        $this->assertSame(PreviewMode::Split, PreviewMode::Editor->next());
        $this->assertSame(PreviewMode::Preview, PreviewMode::Split->next());
        $this->assertSame(PreviewMode::Editor, PreviewMode::Preview->next(), 'and wraps');
    }

    public function testOnlyTheTextAloneHidesThePreview(): void
    {
        $this->assertFalse(PreviewMode::Editor->showsPreview());
        $this->assertTrue(PreviewMode::Split->showsPreview());
        $this->assertTrue(PreviewMode::Preview->showsPreview());
    }

    public function testABufferHasAnArrangementOnlyWhenSomethingPreviewsItAndItIsText(): void
    {
        $text = new Buffer('/p/README.md', new Document('# hi'));
        $this->assertNull($text->previewArrangement(), 'nothing previews it');

        $text->previewId = 'markdown';
        $this->assertSame(PreviewMode::Split, $text->previewArrangement(), 'a new tab opens beside its preview');
        $text->previewMode = PreviewMode::Preview;
        $this->assertSame(PreviewMode::Preview, $text->previewArrangement());

        $viewer = new Buffer('/p/g.dot', new Document(''), paneId: 'graph');
        $viewer->previewId = 'graph';
        $this->assertNull($viewer->previewArrangement(), 'a viewer tab IS its pane: nothing to arrange');
    }
}
