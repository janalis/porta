<?php

declare(strict_types=1);

use Janalis\Tuink\Launch;
use Porta\Config\ConfigScope;
use Porta\Console\WorkspaceResolver;
use Porta\Container\ToolLaunch;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Settings\SettingsScope;
use Porta\Tests\Behat\PortaApplication;
use Porta\Tests\Support\Services;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/*
 * Builds the Ide the way `porta [path|file:line] [--no-lsp] [--plugin=<name>]…`
 * does, and `--diff LOCAL REMOTE` / `--merge LOCAL REMOTE BASE MERGED` as git
 * runs it, on tuink's terminal. The Welcome is marked seen unless
 * PORTA_BEHAT_FIRST_RUN is "1", and the settings scope is pinned to the project, as every E2e test does: the
 * fixture home's global config is shared by the whole run.
 */
return static function (VirtualTerminal $terminal, Launch $launch): PortaApplication {
    $cwd = $launch->workspace ?? (string) getcwd();
    $options = array_values(array_filter($launch->arguments, static fn (string $a): bool => str_starts_with($a, '-')));
    $targets = array_values(array_filter($launch->arguments, static fn (string $a): bool => !str_starts_with($a, '-')));
    $absolute = array_map(static fn (string $path): string => str_starts_with($path, '/') ? $path : $cwd.'/'.$path, $targets);
    // As git's difftool and mergetool run it: the paths are the files to compare, the project is where it runs
    $tool = match (true) {
        in_array('--diff', $options, true) => ToolLaunch::diff($absolute[0], $absolute[1]),
        in_array('--merge', $options, true) => ToolLaunch::merge($absolute[0], $absolute[1], $absolute[2], $absolute[3]),
        default => null,
    };
    $workspace = new WorkspaceResolver()->resolve(null !== $tool ? $cwd : $targets[0] ?? $cwd, $cwd);
    if ('1' !== getenv('PORTA_BEHAT_FIRST_RUN')) {
        new OnboardingState($workspace->rootDir)->markWelcomeSeen();
    }

    $plugins = array_values(array_map(
        static fn (string $flag): string => substr($flag, strlen('--plugin=')),
        array_filter($options, static fn (string $flag): bool => str_starts_with($flag, '--plugin=')),
    ));

    $ide = new Ide(
        rootDir: $workspace->rootDir,
        initialFile: null !== $tool ? null : $workspace->initialFile,
        terminal: $terminal,
        initialLine: $workspace->line,
        lspDisabled: in_array('--no-lsp', $options, true),
        tool: $tool,
        plugins: $plugins,
    );
    Services::resolve($ide, SettingsScope::class)->set(ConfigScope::Project);

    return new PortaApplication($ide);
};
