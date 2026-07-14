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
}
