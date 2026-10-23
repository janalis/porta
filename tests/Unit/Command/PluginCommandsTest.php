<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Porta\Command\PluginCommands;
use Porta\Config\CommandRegistry;
use Porta\Editor\ModalHost;
use Porta\Editor\PromptTone;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTools;
use Porta\Tests\Support\TestTui;
use Porta\Ui\TextEcho;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The palette's "Plugins: …" verbs: each is a route to what the Settings page
 * already does, so what is tested here is that it picks the right plugins,
 * and says so when there are none to pick.
 */
final class PluginCommandsTest extends TestCase
{
    use StatusReads;

    private TempProject $project;

    private StatusBarWidget $status;

    private FakePluginLifecycle $lifecycle;

    private CommandRegistry $commands;

    /** @var list<array{label: string, hint: string, value: string}>|null the list the last picker showed */
    private ?array $items = null;

    private ?string $title = null;

    private ?\Closure $onPick = null;

    /** @var list<string> every argv a manager was handed, joined */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->status = new StatusBarWidget();
        $this->lifecycle = new FakePluginLifecycle();
        $this->commands = new CommandRegistry();
        $this->plugin('viewer', 'Shows pictures', [['name' => 'dot', 'sources' => [['brew', 'graphviz']]]]);
        $this->plugin('linter', 'Checks code', [['name' => 'lint', 'sources' => [['brew', 'lint']]]]);
        $this->plugin('quiet', null, []);

        $notifier = new Notifier($this->status, TestTui::create(160, 24));
        $tools = new TestTools(
            $this->project->dir,
            new PluginInstaller($this->project->dir, null, new Translator('en'), null),
            new PluginManager(new EventDispatcher(), new PluginSettings($this->project->dir, [])),
            $notifier,
            $this->lifecycle,
            ['brew' => '/bin/brew'],
            path: ['lint' => '/opt/homebrew/Cellar/lint/1.0/bin/lint'],
            run: function (array $argv, string $cwd, \Closure $onExit): void {
                $this->ran[] = \implode(' ', $argv);
            },
        );
        $modals = new class($this->title, $this->items, $this->onPick) implements ModalHost {
            /** @param list<array{label: string, hint: string, value: string}>|null $items */
            public function __construct(
                private ?string &$title,
                private ?array &$items,
                private ?\Closure &$onPick,
            ) {}

            public function openListOverlay(string $title, array $items, callable $onPick): void
            {
                $this->title = $title;
                $this->items = $items;
                $this->onPick = $onPick(...);
            }

            public function withdrawListOverlay(callable $onPick): void {}

            public function openPrompt(string $label, string $initial, callable $onSubmit, PromptTone $tone = PromptTone::Plain, TextEcho $echo = TextEcho::Visible): void {}
        };
        new PluginCommands($this->lifecycle, $tools->catalog, $tools->jobs, $modals, $notifier, new Translator('en'))->register($this->commands);
    }

    /**
     * A plugin installed in the project, with $description and $tools in its manifest.
     *
     * @param list<array<string, mixed>> $tools
     */
    private function plugin(string $name, ?string $description, array $tools): void
    {
        $this->project->write('.porta/plugins/'.$name.'/plugin.php', "<?php\n");
        $manifest = ['extra' => ['porta' => ['tools' => $tools]]];
        if (null !== $description) {
            $manifest['description'] = $description;
        }
        $this->project->write('.porta/plugins/'.$name.'/composer.json', (string) \json_encode($manifest, \JSON_THROW_ON_ERROR));
        $this->lifecycle->names[] = $name;
    }

    private function toast(): string
    {
        return $this->statusRow($this->status, 160);
    }

    public function testEnableOffersTheDisabledPluginsWithWhatTheyDo(): void
    {
        $this->lifecycle->disabledNames = ['viewer', 'quiet'];

        $this->commands->run('plugins_enable');

        $this->assertSame('Enable plugin', $this->title);
        $this->assertSame(
            [['label' => 'viewer', 'hint' => 'Shows pictures', 'value' => 'viewer'], ['label' => 'quiet', 'hint' => '', 'value' => 'quiet']],
            $this->items,
            'only the disabled ones, each with its description, or none when it has none',
        );
        $this->assertInstanceOf(\Closure::class, $this->onPick);
        ($this->onPick)('viewer');
        $this->assertSame([['viewer', true]], $this->lifecycle->toggled, 'the pick goes through the lifecycle, as the Settings toggle does');
    }

    public function testDisableOffersTheEnabledPlugins(): void
    {
        $this->lifecycle->disabledNames = ['viewer'];

        $this->commands->run('plugins_disable');

        $this->assertSame('Disable plugin', $this->title);
        $this->assertSame(['linter', 'quiet'], \array_column($this->items ?? [], 'value'));
        $this->assertInstanceOf(\Closure::class, $this->onPick);
        ($this->onPick)('linter');
        $this->assertSame([['linter', false]], $this->lifecycle->toggled);
    }

    public function testWithNothingToPickItSaysSoInsteadOfAnEmptyList(): void
    {
        $this->commands->run('plugins_enable');
        $this->assertNull($this->items);
        $this->assertStringContainsString('Every plugin is enabled', $this->toast());

        $this->lifecycle->disabledNames = ['viewer', 'linter', 'quiet'];
        $this->commands->run('plugins_disable');
        $this->assertNull($this->items);
        $this->assertStringContainsString('No plugin is enabled', $this->toast());
    }

    public function testInstallMissingQueuesTheEnabledPluginsMissingTools(): void
    {
        $this->commands->run('plugins_install_tools');

        $this->assertSame(['/bin/brew install graphviz'], $this->ran, 'dot is missing; lint is there');
    }

    public function testInstallMissingWithNothingMissingSaysSo(): void
    {
        $this->lifecycle->disabledNames = ['viewer'];

        $this->commands->run('plugins_install_tools');

        $this->assertSame([], $this->ran);
        $this->assertStringContainsString('Nothing to install: every enabled plugin has the tools it requires', $this->toast());
    }

    public function testUpdateAllUpdatesWhatPortaManages(): void
    {
        $this->commands->run('plugins_update_tools');

        $this->assertSame(['/bin/brew upgrade lint'], $this->ran, 'lint is brew\'s; dot is not there to update');
    }

    public function testUpdateAllWithNothingManagedSaysSo(): void
    {
        $this->lifecycle->disabledNames = ['linter'];

        $this->commands->run('plugins_update_tools');

        $this->assertSame([], $this->ran);
        $this->assertStringContainsString('Nothing to update: Porta manages none of the installed tools', $this->toast());
    }
}
