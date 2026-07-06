<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\DiffPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\GoToFileWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;

final class ActionCatalogTest extends TestCase
{
    /** Every widget that contributes default keybindings. */
    private const array WIDGETS = [
        CodeEditorWidget::class,
        ContextMenu::class,
        DiffPanelWidget::class,
        FileTreeWidget::class,
        FindBarWidget::class,
        GoToFileWidget::class,
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
            $method = new \ReflectionMethod($widget, 'getDefaultKeybindings');
            /** @var array<string, string[]> $bindings */
            $bindings = $method->invoke(null);
            foreach (array_keys($bindings) as $id) {
                $widgetBound[$id] = true;
            }
        }

        foreach (ActionCatalog::ACTIONS as $id => $spec) {
            if (isset($spec['keys'])) {
                $this->assertNotEmpty($spec['keys'], "Ide-scope action '{$id}' has an empty default");
                $this->assertArrayNotHasKey($id, $widgetBound, "action '{$id}' is bound both in the catalog and by a widget");
            } else {
                $this->assertArrayHasKey($id, $widgetBound, "action '{$id}' has no default binding anywhere");
            }
        }
    }

    public function testKnownActionsAreTheCatalogKeys(): void
    {
        $this->assertSame(array_keys(ActionCatalog::ACTIONS), ActionCatalog::knownActions());
    }

    public function testIdeDefaultsCarryOnlyKeyedEntries(): void
    {
        $defaults = ActionCatalog::ideDefaults();
        $this->assertSame(['ctrl+q'], $defaults['ide_quit']);
        $this->assertSame(['ctrl+t', 'f2'], $defaults['ide_toggle_focus']);
        $this->assertArrayNotHasKey('cursor_up', $defaults);
    }

    public function testLabelsMapEveryAction(): void
    {
        $labels = ActionCatalog::labels();
        $this->assertSame('Save File', $labels['save']);
        $this->assertCount(\count(ActionCatalog::ACTIONS), $labels);
    }

    public function testLabelLookup(): void
    {
        $this->assertSame('Save File', ActionCatalog::label('save'));
        // Unknown action falls back to a title-cased slug.
        $this->assertSame('Some Made Up', ActionCatalog::label('some_made_up'));
    }
}
