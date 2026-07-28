<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Role;
use Porta\Widget\Render\ModalRowStyle;
use Porta\Widget\Support\RowSelection;

final class ModalRowStyleTest extends TestCase
{
    public function testEachStateResolvesToItsRole(): void
    {
        $this->assertSame(Role::SelectedRow, ModalRowStyle::role(RowSelection::Selected));
        $this->assertSame(Role::RowHighlightBg, ModalRowStyle::role(RowSelection::Hovered));
        $this->assertSame(Role::PopupRow, ModalRowStyle::role(RowSelection::Unselected));
    }

    public function testSelectionWinsOverHoverWhenFoldingTheTwoFacts(): void
    {
        // The selected row under the pointer must still read as selected — the
        // precedence the modal lists used to spell out for themselves.
        $this->assertSame(RowSelection::Selected, RowSelection::of(selected: true, hovered: true));
        $this->assertSame(RowSelection::Hovered, RowSelection::of(selected: false, hovered: true));
        $this->assertSame(RowSelection::Unselected, RowSelection::of(selected: false, hovered: false));
    }
}
