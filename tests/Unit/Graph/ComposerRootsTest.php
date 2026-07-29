<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Graph;

use PHPUnit\Framework\TestCase;
use Porta\Graph\ComposerRoots;
use Porta\Tests\Support\TempProject;

final class ComposerRootsTest extends TestCase
{
    public function testReadsPsr4RootsFromBothAutoloadSections(): void
    {
        $project = new TempProject([
            'composer.json' => (string) json_encode([
                'autoload' => ['psr-4' => ['App\\' => 'src/']],
                'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/']],
            ]),
            'src/.keep' => '',
            'tests/.keep' => '',
        ]);

        $this->assertSame(['App' => $project->dir.'/src', 'App\Tests' => $project->dir.'/tests'], ComposerRoots::roots($project->dir));
    }

    public function testMissingDirsAndOddShapesAreSkipped(): void
    {
        $project = new TempProject([
            'composer.json' => (string) json_encode([
                'autoload' => ['psr-4' => ['App\\' => 'nonexistent/', 'Lib\\' => ['also-missing/', 'lib/'], 'Bad\\' => 42]],
            ]),
            'lib/.keep' => '',
        ]);

        $this->assertSame(['Lib' => $project->dir.'/lib'], ComposerRoots::roots($project->dir), 'a multi-dir prefix takes its first existing dir; non-string entries drop');
    }

    public function testNonArrayPsr4AndNumericPrefixesAreSkipped(): void
    {
        $project = new TempProject([
            'composer.json' => (string) json_encode([
                'autoload' => ['psr-4' => 'not-an-array'],
                'autoload-dev' => ['psr-4' => ['0' => 'src/', 'App\\' => 'src/']],
            ]),
            'src/.keep' => '',
        ]);

        $this->assertSame(['App' => $project->dir.'/src'], ComposerRoots::roots($project->dir), 'a numeric JSON key decodes as int and drops');
    }

    public function testNoComposerJsonMeansNoRoots(): void
    {
        $project = new TempProject(['README.md' => 'no composer here']);

        $this->assertSame([], ComposerRoots::roots($project->dir));
    }
}
