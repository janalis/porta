<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Config\WorkspaceTrust;
use Porta\Lsp\ServerLocator;
use Porta\Plugin\Api\LegacyCoreSettings;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UntrustsProjects;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * What an untrusted project may not do — and, for each place that used to let
 * it, that a trusted one still does. The places are the joins: the config
 * loader, the language-server locator, the plugin loader, the adoption of the
 * pre-plugin keys.
 */
final class WorkspaceTrustTest extends TestCase
{
    use ClearsPortaLsp;
    use UntrustsProjects;

    /** The `.porta.json` a hostile clone would ship: something to run at every key that ever ran one. */
    private const array HOSTILE = [
        'lsp' => './evil-server',
        'enabledPlugins' => ['git', 'phpstan'],
        'disabledPlugins' => ['todo'],
        'plugins' => [
            'git' => ['path' => './x.sh', 'showBranch' => false],
            'graph-viewer' => ['command' => 'sh -c evil', 'dotPath' => './dot', 'plantuml' => './pu', 'renderMode' => 'text'],
            'review' => ['ghBinary' => './gh', 'hosts' => 'evil.tld=gitlab', 'provider' => 'gitlab'],
            'symfony-lsp' => ['php-command' => './php', 'runtime-indexing' => true],
            'php-cs-fixer' => ['args' => '--config=evil.php', 'binary' => './fixer'],
            'image-viewer' => ['svgConverter' => './convert'],
            'mcp' => ['allowRuns' => true, 'allowFileOps' => true, 'allowEdits' => true, 'allowSave' => true],
            'graph-viewer-out' => ['outputDir' => '/Users/me/.ssh'],
        ],
        'languages' => ['php' => ['runCommand' => './run', 'debug' => ['adapter' => './dap'], 'indentSize' => 2]],
        'settings' => ['gitPath' => './git', 'dotPath' => './dot', 'lineNumbers' => 'relative'],
    ];

    public function testRestrictedDropsEveryKeyThatNamesSomethingToRun(): void
    {
        $this->assertSame(
            [
                'disabledPlugins' => ['todo'],
                'plugins' => [
                    'git' => ['showBranch' => false],
                    'graph-viewer' => ['renderMode' => 'text'],
                    'review' => [],
                    'symfony-lsp' => [],
                    'php-cs-fixer' => [],
                    'image-viewer' => [],
                    'mcp' => [],
                    'graph-viewer-out' => [],
                ],
                'languages' => ['php' => ['indentSize' => 2]],
                'settings' => ['lineNumbers' => 'relative'],
            ],
            WorkspaceTrust::restricted(self::HOSTILE),
        );
        $this->assertSame(
            ['plugins' => ['x' => 'not a block'], 'languages' => ['php' => 'nor this']],
            WorkspaceTrust::restricted(['plugins' => ['x' => 'not a block'], 'languages' => ['php' => 'nor this']]),
            'what is not a block is left for the parser to warn about',
        );
    }

    public function testAFolderTrustsEveryProjectBeneathItAndNoSiblingThatSharesItsName(): void
    {
        $tree = new TempProject(['work/app/a.php' => '', 'work-old/app/a.php' => '']);
        $trust = new WorkspaceTrust(null, ['', $tree->path('work').'/']);

        $this->assertTrue($trust->isTrusted($tree->path('work')));
        $this->assertTrue($trust->isTrusted($tree->path('work/app')));
        $this->assertFalse($trust->isTrusted($tree->path('work-old/app')));
        $this->assertFalse($trust->isTrusted($tree->dir));
    }

    public function testTheEnvironmentNamesTheFoldersAndTheHome(): void
    {
        $this->untrustProjects();
        $tree = new TempProject(['a/x' => '', 'b/x' => '', 'c/x' => '']);
        \putenv('PORTA_TRUSTED_FOLDERS='.$tree->path('a').'::'.$tree->path('b'));

        $trust = WorkspaceTrust::fromEnvironment();

        $this->assertTrue($trust->isTrusted($tree->path('a')));
        $this->assertTrue($trust->isTrusted($tree->path('b')));
        $this->assertFalse($trust->isTrusted($tree->path('c')));
        $trust->trust($tree->path('c'));
        $this->assertFileExists($this->untrustingHome->path('.porta/trusted-projects.json'), 'answers live in PORTA_HOME');
    }

    public function testAnAnswerIsKeptEitherWayAndTheLatestWins(): void
    {
        $this->untrustProjects();
        $home = new TempProject();
        $project = new TempProject(['.porta.json' => '{}']);
        $trust = new WorkspaceTrust($home->dir);

        $this->assertTrue($trust->shouldAsk($project->dir));
        $this->assertTrue($trust->trust($project->dir));
        $this->assertTrue($trust->isTrusted($project->dir));
        $this->assertFalse($trust->shouldAsk($project->dir));
        $this->assertSame(0o600, \fileperms($home->path('.porta/trusted-projects.json')) & 0o777, 'the user\'s to grow, never another\'s to read');

        $this->assertTrue($trust->restrict($project->dir.'/'));
        $this->assertFalse($trust->isTrusted($project->dir), 'restricting takes the trust back');
        $this->assertFalse($trust->shouldAsk($project->dir), 'and is an answer too');
        $this->assertTrue($trust->trust($project->dir));
        $this->assertTrue($trust->isTrusted($project->dir), 'and back again');
        $this->assertSame(
            ['trusted' => [(string) \realpath($project->dir)], 'restricted' => []],
            \json_decode((string) \file_get_contents($home->path('.porta/trusted-projects.json')), associative: true),
            'one answer per project',
        );

        $this->assertTrue(new WorkspaceTrust($home->dir)->isTrusted($project->dir), 'read back by the next session');
    }

    public function testAnAnswerWithNowhereToGoIsNotKept(): void
    {
        $project = new TempProject(['.porta.json' => '{}']);

        $this->assertFalse(new WorkspaceTrust(null)->trust($project->dir), 'no home');
        $this->assertFalse(new WorkspaceTrust('/dev/null')->restrict($project->dir), 'an unwritable one');
        $this->assertTrue(new WorkspaceTrust('/dev/null')->shouldAsk($project->dir), 'so it is asked again');
    }

    public function testAHandEditedAnswerFileIsReadForWhatItCanSay(): void
    {
        $project = new TempProject(['.porta.json' => '{}']);
        $home = new TempProject(['.porta/trusted-projects.json' => \json_encode(['trusted' => [42, (string) \realpath($project->dir)], 'restricted' => 'garbage'], \JSON_THROW_ON_ERROR)]);

        $this->assertTrue(new WorkspaceTrust($home->dir)->isTrusted($project->dir));
    }

    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function whatIsWorthAsking(): iterable
    {
        yield 'a .porta.json' => [['.porta.json' => '{}'], true];
        yield 'plugins of its own' => [['.porta/plugins/x/plugin.php' => '<?php'], true];
        yield 'a vendor/bin' => [['vendor/bin/phpstan' => ''], true];
        yield 'nothing of it' => [['composer.json' => '{}', '.porta/error.log' => ''], false];
    }

    /** @param array<string, string> $files */
    #[DataProvider('whatIsWorthAsking')]
    public function testOnlyAProjectHoldingSomethingToRunIsWorthAsking(array $files, bool $ask): void
    {
        $home = new TempProject();
        $project = new TempProject($files);

        $this->assertSame($ask, new WorkspaceTrust($home->dir)->shouldAsk($project->dir));
    }

    public function testTheConfigOfAnUntrustedProjectIsReadRestricted(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['.porta.json' => \json_encode(self::HOSTILE, \JSON_THROW_ON_ERROR)]);

        $config = IdeConfig::load($project->dir);

        $this->assertFalse($config->trusted);
        $this->assertSame([], $config->lspServers);
        $this->assertSame(IdeConfig::DEFAULT_ENABLED_PLUGINS, $config->enabledPlugins, 'the project does not turn bundled plugins on');
        $this->assertSame(['todo'], $config->disabledPlugins, 'turning one off is still its to say');
        $this->assertSame(['showBranch' => false], $config->plugins['git']);
        $this->assertSame(['php' => ['indentSize' => 2]], $config->languages);
        $this->assertSame('relative', $config->settings['lineNumbers'], 'a setting that runs nothing is still its to say');

        new WorkspaceTrust($this->untrustingHome->dir)->trust($project->dir);
        $trusted = IdeConfig::load($project->dir);
        $this->assertTrue($trusted->trusted);
        $this->assertSame('./x.sh', $trusted->plugins['git']['path'], 'trusted, it says what it likes');
        $this->assertSame(['git', 'phpstan'], $trusted->enabledPlugins);
    }

    public function testAnUntrustedProjectDoesNotNameItsLanguageServer(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => ['./evil-server']], \JSON_THROW_ON_ERROR)]);

        $this->assertArrayNotHasKey('*', ServerLocator::locateAll($project->dir));

        new WorkspaceTrust($this->untrustingHome->dir)->trust($project->dir);
        $this->assertSame(['./evil-server'], ServerLocator::locateAll($project->dir)['*'][0]['command']);
    }

    public function testAnUntrustedProjectsPluginsAreNotLoaded(): void
    {
        $plugin = "<?php\nreturn new class('mine') extends Porta\\Plugin\\Api\\AbstractPlugin { public function activate(Porta\\Plugin\\Api\\PluginApi \$api): void {} };";
        $project = new TempProject(['.porta/plugins/mine/plugin.php' => $plugin]);
        $load = static function (bool $trusted) use ($project): PluginManager {
            $manager = new PluginManager(new EventDispatcher(), new PluginSettings($project->dir, []));
            new PluginLoader($project->dir, null, new PluginSettings($project->dir, []), trusted: $trusted)->loadInto($manager);

            return $manager;
        };

        $this->assertFalse($load(false)->hasPlugin('mine'));
        $this->assertTrue($load(true)->hasPlugin('mine'));
    }

    public function testAnUntrustedProjectsPrePluginKeysAreNotAdopted(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['.porta.json' => \json_encode(['settings' => ['gitPath' => './git', 'gitShowBranch' => false]], \JSON_THROW_ON_ERROR)]);
        $legacy = static fn (): LegacyCoreSettings => new LegacyCoreSettings($project->dir, 'git', ['path' => 'gitPath', 'showBranch' => 'gitShowBranch']);

        $this->assertSame(['showBranch' => false], $legacy()->adoptions());

        new WorkspaceTrust($this->untrustingHome->dir)->trust($project->dir);
        $this->assertSame(['path' => './git', 'showBranch' => false], $legacy()->adoptions());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function bundledTools(): iterable
    {
        // Their own config files execute the project's PHP, whichever binary reads them.
        yield 'phpstan' => ['phpstan', true];
        yield 'psalm' => ['psalm', true];
        yield 'php-cs-fixer' => ['php-cs-fixer', true];
        // These read the project as data.
        yield 'mago' => ['mago', false];
        yield 'phpactor' => ['phpactor', false];
    }

    #[DataProvider('bundledTools')]
    public function testTheBundledToolsThatRunProjectCodeSaySo(string $plugin, bool $runs): void
    {
        $tool = require \dirname(__DIR__, levels: 3).'/plugins/'.$plugin.'/plugin.php';
        \assert(\is_object($tool), description: 'a plugin.php returns its plugin');

        $this->assertSame($runs, Probe::invoke($tool, 'runsProjectCode'));
    }
}
