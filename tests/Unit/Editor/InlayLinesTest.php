<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DecorationStore;
use Porta\Editor\Document;
use Porta\Editor\EditorDisplayOptions;
use Porta\Editor\InlayHint;
use Porta\Editor\InlayHintMode;
use Porta\Editor\InlayLines;

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
            $this->assertSame([$hint], $lines->shown(0), "{$mode->value}: still the line's hints, for a card");
        }
    }
}
