<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DecorationStore;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\GutterHint;
use Porta\Editor\GutterPart;
use Porta\I18n\Translator;

final class GutterHintTest extends TestCase
{
    /** Each mark says what it is and what a click does, in the marker column's own precedence. */
    public function testTheMarkerColumnSaysWhatItsMarkIs(): void
    {
        $store = new DecorationStore(static function (): void {});
        $store->setBreakpointLines([1, 2, 3], conditionalLines: [2], logpointLines: [3]);
        $store->setBookmarkLines([4, 1]);
        $store->setDebugLine(5);
        $store->setDiagnostics([
            6 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'unused', 'col' => 0]],
            1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'boom', 'col' => 0]],
        ]);
        $hint = static fn (int $line): ?string => GutterHint::for($store, $line, GutterPart::Marker, clickShowsChange: false, translator: new Translator('en'));

        $this->assertSame('Breakpoint · click to remove', $hint(1), 'over a bookmark and an error, as the column draws it');
        $this->assertSame('Conditional breakpoint · click to remove', $hint(2));
        $this->assertSame('Logpoint · click to remove', $hint(3));
        $this->assertSame('Bookmark', $hint(4));
        $this->assertSame('Paused here', $hint(5));
        $this->assertSame('▲ unused', $hint(6));
        $this->assertNull($hint(0), 'no mark, no hint');
    }

    /** A change mark names its kind, and says a click shows it only when something will. */
    public function testTheChangeStripNamesTheChange(): void
    {
        $store = new DecorationStore(static function (): void {});
        $store->setVcsMarks([0 => ChangeMarkKind::Added, 1 => ChangeMarkKind::Modified, 2 => ChangeMarkKind::Deleted]);
        $translator = new Translator('en');

        $this->assertSame('Added · click to see the change', GutterHint::for($store, 0, GutterPart::Change, clickShowsChange: true, translator: $translator));
        $this->assertSame('Modified', GutterHint::for($store, 1, GutterPart::Change, clickShowsChange: false, translator: $translator));
        $this->assertSame('Deleted', GutterHint::for($store, 2, GutterPart::Change, clickShowsChange: false, translator: $translator));
        $this->assertNull(GutterHint::for($store, 3, GutterPart::Change, clickShowsChange: true, translator: $translator));
    }
}
