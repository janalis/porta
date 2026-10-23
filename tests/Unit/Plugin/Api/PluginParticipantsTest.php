<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Presence\Participant;
use Porta\Tests\Support\TestPluginRuntime;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** A plugin's people in core's roster: cleaned on the way in, kept for a while when lost, gone with the plugin. */
final class PluginParticipantsTest extends TestCase
{
    private PluginManager $manager;

    private PluginRuntime $runtime;

    private function api(string $name = 'assist'): PluginApi
    {
        $this->manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $this->runtime = TestPluginRuntime::create(new CodeEditorWidget(), new StatusBarWidget());
        $this->manager->wireRuntime($this->runtime);
        $api = $this->manager->api($name, '/p', new PluginSettings('/p', []));
        $this->manager->commit($api);

        return $api;
    }

    public function testTheirNamesAreCleanedAndTheirWayInIsThePluginsName(): void
    {
        $participants = $this->api()->participants();

        $participants->set(['g1' => " Ana\e ", 7 => 'Bo', 'g3' => "\x07"]);

        $this->assertEquals(
            [new Participant('g1', 'Ana', 'assist'), new Participant('7', 'Bo', 'assist')],
            $this->runtime->participants->all(),
            'a name of nothing is no one; a numeric id is still a string',
        );
        $participants->outOfTouch();
        $this->assertTrue($this->runtime->participants->isOutOfTouch('plugin:assist'));
        $participants->set([]);
        $this->assertSame([], $this->runtime->participants->all());
    }

    public function testRemovingThePluginTakesItsPeopleOut(): void
    {
        $this->api()->participants()->set(['g1' => 'Ana']);

        $this->manager->deactivate('assist', new CommandRegistry());

        $this->assertSame([], $this->runtime->participants->all());
    }

    public function testYourNameIsTheOneTheOthersSee(): void
    {
        $api = $this->api();

        $this->assertSame($this->runtime->participants->yourName(), $api->participants()->yourName());
        $this->assertNotSame('', $api->participants()->yourName());
    }
}
