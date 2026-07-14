<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Editor\Document;
use Porta\I18n\Translator;
use Porta\Plugin\PluginApi;
use Porta\Plugin\PluginEvents;
use Porta\Plugin\PluginException;
use Porta\Plugin\PluginManager;
use Porta\Plugin\PluginRuntime;
use Porta\Plugin\PluginSettings;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;

final class PluginApiTest extends TestCase
{
    private CodeEditorWidget $editor;
    /** @var list<string> */
    private array $calls = [];

    private function api(bool $wired = true): PluginApi
    {
        $manager = new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
        $this->editor = new CodeEditorWidget();
        $this->calls = [];
        if ($wired) {
            $manager->wireRuntime(new PluginRuntime(
                flash: function (string $message, float $seconds): void {
                    $this->calls[] = "flash:{$message}:{$seconds}";
                },
                translator: new Translator('en'),
                editor: $this->editor,
                openFile: function (string $path): void {
                    $this->calls[] = "open:{$path}";
                },
                requestRender: function (): void {
                    $this->calls[] = 'render';
                },
                setDiagnostics: function (string $path, array $byLine): void {
                    $this->calls[] = 'diag:'.$path.':'.json_encode($byLine);
                },
            ));
        }

        return new PluginApi('demo', $manager, '/p', new PluginSettings('/p', []));
    }

    public function testRegistrationBuffersNamespacedCommandsAndValidatesIds(): void
    {
        $api = $this->api(wired: false);
        $this->assertSame('/p', $api->projectRoot(), 'static config, available during activate()');
        $api->registerCommand('hello', 'Say Hello', static function (): void {}, ['f8']);
        $api->onFileSaved(static function (): void {});

        $this->assertSame('demo:hello', $api->bufferedCommands()[0]['id']);
        $this->assertSame(['f8'], $api->bufferedCommands()[0]['keys']);
        $this->assertSame('fileSaved', $api->bufferedHooks()[0][0]);
        $this->assertSame('demo', $api->pluginName());

        $api->addMenuItem('hello');
        $this->assertSame([['id' => 'demo:hello', 'when' => null]], $api->bufferedMenuItems());

        try {
            $api->registerCommand('hello', 'Again', static function (): void {});
            $this->fail('duplicate id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Duplicate command id', $e->getMessage());
        }
        try {
            $api->registerCommand('bad:name', 'Bad', static function (): void {});
            $this->fail('invalid id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Invalid command id', $e->getMessage());
        }
        try {
            $api->addMenuItem('nope');
            $this->fail('menu item for an unregistered command must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('register it first', $e->getMessage());
        }
    }

    public function testRegisterSettingValidatesAndSettingReadsSeededConfig(): void
    {
        $manager = new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
        $seeded = new PluginSettings('/p', ['demo' => ['binary' => '/opt/x', 'strict' => 'yes', 'level' => 'nope', 'jobs' => '7']]);
        $api = new PluginApi('demo', $manager, '/p', $seeded);

        // Reads work immediately after registration — i.e. during activate().
        $api->registerSetting('binary', 'Binary', 'text', '');
        $api->registerSetting('strict', 'Strict', 'bool', false);
        $api->registerSetting('level', 'Level', 'choice', 'low', ['low', 'high']);
        $api->registerSetting('jobs', 'Jobs', 'int', 4);
        $this->assertSame('/opt/x', $api->setting('binary'));
        $this->assertTrue($api->setting('strict'), 'coerced to the declared bool kind');
        $this->assertSame('low', $api->setting('level'), 'out-of-options choice falls back to the default');
        $this->assertSame(7, $api->setting('jobs'), 'the raw string coerced to the declared int kind');

        $this->assertCount(4, $api->bufferedSettings());

        foreach ([
            ['bad key', fn () => $api->registerSetting('bad:key', 'X')],
            ['duplicate', fn () => $api->registerSetting('binary', 'Again')],
            ['bad kind', fn () => $api->registerSetting('x', 'X', 'float')],
            ['choice needs options', fn () => $api->registerSetting('y', 'Y', 'choice', 'a')],
            ['default not in options', fn () => $api->registerSetting('z', 'Z', 'choice', 'a', ['b'])],
            ['unknown read', fn () => $api->setting('ghost')],
        ] as [$label, $action]) {
            try {
                $action();
                $this->fail("{$label} must throw");
            } catch (PluginException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEveryActionMethodThrowsBeforeTheRuntimeIsWired(): void
    {
        $api = $this->api(wired: false);
        $api->registerSetting('flag', 'Flag', 'bool', false); // a registered key, so changeSetting reaches the runtime gate
        $actions = [
            fn () => $api->flash('x'),
            fn () => $api->activeFile(),
            fn () => $api->documentText(),
            fn () => $api->caretPosition(),
            fn () => $api->insertAtCaret('x'),
            fn () => $api->openFile('/x'),
            fn () => $api->jumpTo(0),
            fn () => $api->setDiagnostics('/x', []),
            fn () => $api->changeSetting('flag', true),
        ];
        foreach ($actions as $i => $action) {
            try {
                $action();
                $this->fail("action #{$i} must throw during activate()");
            } catch (PluginException $e) {
                $this->assertStringContainsString('unavailable during activate()', $e->getMessage());
            }
        }
    }

    public function testChangeSettingValidatesPersistsAndCoerces(): void
    {
        $project = new TempProject();
        $manager = new PluginManager(new PluginEvents(), $settings = new PluginSettings($project->dir, []));
        $calls = [];
        $manager->wireRuntime(new PluginRuntime(
            flash: static function (string $message, float $seconds): void {},
            translator: new Translator('en'),
            editor: new CodeEditorWidget(),
            openFile: static function (string $path): void {},
            requestRender: function () use (&$calls): void {
                $calls[] = 'render';
            },
            setDiagnostics: static function (string $path, array $byLine): void {},
        ));
        $api = new PluginApi('demo', $manager, $project->dir, $settings);

        $api->registerSetting('jobs', 'Jobs', 'int', 4);
        $settings->registerFor('demo', $api->bufferedSettings()); // what PluginManager::commit does

        $api->changeSetting('jobs', '7');

        $this->assertSame(7, $api->setting('jobs'), 'coerced to the declared int kind, live');
        $this->assertSame(7, IdeConfig::load($project->dir)->plugins['demo']['jobs'], 'persisted to .porta.json');
        $this->assertSame(['render'], $calls, 'an open settings page repaints');

        try {
            $api->changeSetting('ghost', true);
            $this->fail('an unregistered key must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Unknown setting "ghost"', $e->getMessage());
        }
    }

    public function testActionsAgainstALiveEditor(): void
    {
        $api = $this->api();

        $api->flash('hi', 1.5);
        $this->assertSame(['flash:hi:1.5', 'render'], $this->calls);

        // No file open: reads are null, insert is a no-op.
        $this->assertNull($api->activeFile());
        $this->assertNull($api->documentText());
        $this->assertNull($api->caretPosition());
        $api->insertAtCaret('nope');

        $this->editor->showDocument('/p/a.php', new Document("hello\nworld\n"));
        $this->editor->getDocument()->setCursor(1, 2);
        $this->assertSame('/p/a.php', $api->activeFile());
        $this->assertSame("hello\nworld\n", $api->documentText());
        $this->assertSame(['line' => 1, 'column' => 2], $api->caretPosition());

        $api->insertAtCaret('XY');
        $this->assertSame("hello\nwoXYrld\n", $this->editor->getDocument()->getText());

        $api->jumpTo(0, 1);
        $this->assertSame(['line' => 0, 'column' => 1], $api->caretPosition());

        $api->openFile('/p/b.php');
        $this->assertContains('open:/p/b.php', $this->calls);
    }

    public function testSetDiagnosticsNormalizesIntoTheByLineShape(): void
    {
        $api = $this->api();

        $api->setDiagnostics('/p/a.php', [
            ['line' => 2, 'message' => 'boom'],
            ['line' => 2, 'message' => 'warn', 'severity' => 2, 'column' => 4],
            ['line' => -1, 'message' => 'clamped'],
        ]);

        $expected = json_encode([
            2 => [
                ['severity' => 1, 'message' => 'boom', 'col' => 0, 'endCol' => 0],
                ['severity' => 2, 'message' => 'warn', 'col' => 4, 'endCol' => 4],
            ],
            0 => [['severity' => 1, 'message' => 'clamped', 'col' => 0, 'endCol' => 0]],
        ]);
        $this->assertContains('diag:/p/a.php:'.$expected, $this->calls);

        $api->setDiagnostics('/p/a.php', []); // empty list clears
        $this->assertContains('diag:/p/a.php:[]', $this->calls);
    }
}
