<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Editor\IndentKind;
use Porta\Editor\IndentModel;

final class IndentModelTest extends TestCase
{
    /**
     * A blank line does not end the detection of the lines below it.
     *
     * The scan skips a blank line — it indents nothing and says nothing — and
     * turning that `continue` into a `break` left every case here green,
     * because each passes lines that are all indented. Nearly every real source
     * file has a blank line near the top, and stopping there means the indent
     * comes from whatever preceded it, or from the default: a file written with
     * two spaces would be edited as if it used four.
     */
    public function testABlankLineDoesNotStopTheScanBelowIt(): void
    {
        $model = IndentModel::detect(['<?php', '', 'class A', '{', '  public $x;', '    public $y;'], 4);

        $this->assertSame(2, $model->indentSize(), 'the two-space step below the blank line was still seen');
        $this->assertFalse($model->usesTabs());
    }

    public function testUnitAndDisplayWidthForSpaces(): void
    {
        $indent = new IndentModel(usesTabs: false, indentSize: 2, tabWidth: 8);

        $this->assertFalse($indent->usesTabs());
        $this->assertSame('  ', $indent->unit());
        $this->assertSame(2, $indent->indentSize());
        $this->assertSame(8, $indent->tabWidth());
        $this->assertSame(2, $indent->displayWidth(), 'space files display the indent size');
    }

    public function testUnitAndDisplayWidthForTabs(): void
    {
        $indent = new IndentModel(usesTabs: true, indentSize: 2, tabWidth: 8);

        $this->assertSame("\t", $indent->unit());
        $this->assertSame(8, $indent->displayWidth(), 'tab files display the tab width');
    }

    public function testSetClampsSizeToAtLeastOne(): void
    {
        $indent = new IndentModel(usesTabs: false, indentSize: 4, tabWidth: 4);
        $indent->set(IndentKind::Spaces, 0);

        $this->assertSame(1, $indent->indentSize());
        $this->assertSame(1, $indent->tabWidth());
    }

    public function testSetWithTabsKindSwitchesToTabs(): void
    {
        $indent = new IndentModel(usesTabs: false, indentSize: 4, tabWidth: 4);
        $indent->set(IndentKind::Tabs, 4);

        $this->assertTrue($indent->usesTabs());
    }

    public function testUnitForAZeroIndentSizeIsEmpty(): void
    {
        // Not reachable through set() (which clamps to >=1), but the field is
        // otherwise a plain int — unit() must not turn 0 into a phantom space.
        $indent = new IndentModel(usesTabs: false, indentSize: 0, tabWidth: 1);

        $this->assertSame('', $indent->unit());
    }

    public function testUnitForANegativeIndentSizeIsStillEmptyNotNegativeRepeat(): void
    {
        $indent = new IndentModel(usesTabs: false, indentSize: -1, tabWidth: 1);

        $this->assertSame('', $indent->unit());
    }

    public function testApplyEditorConfigOverridesOnlySpecifiedFields(): void
    {
        $indent = new IndentModel(usesTabs: false, indentSize: 4, tabWidth: 4);
        $indent->applyEditorConfig(ResolvedEditorConfig::fromProps(['tab_width' => '8']));

        $this->assertFalse($indent->usesTabs(), 'unspecified style untouched');
        $this->assertSame(4, $indent->indentSize(), 'unspecified indent size untouched');
        $this->assertSame(8, $indent->tabWidth());
    }

    public function testApplyEditorConfigSetsStyleAndIndentSize(): void
    {
        $indent = new IndentModel(usesTabs: false, indentSize: 4, tabWidth: 4);
        $indent->applyEditorConfig(ResolvedEditorConfig::fromProps(['indent_style' => 'tab', 'indent_size' => '3']));

        $this->assertTrue($indent->usesTabs());
        $this->assertSame(3, $indent->indentSize());
    }

    public function testApplyEditorConfigNoneLeavesEverythingUntouched(): void
    {
        $indent = new IndentModel(usesTabs: true, indentSize: 2, tabWidth: 6);
        $indent->applyEditorConfig(ResolvedEditorConfig::none());

        $this->assertTrue($indent->usesTabs());
        $this->assertSame(2, $indent->indentSize());
        $this->assertSame(6, $indent->tabWidth());
    }
}
