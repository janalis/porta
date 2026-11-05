<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Config\KeymapPlatform;
use Porta\Config\PlatformKeymap;
use Porta\Widget\EditorKeymap;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Input\KeyParser;

final class PlatformKeymapTest extends TestCase
{
    /** Chords macOS or the terminal keeps for itself: binding one would be dead at best, and Cmd+Q quits the terminal. */
    private const array RESERVED = ['super+q', 'super+w', 'super+t', 'super+n', 'super+h', 'super+m', 'super+,', 'super+`', 'super+tab', 'super+space'];

    public function testWindowsAndLinuxLeadWithAKeyTheirTerminalsHandOver(): void
    {
        // Windows Terminal keeps Alt+Enter and Ctrl+Shift+V; the Linux
        // terminals keep Ctrl+Shift+V.
        $windows = PlatformKeymap::overrides(KeymapPlatform::Windows);
        $this->assertSame(['alt+i', 'alt+enter'], $windows['code_action']);
        $this->assertSame(['alt+y', 'ctrl+shift+v'], $windows['paste_history']);
        $this->assertArrayNotHasKey('git_push', $windows, 'Alt+K everywhere: Ctrl+Shift+K is the editor\'s Delete Line');

        $linux = PlatformKeymap::overrides(KeymapPlatform::Linux);
        $this->assertArrayNotHasKey('code_action', $linux, 'Alt+Enter reaches the IDE there');
        $this->assertSame(['alt+y', 'ctrl+shift+v'], $linux['paste_history']);
        $this->assertSame($linux, PlatformKeymap::withoutCmd(KeymapPlatform::Linux), 'no Cmd chord to leave out');
    }

    public function testNoAddedKeyIsOneAShippedActionAlreadyUses(): void
    {
        $shipped = ActionCatalog::ideDefaults() + EditorKeymap::defaults();
        $taken = \array_merge(...\array_values($shipped));
        foreach ([KeymapPlatform::Windows, KeymapPlatform::Linux, KeymapPlatform::MacOs] as $platform) {
            // The keymap list's own reset key shares Backspace on purpose:
            // its filter takes Backspace first while it is open.
            foreach (\array_diff_key(PlatformKeymap::overrides($platform), ['reset_binding' => true]) as $action => $keys) {
                $added = \array_diff($keys, $shipped[$action] ?? []);
                $this->assertSame([], \array_values(\array_intersect($added, $taken)), $platform->value.' '.$action.' takes a key something else ships with');
            }
        }
    }

    /**
     * An IDE key runs before the editor's, so one the editor also binds hides
     * it — Ctrl+Shift+K pushed to the remote where the docs promised Delete
     * Line. The pairs below are each enabled only where the editor is not
     * reading keys: the tree has focus, or a debug session is stopped.
     */
    public function testNoIdeKeyHidesAnEditorKeyOutsideItsOwnContext(): void
    {
        $contextual = ['f7' => 'debug_step_into', 'ctrl+x' => 'tree_cut', 'ctrl+c' => 'tree_copy', 'ctrl+v' => 'tree_paste', 'delete' => 'tree_delete', 'shift+f6' => 'tree_rename'];
        $editor = \array_merge(...\array_values(EditorKeymap::defaults()));
        $hidden = [];
        foreach (ActionCatalog::ideDefaults() as $action => $keys) {
            foreach (\array_intersect($keys, $editor) as $key) {
                if (($contextual[$key] ?? null) === $action) {
                    continue;
                }
                $hidden[] = $key.' '.$action;
            }
        }

        $this->assertSame([], $hidden);
    }

    public function testTheMacResetsWithItsDeleteKeyEvenWithoutCmdChords(): void
    {
        // The Mac "delete" key sends Backspace; Delete is fn+delete. And
        // Ctrl+F1 is the system's there, so Error Description leads with Alt+Q.
        $this->assertSame(
            ['reset_binding' => ['backspace', 'delete'], 'show_error_description' => ['alt+q', 'ctrl+f1']],
            PlatformKeymap::withoutCmd(KeymapPlatform::MacOs),
        );
    }

    public function testEveryCmdChordIsAddedAndCtrlLeadsUntilOneArrives(): void
    {
        $overlay = PlatformKeymap::overrides(KeymapPlatform::MacOs);

        $this->assertSame(['ctrl+c', 'super+c'], $overlay['copy']);
        $this->assertSame(['ctrl+shift+z', 'super+shift+z'], $overlay['redo']);
        $this->assertSame(['ctrl+/', 'ctrl+-', 'super+/'], $overlay['toggle_comment'], 'every shipped key kept, in order');
        $this->assertArrayNotHasKey('paste', $overlay, 'Cmd+V is the terminal\'s paste');
        foreach ($overlay as $action => $keys) {
            if ('reset_binding' === $action) {
                continue;
            }
            $this->assertStringStartsNotWith('super+', $keys[0], $action.': a hint names a key that reaches the IDE');
        }
    }

    public function testAnArrivedCmdChordLeadsItsActionsOnly(): void
    {
        // As the parser names them: shift before super.
        $overlay = PlatformKeymap::overrides(KeymapPlatform::MacOs, arrived: ['shift+super+z', 'super+c']);

        $this->assertSame(['super+shift+z', 'ctrl+shift+z'], $overlay['redo']);
        $this->assertSame(['super+c', 'ctrl+c'], $overlay['copy']);
        $this->assertSame(['super+c', 'ctrl+c'], $overlay['tree_copy'], 'one chord, every action it is bound to');
        $this->assertSame(['ctrl+z', 'super+z'], $overlay['undo'], 'Cmd+Shift+Z arriving proves nothing about Cmd+Z');
    }

    public function testNoCmdChordIsOneTheSystemOrTheTerminalKeeps(): void
    {
        foreach (PlatformKeymap::overrides(KeymapPlatform::MacOs) as $action => $keys) {
            $this->assertSame([], \array_values(\array_intersect($keys, self::RESERVED)), $action);
        }
    }

    public function testEveryListKeepsExactlyTheShippedKeys(): void
    {
        // The lists are spelled out so Config does not reach into the widgets;
        // this is what keeps them from drifting from the defaults they extend.
        $shipped = ActionCatalog::ideDefaults() + EditorKeymap::defaults();
        foreach ([KeymapPlatform::Windows, KeymapPlatform::Linux, KeymapPlatform::MacOs] as $platform) {
            foreach (PlatformKeymap::overrides($platform) as $action => $keys) {
                if ('reset_binding' === $action) {
                    continue;
                }
                $this->assertArrayHasKey($action, $shipped, $action.' is a shipped action');
                $this->assertSame([], \array_values(\array_diff($shipped[$action], $keys)), $platform->value.' '.$action.' keeps every shipped key');
            }
        }
    }

    public function testEveryOverlayKeyIsOneTheParserReadsAndACmdChordIsNotTheBareKey(): void
    {
        // Keybindings throws on a key id it cannot read, and the overlays are
        // laid in unvetted — so each must parse. And a `super+c` read as a
        // bare `c` would fire on every c typed.
        $parser = new KeyParser();
        foreach (KeymapPlatform::cases() as $platform) {
            $this->assertInstanceOf(Keybindings::class, new Keybindings(PlatformKeymap::overrides($platform), $parser));
        }

        $this->assertFalse($parser->matches('c', 'super+c'));
        $this->assertTrue($parser->matches("\x1b[99;9u", 'super+c'));
    }
}
