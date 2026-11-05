<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Lsp\WorkspaceEditor;

final class TextEditOrderTest extends TestCase
{
    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string} */
    private static function edit(int $line, int $from, int $to, string $text): array
    {
        return ['range' => ['start' => ['line' => $line, 'character' => $from], 'end' => ['line' => $line, 'character' => $to]], 'newText' => $text];
    }

    public function testEditsAtOnePositionReadInArrayOrder(): void
    {
        $document = new Document("<?php\nclass A {}\n");

        $this->assertTrue(WorkspaceEditor::applyTextEdits($document, [self::edit(1, 0, 0, "use A;\n"), self::edit(1, 0, 0, "use B;\n")]));

        $this->assertSame("<?php\nuse A;\nuse B;\nclass A {}\n", $document->getText(), 'two imports on one line, in the order sent');
    }

    public function testAnInsertBesideAReplaceAtTheSameSpotKeepsItsPlace(): void
    {
        $document = new Document('foo(x)');

        $this->assertTrue(WorkspaceEditor::applyTextEdits($document, [self::edit(0, 4, 4, '$a, '), self::edit(0, 4, 5, 'y')]));

        $this->assertSame('foo($a, y)', $document->getText());
    }

    public function testADeferredImportPairLandsInOrderToo(): void
    {
        $document = new Document("<?php\nclass A {}\n");

        $document->applyEditsKeepingCursor([self::edit(1, 0, 0, "use A;\n"), self::edit(1, 0, 0, "use B;\n")]);

        $this->assertSame("<?php\nuse A;\nuse B;\nclass A {}\n", $document->getText());
    }
}
