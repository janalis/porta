<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\LaunchArguments;

final class LaunchArgumentsTest extends TestCase
{
    public function testDefaultTemplateRunsTheCurrentFileInTheProjectRoot(): void
    {
        $this->assertSame(
            ['program' => '/p/src/A.php', 'cwd' => '/p', 'stopOnEntry' => false],
            LaunchArguments::build('/p/src/A.php', '/p', []),
        );
    }

    public function testOverridesMergeOverTheTemplateAndSubstituteRecursively(): void
    {
        $arguments = LaunchArguments::build('/p/a.php', '/p', [
            'runtimeExecutable' => 'php',
            'stopOnEntry' => true,
            'env' => ['SCRIPT' => '${file}', 'ROOT' => '${workspaceRoot}/vendor', 'PORT' => 9003],
            'program' => '${workspaceRoot}/run.php',
        ]);

        $this->assertSame(
            [
                'program' => '/p/run.php',
                'cwd' => '/p',
                'stopOnEntry' => true,
                'runtimeExecutable' => 'php',
                'env' => ['SCRIPT' => '/p/a.php', 'ROOT' => '/p/vendor', 'PORT' => 9003],
            ],
            $arguments,
            'strings substituted at every depth; non-strings untouched',
        );
    }

    public function testRunCommandSeedsRuntimeExecutableAndArgs(): void
    {
        $this->assertSame(
            ['program' => '/p/a.php', 'cwd' => '/p', 'stopOnEntry' => false, 'runtimeExecutable' => 'frankenphp', 'runtimeArgs' => ['php-cli']],
            LaunchArguments::build('/p/a.php', '/p', [], ['frankenphp', 'php-cli']),
            'a multi-token run command splits into runtimeExecutable + runtimeArgs',
        );

        $this->assertSame(
            ['program' => '/p/a.php', 'cwd' => '/p', 'stopOnEntry' => false, 'runtimeExecutable' => '/usr/bin/php'],
            LaunchArguments::build('/p/a.php', '/p', [], ['/usr/bin/php']),
            'a single-token run command sets only runtimeExecutable',
        );
    }

    public function testUserLaunchOverridesWinOverTheRunCommand(): void
    {
        $arguments = LaunchArguments::build('/p/a.php', '/p', ['runtimeExecutable' => 'php8.4'], ['frankenphp', 'php-cli']);
        $this->assertSame('php8.4', $arguments['runtimeExecutable'], 'an explicit launch override beats the run command');
        $this->assertSame(['php-cli'], $arguments['runtimeArgs'], 'but the run command still contributes runtimeArgs');
    }
}
