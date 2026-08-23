<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config\EditorConfig;

use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\ResolvedEditorConfig;

final class ResolvedEditorConfigTest extends TestCase
{
    public function testNoneIsAllNull(): void
    {
        $c = ResolvedEditorConfig::none();

        $this->assertNull($c->usesTabs);
        $this->assertNull($c->indentSize);
        $this->assertNull($c->tabWidth);
        $this->assertNull($c->eol);
        $this->assertNull($c->trimTrailingWhitespace);
        $this->assertNull($c->insertFinalNewline);
        $this->assertNull($c->charset);
    }

    public function testIndentStyle(): void
    {
        $this->assertTrue(ResolvedEditorConfig::fromProps(['indent_style' => 'tab'])->usesTabs);
        $this->assertFalse(ResolvedEditorConfig::fromProps(['indent_style' => 'SPACE'])->usesTabs);
        $this->assertNull(ResolvedEditorConfig::fromProps(['indent_style' => 'weird'])->usesTabs);
    }

    public function testNumericIndentSizeDefaultsTabWidth(): void
    {
        $c = ResolvedEditorConfig::fromProps(['indent_size' => '2']);

        $this->assertSame(2, $c->indentSize);
        $this->assertSame(2, $c->tabWidth, 'tab_width defaults to a numeric indent_size');
    }

    public function testExplicitTabWidthKept(): void
    {
        $c = ResolvedEditorConfig::fromProps(['indent_size' => '4', 'tab_width' => '8']);

        $this->assertSame(4, $c->indentSize);
        $this->assertSame(8, $c->tabWidth);
    }

    public function testIndentSizeTabFollowsTabWidth(): void
    {
        $c = ResolvedEditorConfig::fromProps(['indent_size' => 'tab', 'tab_width' => '8']);

        $this->assertSame(8, $c->indentSize);
        $this->assertSame(8, $c->tabWidth);
    }

    public function testIndentSizeTabWithoutTabWidthStaysNull(): void
    {
        $c = ResolvedEditorConfig::fromProps(['indent_size' => 'tab']);

        $this->assertNull($c->indentSize);
        $this->assertNull($c->tabWidth);
    }

    public function testTabWidthOnly(): void
    {
        $c = ResolvedEditorConfig::fromProps(['tab_width' => '3']);

        $this->assertNull($c->indentSize);
        $this->assertSame(3, $c->tabWidth);
    }

    public function testNonNumericAndZeroSizes(): void
    {
        $this->assertNull(ResolvedEditorConfig::fromProps(['indent_size' => 'huge'])->indentSize);
        $this->assertSame(1, ResolvedEditorConfig::fromProps(['indent_size' => '0'])->indentSize, 'clamped to at least 1');
    }

    public function testEndOfLine(): void
    {
        $this->assertSame('lf', ResolvedEditorConfig::fromProps(['end_of_line' => 'LF'])->eol);
        $this->assertSame('crlf', ResolvedEditorConfig::fromProps(['end_of_line' => 'crlf'])->eol);
        $this->assertSame('cr', ResolvedEditorConfig::fromProps(['end_of_line' => 'cr'])->eol);
        $this->assertNull(ResolvedEditorConfig::fromProps(['end_of_line' => 'nel'])->eol);
    }

    public function testBooleanProps(): void
    {
        $this->assertTrue(ResolvedEditorConfig::fromProps(['trim_trailing_whitespace' => 'TRUE'])->trimTrailingWhitespace);
        $this->assertFalse(ResolvedEditorConfig::fromProps(['trim_trailing_whitespace' => 'false'])->trimTrailingWhitespace);
        $this->assertNull(ResolvedEditorConfig::fromProps(['trim_trailing_whitespace' => 'maybe'])->trimTrailingWhitespace);
        $this->assertTrue(ResolvedEditorConfig::fromProps(['insert_final_newline' => 'true'])->insertFinalNewline);
    }

    public function testCharset(): void
    {
        $this->assertSame('utf-8', ResolvedEditorConfig::fromProps(['charset' => 'UTF-8'])->charset);
        $this->assertNull(ResolvedEditorConfig::fromProps(['charset' => ''])->charset);
        $this->assertNull(ResolvedEditorConfig::fromProps([])->charset);
    }

    public function testValuesAreTrimmedOfSurroundingWhitespace(): void
    {
        // The parser already trims, but fromProps() is a public entry point
        // in its own right — it must not assume a pre-trimmed caller.
        $c = ResolvedEditorConfig::fromProps([
            'indent_size' => ' TAB ',
            'tab_width' => '8',
            'indent_style' => ' tab ',
            'end_of_line' => ' lf ',
            'charset' => ' UTF-8 ',
            'trim_trailing_whitespace' => ' true ',
        ]);

        $this->assertSame(8, $c->indentSize, '"indent_size = tab" keyword, case- and whitespace-insensitive');
        $this->assertTrue($c->usesTabs);
        $this->assertSame('lf', $c->eol);
        $this->assertSame('utf-8', $c->charset);
        $this->assertTrue($c->trimTrailingWhitespace);
    }

    public function testNumericIndentSizeWithSurroundingWhitespaceIsStillNumeric(): void
    {
        $this->assertSame(4, ResolvedEditorConfig::fromProps(['indent_size' => ' 4 '])->indentSize);
    }

    public function testToPositiveIntRejectsATrailingNonDigitSuffix(): void
    {
        // A digit run must span the WHOLE trimmed value, not just its start.
        $this->assertNull(ResolvedEditorConfig::fromProps(['indent_size' => '5abc'])->indentSize);
    }

    public function testToPositiveIntRejectsALeadingNonDigitPrefix(): void
    {
        // ...nor just its end.
        $this->assertNull(ResolvedEditorConfig::fromProps(['indent_size' => 'abc5'])->indentSize);
    }

    public function testTabWidthOwnTrimIsNotMaskedByIndentSizesUpstreamTrim(): void
    {
        // Unlike indent_size, tab_width is never pre-trimmed before reaching
        // toPositiveInt() — it's the only property that exercises that
        // method's OWN trim(), independent of fromProps()'s outer one.
        $this->assertSame(8, ResolvedEditorConfig::fromProps(['tab_width' => ' 8 '])->tabWidth);
        $this->assertNull(ResolvedEditorConfig::fromProps(['tab_width' => '5abc'])->tabWidth);
        $this->assertNull(ResolvedEditorConfig::fromProps(['tab_width' => 'abc5'])->tabWidth);
    }
}
