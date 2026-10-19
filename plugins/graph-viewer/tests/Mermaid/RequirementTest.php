<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\RequirementDiagram;
use Janalis\Viz\Mermaid\RequirementLexer;
use Janalis\Viz\Mermaid\RequirementParser;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class RequirementTest extends TestCase
{
    private static function parse(string $source): RequirementDiagram
    {
        $parser = new RequirementParser();
        foreach (new RequirementLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testPinnedUpstreamSample(): void
    {
        $source = \file_get_contents(__DIR__.'/../fixtures/mermaid/requirement/sample.mmd');
        $this->assertIsString($source);
        $diagram = self::parse($source);
        $this->assertCount(5, $diagram->nodes);
        $this->assertCount(4, $diagram->relations);
        $this->assertSame('test_entity2', $diagram->relations[3]['from']);
        $this->assertSame('test_req', $diagram->relations[3]['to']);
        $this->assertSame('medium', $diagram->nodes['test_req3']['fields']['risk']);
        $this->assertSame('inspection', $diagram->nodes['test_req2']['fields']['verifymethod']);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
    }

    public function testTerminalAndNativeExports(): void
    {
        $source = "requirementDiagram\nrequirement safe { text: \"<script>&amp;\"\n}\nelement code {}\ncode - satisfies -> safe";
        $rows = NativeFixtureRows::render($source, width: 80);
        $this->assertNotEmpty($rows->rows);
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('&lt;script&gt;&amp;', $svg);
        $this->assertStringContainsString('&lt;&lt;satisfies&gt;&gt;', $svg);
        $this->assertStringNotContainsString('<script>', $svg);
        $png = NativeFixtureExport::render($source, Format::Png);
        $this->assertStringStartsWith("\x89PNG", $png);
    }
}
