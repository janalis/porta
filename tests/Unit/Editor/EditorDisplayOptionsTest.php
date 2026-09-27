<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DiagnosticMessageMode;
use Porta\Editor\EditorDisplayOptions;
use Porta\Editor\InlayHintMode;
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

        $this->assertTrue($options->vcsGutter, 'on by default');
        $this->assertFalse($options->setVcsGutter(true), 'setting the value it already holds is not a change');
        $this->assertSame(0, $repaints, 'and does not repaint');

        $this->assertTrue($options->setVcsGutter(false), 'a real change answers true');
        $this->assertSame(1, $repaints, 'and repaints exactly once');
        $this->assertFalse($options->vcsGutter);

        $this->assertFalse($options->setVcsGutter(false), 'idempotent at the new value too');
        $this->assertSame(1, $repaints, 'still one repaint');
    }

    public function testEveryBooleanToggleGoesThroughTheSameGuard(): void
    {
        // Driven as a table rather than one test per toggle: the point is that
        // NONE of them bypasses apply(), including one added later.
        $repaints = 0;
        $options = new EditorDisplayOptions(static function () use (&$repaints): void {
            ++$repaints;
        });

        $toggles = [
            'vcsGutter' => [$options->setVcsGutter(...), static fn (): bool => $options->vcsGutter],
            'stickyScroll' => [$options->setStickyScroll(...), static fn (): bool => $options->stickyScroll],
            'indentGuides' => [$options->setIndentGuides(...), static fn (): bool => $options->indentGuides],
            'autoPairs' => [$options->setAutoPairs(...), static fn (): bool => $options->autoPairs],
            'softWrap' => [$options->setSoftWrap(...), static fn (): bool => $options->softWrap],
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

    public function testEveryModeGoesThroughTheSameGuard(): void
    {
        // The enum-valued options share apply() with the booleans; each is
        // driven from its default to another case and back to that case.
        $repaints = 0;
        $options = new EditorDisplayOptions(static function () use (&$repaints): void {
            ++$repaints;
        });

        $this->assertSame(LineNumberMode::Absolute, $options->lineNumbers, 'absolute by default');
        $this->assertSame(DiagnosticMessageMode::Inline, $options->diagnosticMessages, 'messages after the code by default');
        $this->assertSame(InlayHintMode::Inline, $options->inlayHints, 'hints in the line by default');

        $modes = [
            'lineNumbers' => [$options->setLineNumbers(...), static fn (): \BackedEnum => $options->lineNumbers, LineNumberMode::Relative],
            'diagnosticMessages' => [$options->setDiagnosticMessages(...), static fn (): \BackedEnum => $options->diagnosticMessages, DiagnosticMessageMode::Menu],
            'inlayHints' => [$options->setInlayHints(...), static fn (): \BackedEnum => $options->inlayHints, InlayHintMode::Tooltip],
        ];

        $expected = 0;
        foreach ($modes as $name => [$set, $read, $other]) {
            $this->assertFalse($set($read()), $name.': the mode it already shows is not a change');
            $this->assertSame($expected, $repaints, $name.': and does not repaint');

            $this->assertTrue($set($other), $name.': another mode is');
            $this->assertSame($other, $read(), $name.': and took');
            $this->assertSame(++$expected, $repaints, $name.': which repaints once');

            $this->assertFalse($set($other), $name.': idempotent at the new mode');
            $this->assertSame($expected, $repaints, $name.': still once');
        }
    }
}
