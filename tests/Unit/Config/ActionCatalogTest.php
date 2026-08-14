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

    public function testEveryGitVerbIsAdoptableUnderItsRealLabel(): void
    {
        // The checklist the move is measured against, and it has to be a list
        // rather than a rule: adoptCommand() REFUSES an id with no 'adoptable'
        // entry, so a verb missing from here cannot be vacated at all — the
        // plugin throws at activate() and every git command disappears.
        //
        // The labels matter as much as the flags. label() falls back to
        // ucwords(str_replace('_', ' ', $id)) for an unknown id, so a verb that
        // core stopped registering and the catalog never named would quietly
        // read "Git Commit" in the palette, the menu and the keymap page.
        $expected = [
            'git_blame' => 'Git: Blame Current Line',
            'git_branches' => 'Git: Branches…',
            'git_commit' => 'Git: Commit…',
            'git_fetch' => 'Git: Fetch',
            'git_file_history' => 'Git: File History',
            'git_pull' => 'Git: Pull',
            'git_push' => 'Git: Push',
            'git_push_upstream' => 'Git: Push (Set Upstream)',
            'git_remote_cancel' => 'Git: Cancel Remote Operation',
            'git_stage' => 'Git: Stage Current File',
            'git_stash' => 'Git: Stash',
            'git_stash_pop' => 'Git: Stash Pop',
            'git_stashes' => 'Git: Stashes…',
            'git_toggle_blame' => 'Git: Toggle Blame Annotations',
            'git_undo_commit' => 'Git: Undo Last Commit',
        ];

        $actual = [];
        /** @var array<string, array<string, mixed>> $actions */
        $actions = Probe::getStatic(ActionCatalog::class, 'ACTIONS');
        foreach ($actions as $id => $action) {
            if (!str_starts_with((string) $id, 'git_')) {
                continue;
            }

            $actual[$id] = ActionCatalog::label((string) $id);
            $this->assertTrue(ActionCatalog::isAdoptable((string) $id), $id.' must be adoptable before the plugin can implement it');
        }
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    public function testTheGitViewToggleIsAdoptableButItsTwoNeighboursAreNot(): void
    {
        // ide_git flipped when the git VIEW became the git plugin's: nothing in
        // core implements it any more, and outside a repository the plugin
        // registers neither the view nor the verb — so MenuModel skipping the
        // unregistered item is exactly right, in the same case where the whole
        // Git group is hidden.
        $this->assertTrue(ActionCatalog::isAdoptable('ide_git'));
        $this->assertSame('Git: Changes', ActionCatalog::label('ide_git'));

        // The contrast that keeps this honest: the other two view toggles are
        // core's own views, always registered, and must never be adoptable.
        $this->assertFalse(ActionCatalog::isAdoptable('ide_settings'));
        $this->assertFalse(ActionCatalog::isAdoptable('ide_welcome'));
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
