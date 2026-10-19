<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\NativeMermaidDiagram;

final class PieThemeTest extends TestCase
{
    #[DataProvider('themes')]
    public function testPinnedPalettesTypographyBackgroundAndRasterComposition(string $name, array $expected): void
    {
        $source = "---\nconfig: {theme: ".$name."}\n---\npie\n";
        foreach (\range(1, end: 12) as $index) {
            $source .= '"Slice '.$index.'":1'."\n";
        }
        $scene = NativeFixtureScene::render($source);
        $this->assertSame($expected['background'], $scene->background);
        $slices = \array_values(\array_intersect_key($scene->graph->nodes, $scene->elements));
        $this->assertCount(12, $slices);
        foreach ($slices as $index => $slice) {
            $this->assertSame($expected['pie'.($index + 1)] ?? '#000000', $slice->fillColor);
            $this->assertSame($expected['pieStrokeColor'], $slice->color);
            $this->assertSame(0.7, $slice->opacity);
            $this->assertSame(0.2, $slice->strokeWidth);
        }
        $title = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->label, 'title:'));
        $this->assertSame($expected['pieTitleTextColor'], $title->textColor);
        $this->assertSame(25 / 16, $title->fontScale);
        $caption = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => '8%' === $node->label);
        $this->assertSame($expected['pieSectionTextColor'], $caption->textColor);
        $this->assertSame(17 / 16, $caption->fontScale);
        $legend = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $node->label === $slices[0]->label && $node->id !== $slices[0]->id);
        $this->assertSame($expected['pieLegendTextColor'], $legend->textColor);
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('<rect width="100%" height="100%" fill="'.$expected['background'].'"', $svg);
        $this->assertStringContainsString('opacity="0.7"', $svg);
        $png = \imagecreatefromstring(NativeFixtureExport::render($source, GraphExportFormat::Png));
        $this->assertInstanceOf(\GdImage::class, $png);
        $this->assertSame(\hexdec(\substr($expected['background'], offset: 1)), \imagecolorat($png, x: 0, y: 0));
        $pixel = \imagecolorat($png, (int) \round($slices[0]->x * 10), (int) \round($slices[0]->y * 10));
        $rgb = (int) \hexdec(\substr($expected['pie1'], offset: 1));
        $background = (int) \hexdec(\substr($expected['background'], offset: 1));
        foreach ([16, 8, 0] as $shift) {
            $this->assertEqualsWithDelta(((($rgb >> $shift) & 255) * 0.7) + ((($background >> $shift) & 255) * 0.3), ($pixel >> $shift) & 255, delta: 2);
        }
    }

    public static function themes(): iterable
    {
        $reference = \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/pie-theme-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach ($reference['themes'] as $name => $values) {
            yield $name => [$name, $values];
        }
    }

    public function testVariableOverlaysPreserveZeroAndFrontMatterTitleStyling(): void
    {
        $source = "---\ntitle: Caption\nconfig:\n  theme: dark\n  themeVariables: {pie1: red, pie2: blue, pieOpacity: 0, pieStrokeWidth: 0, pieTitleTextSize: 32px, pieTitleTextColor: gold, pieSectionTextSize: 24, pieLegendTextSize: 20, pieOuterStrokeWidth: 0}\n---\n%%{init: {themeVariables: {pie2: green}}}%%\npie\n\"A\":1\n\"B\":1";
        $scene = NativeFixtureScene::render($source);
        $slices = \array_values(\array_intersect_key($scene->graph->nodes, $scene->elements));
        $this->assertSame(['#ff0000', '#008000'], \array_column($slices, 'fillColor'));
        $this->assertSame([0.0, 0.0], \array_column($slices, 'opacity'));
        $this->assertSame([0.0, 0.0], \array_column($slices, 'strokeWidth'));
        $title = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => 'frontmatter-title' === $node->name);
        $this->assertSame('#ffd700', $title->textColor);
        $this->assertSame(2.0, $title->fontScale);
        $this->assertSame('#333333', $scene->background);
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('fill="#ffd700" style="font-size:3.2px">Caption', $svg);
        $this->assertStringContainsString('opacity="0"', $svg);
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        $first = MermaidConfiguration::parse((object) ['theme' => 'dark', 'themeVariables' => (object) ['pie1' => 'red']], $statement);
        $second = MermaidConfiguration::parse((object) ['theme' => 'forest', 'themeVariables' => (object) ['pieOpacity' => 0]], $statement);
        $palette = new NativeMermaidDiagram("pie\n", $first->overlay($second))->palette;
        $this->assertSame('forest', $palette->appearance->theme);
        $this->assertSame('#ff0000', $palette->color('pie1'));
        $this->assertSame(0.0, $palette->number('pieOpacity', default: 1.0));
        // A theme is no family option: it applies to every diagram.
        $first->validateFamily('sequence');
    }

    public function testWideBordersAndScaledLegendsFitTheCanvas(): void
    {
        $source = "---\nconfig: {themeVariables: {pieStrokeWidth: 200, pieOuterStrokeWidth: 300, pieLegendTextSize: 48}}\n---\npie\n\"A\":1";
        $scene = NativeFixtureScene::render($source);
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => '@pie-outer' === $node->name);
        $this->assertInstanceOf(GraphNode::class, $node);
        $extent = ($node->w + $node->strokeWidth) / 2;
        $this->assertGreaterThanOrEqual(0, $node->x - $extent);
        $this->assertGreaterThanOrEqual(0, $node->y - $extent);
        $this->assertLessThanOrEqual($scene->graph->width, $node->x + $extent);
        $this->assertLessThanOrEqual($scene->graph->height, $node->y + $extent);
        $legend = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, '@pie-text:') && \str_starts_with($node->label, 'A '));
        $this->assertSame(3.0, $legend->fontScale);
        $this->assertGreaterThanOrEqual(0, $legend->x - ($legend->w / 2));
        $this->assertLessThanOrEqual($scene->graph->width, $legend->x + ($legend->w / 2));
    }

    /** @param array<string, mixed> $variables */
    #[DataProvider('invalid')]
    public function testUnusableValuesBehaveLikeIgnoredDeclarations(array $variables, string $fill, float $opacity, float $title): void
    {
        $source = "---\nconfig:\n  themeVariables: ".\json_encode((object) $variables, flags: \JSON_THROW_ON_ERROR)."\n---\npie title T\n\"A\":1";
        $scene = NativeFixtureScene::render($source);
        $slice = \array_first(\array_intersect_key($scene->graph->nodes, $scene->elements));
        $this->assertSame($fill, $slice->fillColor);
        $this->assertSame($opacity, $slice->opacity);
        $heading = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->label, 'title:'));
        $this->assertInstanceOf(GraphNode::class, $heading);
        $this->assertSame($title, $heading->fontScale);
    }

    public static function invalid(): iterable
    {
        yield 'boolean color' => [['pie1' => false], '#000000', 0.7, 25 / 16];
        yield 'unknown color' => [['pie1' => 'bogus'], '#000000', 0.7, 25 / 16];
        yield 'any CSS name' => [['pie1' => 'tomato'], '#ff6347', 0.7, 25 / 16];
        yield 'opacity clamps' => [['pieOpacity' => 2], '#ececff', 1.0, 25 / 16];
        yield 'negative size' => [['pieTitleTextSize' => -1], '#ececff', 0.7, 25 / 16];
        yield 'other units' => [['pieTitleTextSize' => '2em'], '#ececff', 0.7, 25 / 16];
        yield 'unitless size' => [['pieTitleTextSize' => 32], '#ececff', 0.7, 2.0];
    }

    public function testUnknownThemesAndKeysAreDroppedAsThePinnedDirectiveDropsThem(): void
    {
        foreach (["---\nconfig: {theme: unknown}\n---\npie\n\"A\":1", "---\nconfig: {theme: false, themeVariables: {'12': '#fff'}}\n---\npie\n\"A\":1"] as $source) {
            $scene = NativeFixtureScene::render($source);
            $this->assertSame('#ffffff', $scene->background);
            $this->assertSame('#ececff', \array_first(\array_intersect_key($scene->graph->nodes, $scene->elements))->fillColor);
        }
        $this->assertNull(NativeFixtureScene::render("---\nconfig: {themeVariables: {background: none}}\n---\npie\n\"A\":1")->background);
    }
}
