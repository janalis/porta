<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\DebugAdapterProvider;
use Porta\Debug\DebugAdapters;
use Porta\Debug\DebugConfig;

final class DebugAdaptersTest extends TestCase
{
    public function testSeedsFromTheIdeConfigLanguagesShape(): void
    {
        $adapters = new DebugAdapters([
            'php' => ['indentStyle' => 'spaces', 'debug' => ['adapter' => 'node  /x/phpDebug.js', 'launch' => ['runtimeExecutable' => 'php']]],
            'python' => ['debug' => ['adapter' => 'python -m debugpy.adapter']],
            'javascript' => ['indentSize' => 2], // no debug block
        ]);

        $php = $adapters->adapterFor('php');
        $this->assertInstanceOf(DebugConfig::class, $php);
        $this->assertSame(['node', '/x/phpDebug.js'], $php->adapterArgv, 'command split on whitespace runs');
        $this->assertSame(['runtimeExecutable' => 'php'], $php->launch);

        $this->assertSame(['python', '-m', 'debugpy.adapter'], $adapters->adapterFor('python')?->adapterArgv);
        $this->assertNull($adapters->adapterFor('javascript'));
        $this->assertNull($adapters->adapterFor('rust'), 'unknown language');
    }

    public function testSettingsRowsReadTheConfiguredValues(): void
    {
        $adapters = new DebugAdapters(['php' => ['debug' => ['adapter' => 'node a.js', 'launch' => ['env' => ['X' => '1']]]]]);

        $this->assertSame('node a.js', $adapters->adapterCommand('php'));
        $this->assertSame(['env' => ['X' => '1']], $adapters->launchOverrides('php'));
        $this->assertSame('', $adapters->adapterCommand('python'), 'unset language reads blank');
        $this->assertSame([], $adapters->launchOverrides('python'));
    }

    public function testSetUpdatesLiveAndBlankClears(): void
    {
        $adapters = new DebugAdapters([]);
        $adapters->set('php', ' node b.js ', ['stopOnEntry' => true]);
        $this->assertSame(['node', 'b.js'], $adapters->adapterFor('php')?->adapterArgv);
        $this->assertSame(['stopOnEntry' => true], $adapters->adapterFor('php')?->launch);

        $adapters->set('php', '   ', []);
        $this->assertNull($adapters->adapterFor('php'), 'blank command clears the entry');
    }

    public function testRunCommandBecomesTheDebugConfigRunCommandArgv(): void
    {
        $adapters = new DebugAdapters([
            'php' => ['debug' => ['adapter' => 'node dbg.js'], 'runCommand' => 'frankenphp php-cli'],
            'python' => ['debug' => ['adapter' => 'debugpy']], // no runCommand
        ]);

        $this->assertSame(['frankenphp', 'php-cli'], $adapters->adapterFor('php')?->runCommandArgv);
        $this->assertSame([], $adapters->adapterFor('python')?->runCommandArgv, 'no runCommand → empty');
    }

    public function testSetPreservesTheRunCommandFromBoot(): void
    {
        $adapters = new DebugAdapters(['php' => ['debug' => ['adapter' => 'node a.js'], 'runCommand' => 'frankenphp php-cli']]);
        $adapters->set('php', 'node b.js', ['stopOnEntry' => true]);
        $this->assertSame(['frankenphp', 'php-cli'], $adapters->adapterFor('php')?->runCommandArgv, 'a live adapter edit keeps the run command');
    }

    public function testAPluginsAdapterFillsALanguageTheConfigDoesNotName(): void
    {
        // Precedence is the user first, always: a hand-configured adapter is
        // never shadowed, and clearing it hands the language to the plugin.
        $plugins = new class implements DebugAdapterProvider {
            public function debugAdapters(): array
            {
                return ['php' => ['php', 'adapter'], 'rust' => ['rust-dap']];
            }
        };
        $adapters = new DebugAdapters(['php' => ['debug' => ['adapter' => 'my-own-adapter --flag']]], $plugins);

        $this->assertSame(['my-own-adapter', '--flag'], $adapters->adapterFor('php')?->adapterArgv, "the user's configuration wins");
        $this->assertSame(['rust-dap'], $adapters->adapterFor('rust')?->adapterArgv, 'a plugin fills a language the config does not name');
        $this->assertNull($adapters->adapterFor('go'), 'and neither claims go');

        $adapters->set('php', '', []);
        $this->assertSame(['php', 'adapter'], $adapters->adapterFor('php')?->adapterArgv, 'clearing the setting hands the language back');
    }

    public function testWithNoPluginProviderNothingChanges(): void
    {
        // The nullable dependency is the boot-order escape hatch, not a mode:
        // with no provider the registry is exactly the config.
        $adapters = new DebugAdapters(['php' => ['debug' => ['adapter' => 'x']]]);

        $this->assertSame(['x'], $adapters->adapterFor('php')?->adapterArgv);
        $this->assertNull($adapters->adapterFor('rust'));
    }

    public function testAQuotedAdapterPathWithSpacesSurvivesTokenization(): void
    {
        // The shared Argv tokenizer: the same space-in-path bug fixed for the
        // LSP seam must not live on in the adapter seam.
        $adapters = new DebugAdapters(['php' => ['debug' => ['adapter' => 'node "/Users/x/Application Support/phpDebug.js"']]]);

        $this->assertSame(['node', '/Users/x/Application Support/phpDebug.js'], $adapters->adapterFor('php')?->adapterArgv);
    }

    /**
     * Xdebug's README documents `languages.php.debug.launch.pathMappings` with
     * no adapter of the user's: the launch block, the run command and a
     * Settings edit of the launch config all ride on the plugin's adapter.
     */
    public function testAPluginsAdapterKeepsTheUsersLaunchAndRunCommand(): void
    {
        $plugins = new class implements DebugAdapterProvider {
            public function debugAdapters(): array
            {
                return ['php' => ['xdebug-dap']];
            }
        };
        $adapters = new DebugAdapters(['php' => ['debug' => ['launch' => ['port' => 9010]], 'runCommand' => 'frankenphp php-cli']], $plugins);

        $php = $adapters->adapterFor('php');
        $this->assertSame(['xdebug-dap'], $php?->adapterArgv);
        $this->assertSame(['port' => 9010], $php?->launch, 'the launch block without an adapter is kept');
        $this->assertSame(['frankenphp', 'php-cli'], $php?->runCommandArgv);
        $this->assertSame(['port' => 9010], $adapters->launchOverrides('php'), 'and the Settings row reads it');

        // The Settings launch row saves with the (blank) adapter command it reads.
        $adapters->set('php', $adapters->adapterCommand('php'), ['pathMappings' => ['/var/www' => '${workspaceRoot}']]);
        $this->assertSame(['pathMappings' => ['/var/www' => '${workspaceRoot}']], $adapters->adapterFor('php')?->launch, 'a live launch edit reaches the next run');
        $this->assertSame(['frankenphp', 'php-cli'], $adapters->adapterFor('php')?->runCommandArgv);
    }
}
