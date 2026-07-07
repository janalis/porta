<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Config\LanguageIndent;
use Porta\Editor\Document;
use Porta\Widget\CodeEditorWidget;

/**
 * Per-language indentation: an explicit style/size wins for the file's language;
 * 'auto' takes the file's `.editorconfig` value, falling back to unix-style
 * 2 spaces. Re-applied on every document shown (buffer switch).
 */
final class CodeEditorIndentStyleTest extends TestCase
{
    /** @param array<string, LanguageIndent> $map */
    private function editor(array $map): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->setLanguageIndents($map);

        return $editor;
    }

    public function testDocumentSetIndentSetsUnitAndSize(): void
    {
        $document = new Document("    spaces\n");
        $document->setIndent(true, 3);
        $this->assertTrue($document->usesTabs());
        $this->assertSame("\t", $document->indentUnit());
        $this->assertSame(3, $document->indentSize());
        $this->assertSame(3, $document->tabWidth());

        $document->setIndent(false, 2);
        $this->assertFalse($document->usesTabs());
        $this->assertSame('  ', $document->indentUnit());
    }

    public function testExplicitStyleAndSizeWinForTheLanguage(): void
    {
        $editor = $this->editor(['php' => new LanguageIndent('tabs', 4)]);
        $editor->openFile('x.php', "    four spaces\n"); // content would detect spaces
        $this->assertTrue($editor->getDocument()->usesTabs(), 'explicit tabs win');
        $this->assertSame(4, $editor->getDocument()->indentSize());
    }

    public function testAutoWithoutEditorConfigFallsBackToTwoSpaces(): void
    {
        $editor = $this->editor(['php' => new LanguageIndent()]); // auto/auto
        $editor->openFile('x.php', "\ttabbed\n"); // content would detect tabs
        $this->assertFalse($editor->getDocument()->usesTabs(), 'auto ignores detection → spaces');
        $this->assertSame(2, $editor->getDocument()->indentSize(), 'unix fallback size 2');
    }

    public function testAutoTakesEditorConfigValues(): void
    {
        $editor = $this->editor(['php' => new LanguageIndent('auto', 'auto')]);
        $config = ResolvedEditorConfig::fromProps(['indent_style' => 'tab', 'indent_size' => '8']);
        $editor->showDocument('x.php', new Document("code\n"), editorConfig: $config);
        $this->assertTrue($editor->getDocument()->usesTabs(), 'auto uses .editorconfig indent_style');
        $this->assertSame(8, $editor->getDocument()->indentSize(), 'auto uses .editorconfig indent_size');
    }

    public function testExplicitSizeWithAutoStyleAndAConfig(): void
    {
        // Explicit size 4 wins over the config's 8; auto style takes the config's tabs.
        $editor = $this->editor(['php' => new LanguageIndent('auto', 4)]);
        $config = ResolvedEditorConfig::fromProps(['indent_style' => 'tab', 'indent_size' => '8']);
        $editor->showDocument('x.php', new Document("code\n"), editorConfig: $config);
        $this->assertTrue($editor->getDocument()->usesTabs());
        $this->assertSame(4, $editor->getDocument()->indentSize());
    }

    public function testConfigIsPerLanguageAndReAppliesAcrossBufferSwitches(): void
    {
        $editor = $this->editor([
            'php' => new LanguageIndent('tabs', 4),
            'css' => new LanguageIndent('spaces', 2),
        ]);
        $editor->openFile('a.php', "x\n");
        $this->assertTrue($editor->getDocument()->usesTabs());

        $editor->openFile('b.css', "y\n");
        $this->assertFalse($editor->getDocument()->usesTabs(), 'the switch re-applied the css config');
        $this->assertSame(2, $editor->getDocument()->indentSize());
    }

    public function testALanguageAbsentFromTheMapKeepsTheDocumentDefault(): void
    {
        // No entry for python → the editor leaves the document's own detection.
        $editor = $this->editor(['php' => new LanguageIndent('spaces', 2)]);
        $editor->openFile('x.py', "\ttabbed\n");
        $this->assertTrue($editor->getDocument()->usesTabs(), 'unconfigured language keeps auto-detection');
    }

    public function testSettingLanguageIndentsReAppliesToTheCurrentDocument(): void
    {
        $editor = $this->editor([]);
        $editor->openFile('x.php', "x\n");
        $this->assertFalse($editor->getDocument()->usesTabs());

        $editor->setLanguageIndents(['php' => new LanguageIndent('tabs', 4)]);
        $this->assertTrue($editor->getDocument()->usesTabs(), 'a live config change re-applies');
    }
}
