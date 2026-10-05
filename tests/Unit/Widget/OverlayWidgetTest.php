<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\TestOverlayWidget;

final class OverlayWidgetTest extends TestCase
{
    /** isVisible()/dismiss() are called from outside the hierarchy (BottomDock, RunController) — must stay public. */
    public function testIsVisibleAndDismissAreCallableFromOutsideTheClass(): void
    {
        $widget = new TestOverlayWidget();
        $widget->open();

        $this->assertTrue($widget->isVisible());
        $widget->dismiss();
        $this->assertFalse($widget->isOpen());
    }

    public function testOpenFiresTheOnOpenCallbacksAndInvalidates(): void
    {
        $widget = new TestOverlayWidget();
        $fired = false;
        $widget->onOpen(static function () use (&$fired): void {
            $fired = true;
        });
        $before = $widget->getRenderRevision();

        $widget->open();

        $this->assertTrue($fired, 'the onOpen callback must run');
        $this->assertGreaterThan($before, $widget->getRenderRevision());
    }

    public function testCloseFiresTheOnCloseCallbacksAndInvalidates(): void
    {
        $widget = new TestOverlayWidget();
        $widget->open();
        $fired = false;
        $widget->onClose(static function () use (&$fired): void {
            $fired = true;
        });
        $before = $widget->getRenderRevision();

        $widget->close();

        $this->assertTrue($fired);
        $this->assertGreaterThan($before, $widget->getRenderRevision());
        $this->assertFalse($widget->isOpen());
    }

    public function testClosingAnAlreadyClosedWidgetIsANoOp(): void
    {
        $widget = new TestOverlayWidget();
        $fired = false;
        $widget->onClose(static function () use (&$fired): void {
            $fired = true;
        });

        $widget->close(); // never opened

        $this->assertFalse($fired, 'onClose must not fire for a widget that was never open');
    }

    public function testCloseResetsRevealedToTrueEvenIfTheWidgetWasConcealed(): void
    {
        $widget = new TestOverlayWidget();
        $widget->open();
        $widget->conceal(); // revealed = false
        $widget->close(); // must reset revealed = true, not leave it false

        // conceal() itself no-ops when already !revealed — so calling it again
        // only takes effect (and invalidates) if close() truly reset revealed
        // back to true. Reopening would mask the bug (markOpen() always sets
        // revealed = true unconditionally), so we deliberately do NOT reopen.
        $before = $widget->getRenderRevision();
        $widget->conceal();
        $this->assertGreaterThan($before, $widget->getRenderRevision(), 'close() must leave revealed = true, not false');
    }

    public function testDismissFallsBackToCloseWhenNoOnDismissHookIsWired(): void
    {
        $widget = new TestOverlayWidget();
        $widget->open();

        $widget->dismiss();

        $this->assertFalse($widget->isOpen(), 'no onDismiss hook → dismiss() closes for real');
    }

    public function testDismissPrefersTheOnDismissHookOverClosing(): void
    {
        $widget = new TestOverlayWidget();
        $widget->open();
        $ran = false;
        $widget->onDismiss(static function () use (&$ran): void {
            $ran = true;
        });

        $widget->dismiss();

        $this->assertTrue($ran);
        $this->assertTrue($widget->isOpen(), 'the hook replaces close(), so the widget stays open');
    }
}
