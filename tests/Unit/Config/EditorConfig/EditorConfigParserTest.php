<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config\EditorConfig;

use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\EditorConfigParser;

final class EditorConfigParserTest extends TestCase
{
    public function testRootPreambleAndSections(): void
    {
        $parsed = EditorConfigParser::parse(
            "root = true\n\n# a comment\n; another comment\n[*]\nindent_style = space\nINDENT_SIZE = 2\n[*.md]\ntrim_trailing_whitespace = false\n",
        );

        $this->assertTrue($parsed['root']);
        $this->assertCount(2, $parsed['sections']);
        $this->assertSame('*', $parsed['sections'][0]['glob']);
        // Keys are lowercased, values trimmed but kept verbatim.
        $this->assertSame(['indent_style' => 'space', 'indent_size' => '2'], $parsed['sections'][0]['props']);
        $this->assertSame('*.md', $parsed['sections'][1]['glob']);
        $this->assertSame(['trim_trailing_whitespace' => 'false'], $parsed['sections'][1]['props']);
    }

    public function testRootFalseWhenAbsentOrNotTrue(): void
    {
        $this->assertFalse(EditorConfigParser::parse("[*]\nindent_size = 4\n")['root']);
        $this->assertFalse(EditorConfigParser::parse("root = 0\n[*]\n")['root']);
    }

    public function testPreambleKeysOtherThanRootAreIgnored(): void
    {
        $parsed = EditorConfigParser::parse("some_key = value\nroot = true\n[*]\nindent_size = 4\n");

        $this->assertTrue($parsed['root']);
        $this->assertCount(1, $parsed['sections']);
    }

    public function testMalformedAndEmptyKeyLinesAreSkipped(): void
    {
        $parsed = EditorConfigParser::parse("[*]\nno_equals_here\n= orphan value\nindent_size = 8\n");

        $this->assertSame(['indent_size' => '8'], $parsed['sections'][0]['props']);
    }

    public function testValueMayContainEquals(): void
    {
        $parsed = EditorConfigParser::parse("[*]\nkey = a=b=c\n");

        $this->assertSame('a=b=c', $parsed['sections'][0]['props']['key']);
    }

    public function testCrlfInputAndBlankLines(): void
    {
        $parsed = EditorConfigParser::parse("root = true\r\n\r\n[*]\r\nindent_style = tab\r\n");

        $this->assertTrue($parsed['root']);
        $this->assertSame(['indent_style' => 'tab'], $parsed['sections'][0]['props']);
    }

    public function testEmptyInputHasNoSections(): void
    {
        $parsed = EditorConfigParser::parse('');

        $this->assertFalse($parsed['root']);
        $this->assertSame([], $parsed['sections']);
    }
}
