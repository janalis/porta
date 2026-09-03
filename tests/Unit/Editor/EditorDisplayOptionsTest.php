<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\EditorDisplayOptions;
use Porta\Editor\LineNumberMode;

/**
 * The two promises the class makes and nothing was holding it to: a setter
 * repaints ONLY on a real change, and its answer says whether it changed.
 *
 * Both matter beyond tidiness. Re-applying the settings you already have —
 * which happens on every config re-apply — must not flash the pane; and the
 * widget hangs a side effect off the answer (dropping stale marks when the VCS
 * gutter goes away), so a setter that always claims "changed" would run it on a
 * no-op. Deleting either guard left the whole suite green.
 */
final class EditorDisplayOptionsTest extends TestCase
{
    public function testASetterRepaintsAndAnswersTrueOnlyOnARealChange(): void
    {
        $repaints = 0;
        $options = new EditorDisplayOptions(static function () use (&$repaints): void {
            ++$repaints;
        });

        $this->assertTrue($options->inlineDiagnostics(), 'on by default');
        $this->assertFalse($options->setInlineDiagnostics(true), 'setting the value it already holds is not a change');
        $this->assertSame(0, $repaints, 'and does not repaint');

        $this->assertTrue($options->setInlineDiagnostics(false), 'a real change answers true');
        $this->assertSame(1, $repaints, 'and repaints exactly once');
        $this->assertFalse($options->inlineDiagnostics());

        $this->assertFalse($options->setInlineDiagnostics(false), 'idempotent at the new value too');
        $this->assertSame(1, $repaints, 'still one repaint');
    }

    public function testEveryBooleanToggleGoesThroughTheSameGuard(): void
    {
        // Driven as a table rather than one test per toggle: the point is that
        // NONE of the six bypasses apply(), including one added later.
        $repaints = 0;
        $options = new EditorDisplayOptions(static function () use (&$repaints): void {
            ++$repaints;
        });

        $toggles = [
            'inlineDiagnostics' => [$options->setInlineDiagnostics(...), $options->inlineDiagnostics(...)],
            'vcsGutter' => [$options->setVcsGutter(...), $options->vcsGutter(...)],
            'stickyScroll' => [$options->setStickyScroll(...), $options->stickyScroll(...)],
            'indentGuides' => [$options->setIndentGuides(...), $options->indentGuides(...)],
            'autoPairs' => [$options->setAutoPairs(...), $options->autoPairs(...)],
            'softWrap' => [$options->setSoftWrap(...), $options->softWrap(...)],
        ];

        $expected = 0;
        foreach ($toggles as $name => [$set, $read]) {
            $current = $read();
            $this->assertFalse($set($current), $name.': re-setting the current value is not a change');
            $this->assertSame($expected, $repaints, $name.': and did not repaint');

            $this->assertTrue($set(!$current), $name.': flipping it is');
            $this->assertSame(++$expected, $repaints, $name.': which repaints once');
            $this->assertSame(!$current, $read(), $name.': and the value took');
        }
    }

    public function testTheLineNumberModeCarriesItsOwnGuard(): void
    {
        // The one setter that cannot use apply(): an enum, not a bool by
        // reference. So it repeats the guard and has to be held to it separately.
        $repaints = 0;
        $options = new EditorDisplayOptions(static function () use (&$repaints): void {
            ++$repaints;
        });

        $this->assertSame(LineNumberMode::Absolute, $options->lineNumbers(), 'absolute by default');
        $this->assertFalse($options->setLineNumbers(LineNumberMode::Absolute), 'the mode it already shows is not a change');
        $this->assertSame(0, $repaints, 'and does not repaint');

        $this->assertTrue($options->setLineNumbers(LineNumberMode::Relative));
        $this->assertSame(LineNumberMode::Relative, $options->lineNumbers());
        $this->assertSame(1, $repaints);

        $this->assertFalse($options->setLineNumbers(LineNumberMode::Relative), 'idempotent at the new mode');
        $this->assertSame(1, $repaints);
    }
}
