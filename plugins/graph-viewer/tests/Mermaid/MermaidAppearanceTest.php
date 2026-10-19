<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\Theme\Khroma;
use Porta\GraphViewer\Mermaid\Theme\MermaidAppearance;
use Porta\GraphViewer\Mermaid\Theme\MermaidDirective;
use Porta\GraphViewer\Mermaid\Theme\MermaidMerge;
use Porta\GraphViewer\Mermaid\Theme\MermaidThemes;

final class MermaidAppearanceTest extends TestCase
{
    private const string REFERENCE = __DIR__.'/../fixtures/mermaid/themes/document-reference.json';

    /**
     * Each pinned document's front matter config and init directives, the variable its rendered
     * node fill shows, and the variable its stroke shows ('' where the look paints a gradient).
     *
     * @return array<string, array{string, ?string, list<string>, string, string}>
     */
    private static function documents(): array
    {
        return [
            'plain' => ['flowchart', null, [], 'mainBkg', 'nodeBorder'],
            'dark' => ['flowchart', '{"theme": "dark"}', [], 'mainBkg', ''],
            'baseOverride' => ['flowchart', '{"theme": "base", "themeVariables": {"primaryColor": "#ff0000"}}', [], 'mainBkg', ''],
            'variablesOnly' => ['flowchart', '{"themeVariables": {"primaryColor": "#ff0000"}}', [], 'mainBkg', 'nodeBorder'],
            'initAfterFrontMatter' => ['flowchart', '{"theme": "dark"}', ['{"theme": "forest"}'], 'mainBkg', ''],
            'bogusLater' => ['flowchart', '{"theme": "dark"}', ['{"theme": "bogus"}'], 'mainBkg', 'nodeBorder'],
            'scoped' => ['flowchart', '{"flowchart": {"theme": "neutral"}}', [], 'mainBkg', ''],
            'otherScope' => ['flowchart', '{"pie": {"theme": "dark"}}', [], 'mainBkg', 'nodeBorder'],
            'scopedOverGlobal' => ['flowchart', '{"theme": "dark", "flowchart": {"theme": "forest"}}', [], 'mainBkg', ''],
            'nullTheme' => ['flowchart', '{"theme": "null"}', [], 'mainBkg', 'nodeBorder'],
            'emptyTheme' => ['flowchart', '{"theme": "", "themeVariables": {"primaryColor": "#00ff00"}}', [], 'mainBkg', 'nodeBorder'],
            'fontFamily' => ['flowchart', null, ['{"theme": "base", "fontFamily": "Courier"}'], 'mainBkg', ''],
            'mergedInits' => [
                'flowchart',
                null,
                [
                    '{"theme": "base", "themeVariables": {"primaryColor": "#ff0000", "xyChart": {"titleColor": "#111111"}}}',
                    '{"themeVariables": {"secondaryColor": "#00ff00", "xyChart": {"plotColorPalette": "#222222"}}}',
                ],
                'mainBkg',
                '',
            ],
            'variablesOnlyDerived' => ['flowchart', null, ['{"themeVariables": {"background": "#000000", "git0": "#ff0000"}}'], 'mainBkg', 'nodeBorder'],
            'pieScoped' => ['pie', '{"pie": {"theme": "dark"}}', [], 'pie1', ''],
            'stateScoped' => ['state', '{"state": {"theme": "dark"}}', [], 'mainBkg', ''],
        ];
    }

    /** @param list<string> $inits */
    #[DataProvider('rendered')]
    public function testResolvedVariablesPaintLikeThePinnedDocument(string $section, ?string $frontMatter, array $inits, string $fill, string $stroke, \stdClass $expected): void
    {
        $appearance = self::document($section, $frontMatter, $inits);
        $this->assertSame(self::rgb($expected->fill), self::rgb(self::text($appearance->variables->{$fill})));
        if ('' !== $stroke) {
            $this->assertSame(self::rgb($expected->stroke), self::rgb(self::text($appearance->variables->{$stroke})));
        }
        $this->assertSame($expected->look ?? 'classic', $appearance->look);
    }

    public static function rendered(): iterable
    {
        $reference = \json_decode((string) \file_get_contents(self::REFERENCE), associative: false, flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $reference);
        foreach (self::documents() as $name => [$section, $frontMatter, $inits, $fill, $stroke]) {
            yield $name => [$section, $frontMatter, $inits, $fill, $stroke, $reference->documents->{$name}];
        }
    }

    public function testInitConfigMovesToTheDiagramTypeAndFrontMatterMergesBeneath(): void
    {
        $sanitizer = MermaidDirective::bundled();
        $flowchart = $sanitizer->document(self::object('{"flowchart": {"curve": "linear", "padding": 3}}'), [self::object('{"config": {"padding": 4}}')], 'flowchart-v2');
        $this->assertEquals(self::object('{"flowchart": {"curve": "linear", "padding": 4}}'), $flowchart);
        // The pinned move uses the detector id, which is no configuration key for state diagrams.
        $state = $sanitizer->document(null, [self::object('{"config": {"padding": 4}}')], 'stateDiagram');
        $this->assertEquals(new \stdClass(), $state);
        $single = $sanitizer->document(null, [self::object('{"theme": "dark", "themeVariables": {"nodeBorder": null}}')], 'flowchart-v2');
        $this->assertEquals(self::object('{"theme": "dark", "themeVariables": {}}'), $single);
        $several = $sanitizer->document(null, [self::object('{"theme": "dark", "bogus": 1}'), self::object('{"theme": "forest"}')], 'flowchart-v2');
        $this->assertEquals(self::object('{"theme": "forest"}'), $several);
    }

    public function testThemeChoiceAndVariableMergingFollowThePinnedConfiguration(): void
    {
        $this->assertSame('redux-color', self::appearance('flowchart', [])->theme);
        $this->assertSame('default', self::appearance('pie', [])->theme);
        $this->assertSame('classic', self::appearance('pie', [])->look);
        $this->assertSame('elk', self::appearance('flowchart', [])->layout);
        $this->assertSame('swimlane', self::appearance('swimlane', [])->layout);
        // Separate layers fall back layer by layer; one merged document hides the earlier theme.
        $this->assertSame('dark', self::appearance('flowchart', ['{"theme": "dark"}', '{"theme": "bogus"}'])->theme);
        $this->assertSame('redux-color', self::document('flowchart', '{"theme": "dark"}', ['{"theme": "bogus"}'])->theme);
        $this->assertSame('null', self::appearance('flowchart', ['{"theme": "null"}'])->theme);
        $this->assertSame('handDrawn', self::appearance('flowchart', ['{"look": "handDrawn"}', '{"look": "sketch"}'])->look);
        $this->assertSame('dagre', self::appearance('flowchart', ['{"layout": "dagre"}'])->layout);
        $merged = self::appearance('flowchart', [
            '{"theme": "base", "themeVariables": {"xyChart": {"titleColor": "#111111"}}}',
            '{"themeVariables": {"xyChart": {"plotColorPalette": "#222222"}}}',
        ])->variables;
        $this->assertSame(['#111111', '#222222'], [$merged->xyChart->titleColor, $merged->xyChart->plotColorPalette]);
        // Without a chosen theme, authored variables replace the default theme's values but derive nothing.
        $plain = self::appearance('pie', ['{"themeVariables": {"primaryColor": "#ff0000"}}'])->variables;
        $this->assertSame(['#ff0000', '#ECECFF'], [$plain->primaryColor, $plain->mainBkg]);
        $courier = self::appearance('flowchart', ['{"theme": "base", "fontFamily": "Courier"}', '{"fontFamily": "Mono", "themeVariables": {"fontFamily": "Arial"}}'])->variables;
        $this->assertSame('Arial', $courier->fontFamily);
    }

    public function testSanitizationDropsWhatThePinnedDirectiveDrops(): void
    {
        $directive = MermaidDirective::bundled()->sanitize(self::object(
            '{"theme": "base", "startOnLoad": true, "secure": ["theme"], "unknownKey": 1, "__x": 1, "constructorish": 1, "themeVariables": {"primaryColor": "url(data:x)", "secondaryColor": "#00f-", "tertiaryColor": "<b>", "nodeBorder": null, "unknownVariable": "#123456", "lineColor": "rgb(1, 2, 3)", "xyChart": {"titleColor": "<i>", "plotColorPalette": "#fff"}}, "treeView": {"nodeColors": {"a": "#fff", "b": "url(x)", "__c": "#000"}}, "themeCSS": "a { b }}"}',
        ));
        $this->assertEquals(
            self::object(
                '{"theme": "base", "themeVariables": {"primaryColor": "", "secondaryColor": "", "tertiaryColor": "", "lineColor": "rgb(1, 2, 3)", "xyChart": {"plotColorPalette": "#fff"}}, "treeView": {"nodeColors": {"a": "#fff"}}, "themeCSS": "{ /* ERROR: Unbalanced CSS */ }"}',
            ),
            $directive,
        );
        $this->expectException(\InvalidArgumentException::class);
        MermaidDirective::bundled()->sanitize(self::object('{"fontFamily": 12}'));
    }

    public function testBlankedColorsFailAsThePinnedRenderDoes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unsupported color format: ""');
        self::document('flowchart', null, ['{"theme": "base", "themeVariables": {"primaryColor": "url(data:x)"}}']);
    }

    public function testMergingKeepsJavaScriptObjectSemantics(): void
    {
        $this->assertEquals(
            self::object('{"a": {"b": 1, "c": 2}, "n": null, "o": {"x": 1}, "l": [1, 2, 3]}'),
            MermaidMerge::assign(
                self::object('{"a": {"b": 1}, "n": null, "o": {"x": 1}, "l": [1, 2]}'),
                self::object('{"a": {"c": 2}, "n": "text", "o": "text", "l": [2, 3], "skip": null}'),
            ),
        );
        $this->assertSame('source', MermaidMerge::assign(null, 'source'));
        $this->assertEquals(self::object('{"a": 1, "b": 2}'), MermaidMerge::assign(self::object('{"a": 1}'), [self::object('{"b": 2}')]));
        // Depth 1 merges the first level and replaces what lies below it, as Object.assign does.
        $this->assertEquals(self::object('{"deep": {"x": {"y": 2}}}'), MermaidMerge::assign(self::object('{"deep": {"x": {"z": 1}}}'), self::object('{"deep": {"x": {"y": 2}}}'), depth: 1));
        $this->assertEquals(self::object('{"deep": {"x": {"z": 1, "y": 2}}}'), MermaidMerge::assign(self::object('{"deep": {"x": {"z": 1}}}'), self::object('{"deep": {"x": {"y": 2}}}')));
        $this->assertEquals(
            self::object('{"a": {"b": {"c": 1, "d": 2}}, "l": [{"x": 1, "y": 2}, 3], "n": null}'),
            MermaidMerge::deep(
                self::object('{"a": {"b": {"c": 1}}, "l": [{"x": 1}], "n": 1}'),
                self::object('{"a": {"b": {"d": 2}}, "l": [{"y": 2}, 3], "n": null}'),
            ),
        );
        $this->assertEquals(self::object('{"replaced": {"y": 2}}'), MermaidMerge::assign(self::object('{"replaced": null}'), self::object('{"replaced": {"y": 2}}')));
        $this->assertSame('kept', MermaidMerge::assign('kept', self::object('{"x": 1}')));
    }

    /** @param list<string> $directives Directive objects resolved as already-assembled layers. */
    private static function appearance(string $section, array $directives): MermaidAppearance
    {
        $sanitizer = MermaidDirective::bundled();

        return MermaidAppearance::resolve(\array_map(static fn (string $json): \stdClass => $sanitizer->sanitize(self::object($json)), $directives), $section, MermaidThemes::bundled());
    }

    /** @param list<string> $inits */
    private static function document(string $section, ?string $frontMatter, array $inits): MermaidAppearance
    {
        $directive = MermaidDirective::bundled()->document(null === $frontMatter ? null : self::object($frontMatter), \array_map(self::object(...), $inits), 'flowchart-v2');

        return MermaidAppearance::resolve([$directive], $section, MermaidThemes::bundled());
    }

    private static function object(string $json): \stdClass
    {
        $object = \json_decode($json, associative: false, flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $object);

        return $object;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return list<int> */
    private static function rgb(string $color): array
    {
        return \array_map(static fn (float $channel): int => (int) Khroma::round($channel), \array_slice(Khroma::rgbaOf($color), offset: 0, length: 3));
    }
}
