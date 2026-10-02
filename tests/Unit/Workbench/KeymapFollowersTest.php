<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Config\KeymapPlatform;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestLiveKeybindings;
use Porta\Tests\Support\TestPane;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Workbench\KeymapFollowers;

/**
 * The widgets that match keys against their own merged map are handed every
 * rebuilt one — otherwise they read the boot context's map for the session.
 */
final class KeymapFollowersTest extends TestCase
{
    public function testEveryRebuildReachesTheEditorThePanesAndTheKeymapList(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['keymap' => ['duplicate_line' => 'f12', 'goto_file' => 'f11']])]);
        $keys = TestLiveKeybindings::create($project->dir, KeymapPlatform::Linux);
        $editor = new CodeEditorWidget();
        $panes = new EditorPanes();
        $list = new KeybindingsListWidget(new Translator('en'));
        new KeymapFollowers($keys, $editor, $panes, $list)->wire();
        // Registered after the wiring, as a plugin's pane is: the next rebuild still reaches it.
        $panes->register($pane = new TestPane('demo'), static fn (): string => '');
        $this->assertSame(['ctrl+d'], $editor->getKeybindings()->getBindings('duplicate_line'), 'nothing handed over before a rebuild');

        $keys->usePlatform('macos');

        $this->assertSame(['f12'], $editor->getKeybindings()->getBindings('duplicate_line'), "the user's rebind, live in the editor");
        $this->assertSame(['f11'], $pane->getKeybindings()->getBindings('goto_file'), 'and in a pane, over its own default');
        $this->assertSame(['backspace', 'delete'], $list->getKeybindings()->getBindings('reset_binding'), "and the keymap list resets with the Mac's delete key");
    }
}
