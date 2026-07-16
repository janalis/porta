<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CompletionItem;
use Porta\Editor\SelectionMode;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Locks the editor's exact ANSI output across a fixture matrix. The ordinary
 * widget tests strip ANSI, so they cannot catch SGR/styling regressions — this
 * asserts byte-for-byte equality against a committed golden file, protecting the
 * render extraction (EditorRenderer / Viewport) from any visual change.
 *
 * Regenerate the golden after an intentional visual change:
 *   UPDATE_EDITOR_GOLDEN=1 vendor/bin/phpunit tests/Unit/Widget/Render/EditorRenderGoldenTest.php
 */
final class EditorRenderGoldenTest extends TestCase
{
    private const GOLDEN = __DIR__.'/../../../Support/golden/editor-render.json';

    public function testRenderOutputMatchesGolden(): void
    {
        $actual = $this->renderAllScenarios();

        if (getenv('UPDATE_EDITOR_GOLDEN')) {
            if (!is_dir(\dirname(self::GOLDEN))) {
                mkdir(\dirname(self::GOLDEN), permissions: 0o777, recursive: true);
            }
            file_put_contents(self::GOLDEN, json_encode($actual, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            $this->markTestSkipped('Golden regenerated.');
        }

        $this->assertFileExists(self::GOLDEN, 'run once with UPDATE_EDITOR_GOLDEN=1 to seed');
        $expected = json_decode((string) file_get_contents(self::GOLDEN), associative: true);

        // Compare scenario by scenario for a readable diff on failure.
        foreach ($expected as $name => $rows) {
            $this->assertArrayHasKey($name, $actual, "scenario {$name} missing");
            $this->assertSame($rows, $actual[$name], "scenario {$name} render changed");
        }
        $this->assertSame(array_keys($expected), array_keys($actual), 'scenario set changed');
    }

    /** @return array<string, string[]> */
    private function renderAllScenarios(): array
    {
        $out = [];
        foreach ($this->scenarios() as $name => [$build, $cols, $rows]) {
            $editor = new CodeEditorWidget();
            $build($editor);
            $out[$name] = $editor->render(new RenderContext($cols, $rows));
        }

        return $out;
    }

    /**
     * @return array<string, array{0: callable(CodeEditorWidget): void, 1: int, 2: int}>
     */
    private function scenarios(): array
    {
        $php = "<?php\n\nfunction greet(string \$name): string\n{\n    return 'hi '.\$name;\n}\n";

        return [
            'plain-focused' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                },
                80,
                10,
            ],

            'plain-unfocused' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(false);
                },
                80,
                10,
            ],

            'selection' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                    $doc = $e->getDocument();
                    $doc->setCursor(2, 0);
                    $doc->beginSelect();
                    $doc->setCursor(4, 8, selection: SelectionMode::Keep);
                },
                80,
                10,
            ],

            'search-matches' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                    $e->setSearchMatches([
                        2 => [['start' => 9, 'end' => 14, 'current' => true]],
                        4 => [['start' => 4, 'end' => 10, 'current' => false]],
                    ]);
                },
                80,
                10,
            ],

            'diagnostics' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                    $e->setDiagnostics([
                        2 => [
                            ['severity' => 1, 'message' => 'bad type', 'col' => 9, 'endCol' => 14],
                            ['severity' => 2, 'message' => 'weak warning', 'col' => 0, 'endCol' => 3],
                        ],
                        4 => [['severity' => 2, 'message' => 'to end', 'col' => 4, 'endCol' => \PHP_INT_MAX]],
                        5 => [['severity' => 3, 'message' => 'info note', 'col' => 4, 'endCol' => 8]],
                        6 => [['severity' => 4, 'message' => 'hint', 'col' => 0, 'endCol' => 2]],
                    ]);
                },
                80,
                10,
            ],

            'multi-caret' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                    $e->getDocument()->setCursor(2, 0);
                    $e->handleInput("\x1b[1;7B"); // ctrl+alt+down: add caret below
                    $e->handleInput("\x1b[1;7B");
                },
                80,
                10,
            ],

            'column-selection' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                    $e->render(new RenderContext(80, 10)); // establish gutter width
                    $e->beginColumnSelectionAt(2, 6 + 4); // Alt+Shift press
                    $e->extendColumnSelectionTo(4, 6 + 10); // drag → rectangle rows 2..4
                },
                80,
                10,
            ],

            'inlay-hints' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                    $e->setInlayHints([
                        2 => [['col' => 0, 'text' => '»'], ['col' => 14, 'text' => ': string']],
                    ]);
                },
                80,
                10,
            ],

            'git-marks' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                    $e->setGitMarks([0 => 'modified', 2 => 'added', 4 => 'deleted']);
                },
                80,
                10,
            ],

            'relative-numbers' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                    $e->enableRelativeLineNumbers();
                    $e->getDocument()->setCursor(3, 0);
                },
                80,
                10,
            ],

            'tabs-and-wide' => [
                static function (CodeEditorWidget $e): void {
                    $e->openFile('t.php', "<?php\n\tif (\$好 === '😀') {\n\t\techo \$好;\n\t}\n");
                    $e->setFocused(true);
                    $e->getDocument()->setCursor(1, 1);
                },
                60,
                8,
            ],

            'completion-popup' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                    $e->getDocument()->setCursor(4, 11);
                    $e->showCompletions([
                        new CompletionItem(label: 'greeting', insertText: 'greeting', kind: 'var', detail: 'string'),
                        new CompletionItem(label: 'greetLoudly', insertText: 'greetLoudly', kind: 'fn', detail: ''),
                    ]);
                },
                80,
                10,
            ],

            'list-overlay' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                    $e->openListOverlay(
                        'Pick One',
                        [
                            ['label' => 'Alpha', 'hint' => 'a', 'value' => '0'],
                            ['label' => 'Beta', 'hint' => 'b', 'value' => '1'],
                        ],
                        static function (): void {},
                    );
                },
                80,
                10,
            ],

            'prompt' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                    $e->openPrompt('Rename to', 'greet', static function (): void {});
                },
                80,
                10,
            ],

            'signature-hint' => [
                static function (CodeEditorWidget $e) use ($php): void {
                    $e->openFile('demo.php', $php);
                    $e->setFocused(true);
                    $e->getDocument()->setCursor(4, 11);
                    $e->setSignatureHint('greet(string $name): string');
                },
                80,
                10,
            ],

            'scrolled' => [
                static function (CodeEditorWidget $e): void {
                    $lines = [];
                    for ($i = 1; $i <= 40; ++$i) {
                        $lines[] = "line {$i} content";
                    }
                    $e->openFile('long.txt', implode("\n", $lines)."\n");
                    $e->setFocused(true);
                    $e->getDocument()->setCursor(30, 3); // forces vScroll > 0
                },
                50,
                8,
            ],
        ];
    }
}
