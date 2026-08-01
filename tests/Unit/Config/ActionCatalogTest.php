<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;

final class ActionCatalogTest extends TestCase
{
    /**
     * Every CORE widget that contributes default keybindings.
     *
     * A plugin's pane binds catalog actions too — the two viewers' nineteen —
     * and asserting that here would make a core test import plugin code. The
     * plugin's own suite pins its half instead, the same direction as the
     * architecture-filename agreement.
     */
    private const array WIDGETS = [
        CodeEditorWidget::class,
        ContextMenu::class,
        FileTreeWidget::class,
        FindBarWidget::class,
        HelpViewerWidget::class,
        MenuBarWidget::class,
        ResultsPanelWidget::class,
        TerminalPanelWidget::class,
    ];

    public function testEveryActionHasANonEmptyLabel(): void
    {
        foreach (ActionCatalog::ACTIONS as $id => $spec) {
            $this->assertNotSame('', $spec['label'], "empty label for action '{$id}'");
        }
    }

    /**
     * Every rebindable action is default-bound in exactly one place: entries
     * with 'keys' are Ide-scope (no widget may also bind them), entries
     * without are widget-scope (some widget must bind them). This keeps the
     * catalog honest against the widgets' getDefaultKeybindings().
     */
    public function testEveryActionIsDefaultBoundInExactlyOnePlace(): void
    {
        $widgetBound = [];
        foreach (self::WIDGETS as $widget) {
            /** @var array<string, string[]> $bindings */
            $bindings = Probe::invokeStatic($widget, 'getDefaultKeybindings');
            foreach (array_keys($bindings) as $id) {
                $widgetBound[$id] = true;
            }
        }

        foreach (ActionCatalog::ACTIONS as $id => $spec) {
            if (str_starts_with($id, 'graph_') || str_starts_with($id, 'image_')) {
                continue; // bound by a viewer plugin's pane; each is pinned in its own suite
            }
            if (!array_key_exists('keys', $spec)) {
                // An ADOPTABLE id needs no binding at all: core names the verb,
                // a plugin implements it, and it is reached from the palette and
                // its menu item. The rule below asks whether a keyless action is
                // INVOCABLE, and for those the answer is yes without a key.
                if (ActionCatalog::isAdoptable($id)) {
                    $this->assertArrayNotHasKey($id, $widgetBound, "adoptable action '{$id}' must not also be widget-bound");
                    continue;
                }
                $this->assertArrayHasKey($id, $widgetBound, "action '{$id}' has no default binding anywhere");
                continue;
            }
            $this->assertNotEmpty($spec['keys'], "Ide-scope action '{$id}' has an empty default");
            $this->assertArrayNotHasKey($id, $widgetBound, "action '{$id}' is bound both in the catalog and by a widget");
        }
    }

    public function testIdeDefaultsCarryOnlyKeyedEntries(): void
    {
        $defaults = ActionCatalog::ideDefaults();
        $this->assertSame(['ctrl+q'], $defaults['ide_quit']);
        $this->assertSame(['ctrl+t', 'f2'], $defaults['ide_toggle_focus']);
        $this->assertArrayNotHasKey('cursor_up', $defaults);
    }

    public function testEveryActionCarriesALabel(): void
    {
        $this->assertSame('Save File', ActionCatalog::label('save'));
        foreach (ActionCatalog::ACTIONS as $spec) {
            $this->assertNotSame('', $spec['label']);
        }
    }

    public function testLabelLookup(): void
    {
        $this->assertSame('Save File', ActionCatalog::label('save'));
        // Unknown action falls back to a title-cased slug.
        $this->assertSame('Some Made Up', ActionCatalog::label('some_made_up'));
    }
}
