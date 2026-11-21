<?php

declare(strict_types=1);

use Janalis\Tuink\Launch;
use Porta\Config\ConfigScope;
use Porta\Console\WorkspaceResolver;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Settings\SettingsScope;
use Porta\Tests\Behat\PortaApplication;
use Porta\Tests\Support\Services;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/*
 * Builds the Ide the way `porta [path|file:line] [--no-lsp] [--plugin=<name>]…` does, on tuink's
 * terminal. The Welcome is marked seen unless PORTA_BEHAT_FIRST_RUN is "1",
 * and the settings scope is pinned to the project, as every E2e test does: the
 * fixture home's global config is shared by the whole run.
 */
return static function (VirtualTerminal $terminal, Launch $launch): PortaApplication {
    $cwd = $launch->workspace ?? (string) getcwd();
    $options = array_values(array_filter($launch->arguments, static fn (string $a): bool => str_starts_with($a, '-')));
    $targets = array_values(array_filter($launch->arguments, static fn (string $a): bool => !str_starts_with($a, '-')));
    $workspace = new WorkspaceResolver()->resolve($targets[0] ?? $cwd, $cwd);
    if ('1' !== getenv('PORTA_BEHAT_FIRST_RUN')) {
        new OnboardingState($workspace->rootDir)->markWelcomeSeen();
    }

    $plugins = array_values(array_map(
        static fn (string $flag): string => substr($flag, strlen('--plugin=')),
        array_filter($options, static fn (string $flag): bool => str_starts_with($flag, '--plugin=')),
    ));

    $ide = new Ide(
        rootDir: $workspace->rootDir,
        initialFile: $workspace->initialFile,
        terminal: $terminal,
        initialLine: $workspace->line,
        lspDisabled: in_array('--no-lsp', $options, true),
        plugins: $plugins,
    );
    Services::resolve($ide, SettingsScope::class)->set(ConfigScope::Project);

    return new PortaApplication($ide);
};
