<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Editor\Document;

final class DocumentEditorConfigTest extends TestCase
{
    private function config(array $props): ResolvedEditorConfig
    {
        return ResolvedEditorConfig::fromProps($props);
    }

    public function testIndentSizeChangesTheSoftTabUnit(): void
    {
        $doc = new Document('x'); // no indentation -> auto-detects spaces
        $this->assertSame('    ', $doc->indentUnit(), 'default is 4 spaces');

        $doc->applyEditorConfig($this->config(['indent_style' => 'space', 'indent_size' => '2']));

        $this->assertFalse($doc->usesTabs());
        $this->assertSame(2, $doc->indentSize());
        $this->assertSame('  ', $doc->indentUnit());
        $this->assertSame(2, $doc->indentDisplayWidth());
    }

    public function testTabWidthChangesDisplayMathAndInvalidatesCache(): void
    {
        $doc = new Document("\tx"); // leading tab -> auto-detects tabs
        $this->assertTrue($doc->usesTabs());
        $this->assertSame(5, $doc->lineDisplayWidth(0), 'tab expands to 4 columns by default');
        $this->assertSame(str_repeat(' ', times: 4).'x', $doc->getVisualLines()[0]);

        $doc->applyEditorConfig($this->config(['tab_width' => '8']));

        $this->assertSame(8, $doc->tabWidth());
        $this->assertSame(8, $doc->indentDisplayWidth(), 'tabbed files use tab_width for indent display');
        $this->assertSame("\t", $doc->indentUnit(), 'still a hard tab');
        $this->assertSame(9, $doc->lineDisplayWidth(0), 'tab now expands to 8 columns');
        $this->assertSame(str_repeat(' ', times: 8).'x', $doc->getVisualLines()[0], 'visual cache re-expanded');
    }

    public function testIndentStyleOverridesAutoDetection(): void
    {
        $doc = new Document("\tx"); // auto-detected as tabs
        $doc->applyEditorConfig($this->config(['indent_style' => 'space', 'indent_size' => '3']));

        $this->assertFalse($doc->usesTabs());
        $this->assertSame('   ', $doc->indentUnit());
    }

    public function testNullPropsLeaveDefaultsUntouched(): void
    {
        $doc = new Document('  x'); // auto-detected as spaces
        $doc->applyEditorConfig(ResolvedEditorConfig::none());

        $this->assertFalse($doc->usesTabs());
        $this->assertSame(4, $doc->indentSize());
        $this->assertSame(4, $doc->tabWidth());
        $this->assertSame('    ', $doc->indentUnit());
    }
}
