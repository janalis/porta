<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Widget\CodeEditorWidget;

/**
 * The global indent-style setting ('auto' | 'spaces' | 'tabs'): a non-auto
 * value overrides the current document and every buffer shown afterwards;
 * 'auto' defers to the document's own auto-detection / .editorconfig.
 */
final class CodeEditorIndentStyleTest extends TestCase
{
    public function testDocumentSetUsesTabsSwitchesTheIndentUnit(): void
    {
        $document = new Document("    spaces\n"); // auto-detects spaces
        $this->assertFalse($document->usesTabs());

        $document->setUsesTabs(true);
        $this->assertTrue($document->usesTabs());
        $this->assertSame("\t", $document->indentUnit());
    }

    public function testSettingTabsOverridesTheCurrentDocument(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('x.php', "    already spaces\n");
        $this->assertFalse($editor->getDocument()->usesTabs());

        $editor->setIndentStyle('tabs');
        $this->assertTrue($editor->getDocument()->usesTabs());
    }

    public function testOverrideReAppliesToDocumentsOpenedAfterwards(): void
    {
        $editor = new CodeEditorWidget();
        $editor->setIndentStyle('tabs');
        // A brand-new file whose content would auto-detect spaces still gets tabs.
        $editor->openFile('y.php', "    four spaces\n");
        $this->assertTrue($editor->getDocument()->usesTabs(), 'the override wins over detection on open');

        $editor->setIndentStyle('spaces');
        $editor->openFile('z.php', "\ttabbed\n"); // would auto-detect tabs
        $this->assertFalse($editor->getDocument()->usesTabs(), 'spaces override wins');
    }

    public function testAutoDefersToDocumentDetection(): void
    {
        $editor = new CodeEditorWidget();
        $editor->setIndentStyle('tabs');
        $editor->setIndentStyle('auto'); // stop overriding
        $editor->openFile('a.php', "\ttabbed\n");
        $this->assertTrue($editor->getDocument()->usesTabs(), 'auto keeps the detected tabs');

        $editor->openFile('b.php', "    spaced\n");
        $this->assertFalse($editor->getDocument()->usesTabs(), 'auto keeps the detected spaces');
    }

    public function testSettingTheSameStyleIsANoOp(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('x.php', "\ttabbed\n"); // detects tabs
        $editor->setIndentStyle('auto'); // already 'auto': no change, detection preserved
        $this->assertTrue($editor->getDocument()->usesTabs());
    }
}
