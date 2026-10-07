<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DecorationStore;
use Porta\Editor\Document;
use Porta\Editor\EditorDisplayOptions;
use Porta\Editor\InlayHint;
use Porta\Editor\InlayHintKind;
use Porta\Editor\InlayHintMode;
use Porta\Editor\InlayLines;
use Porta\Editor\InlayParameterHints;

final class InlayLinesTest extends TestCase
{
    public function testOnlyInlineModeDrawsHintsIntoTheLineButEveryModeShowsThem(): void
    {
        $noop = static function (): void {};
        $store = new DecorationStore($noop);
        $display = new EditorDisplayOptions($noop);
        $hint = new InlayHint(1, ': int');
        $store->setInlayHints([0 => [$hint]]);
        $lines = new InlayLines($store, $display);
        $doc = new Document("\$x\n");

        $this->assertCount(1, $lines->forLine($doc, 0)->placed(), 'inline: drawn');
        foreach ([InlayHintMode::Tooltip, InlayHintMode::None] as $mode) {
            $display->setInlayHints($mode);
            $this->assertSame([], $lines->forLine($doc, 0)->placed(), "{$mode->value}: not drawn");
            $this->assertSame([$hint], $lines->shown($doc, 0), "{$mode->value}: still the line's hints, for a card");
        }
    }

    /** `f(42, $y, $z)`: a name before the literal, one before each variable, and a type. */
    private function lines(?EditorDisplayOptions &$display = null): InlayLines
    {
        $noop = static function (): void {};
        $store = new DecorationStore($noop);
        $display = new EditorDisplayOptions($noop);
        $store->setInlayHints([0 => [
            new InlayHint(2, 'a:', InlayHintKind::Parameter),
            new InlayHint(6, 'b:', InlayHintKind::Parameter),
            new InlayHint(10, '&c:', InlayHintKind::Parameter),
            new InlayHint(13, ': int', InlayHintKind::Type),
            new InlayHint(13, '3 uses'),
        ]]);

        return new InlayLines($store, $display);
    }

    /** @return list<string> */
    private static function labels(InlayLines $lines): array
    {
        return \array_map(static fn (InlayHint $hint): string => $hint->label, $lines->shown(new Document("f(42, \$y, \$z);\n"), 0));
    }

    public function testByDefaultAParameterIsNamedOnlyBeforeALiteralOrByReference(): void
    {
        $this->assertSame(['a:', '&c:', ': int', '3 uses'], self::labels($this->lines()));
    }

    public function testEachKindAnswersToItsOwnSetting(): void
    {
        $lines = $this->lines($display);

        $display->setInlayParameterHints(InlayParameterHints::All);
        $this->assertSame(['a:', 'b:', '&c:', ': int', '3 uses'], self::labels($lines), 'all: every name');

        $display->setInlayParameterHints(InlayParameterHints::None);
        $display->setInlayTypeHints(false);
        $this->assertSame(['3 uses'], self::labels($lines), 'a hint with no kind answers to the mode alone');
    }

    public function testALineWithoutHintsShowsNone(): void
    {
        $this->assertSame([], $this->lines()->shown(new Document("x\ny\n"), 1));
    }
}
