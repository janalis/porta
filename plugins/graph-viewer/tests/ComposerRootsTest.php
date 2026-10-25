<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\ComposerRoots;
use Porta\Tests\Support\TempProject;

final class ComposerRootsTest extends TestCase
{
    public function testReadsPsr4RootsFromBothAutoloadSections(): void
    {
        $project = new TempProject([
            'composer.json' => (string) \json_encode(
                [
                    'autoload' => ['psr-4' => ['App\\' => 'src/']],
                    'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/']],
                ],
                \JSON_THROW_ON_ERROR,
            ),
            'src/.keep' => '',
            'tests/.keep' => '',
        ]);

        $this->assertSame(['App' => $project->dir.'/src', 'App\Tests' => $project->dir.'/tests'], ComposerRoots::roots($project->dir));
    }

    public function testMissingDirsAndOddShapesAreSkipped(): void
    {
        $project = new TempProject([
            'composer.json' => (string) \json_encode(
                [
                    'autoload' => ['psr-4' => ['App\\' => 'nonexistent/', 'Lib\\' => ['also-missing/', 'lib/', 'lib2/'], 'Bad\\' => 42]],
                ],
                \JSON_THROW_ON_ERROR,
            ),
            'lib/.keep' => '',
            'lib2/.keep' => '',
        ]);

        $this->assertSame(['Lib' => $project->dir.'/lib'], ComposerRoots::roots($project->dir), 'a multi-dir prefix takes its first existing dir; non-string entries drop');
    }

    public function testNonArrayPsr4AndNumericPrefixesAreSkipped(): void
    {
        $project = new TempProject([
            'composer.json' => (string) \json_encode(
                [
                    'autoload' => ['psr-4' => 'not-an-array'],
                    'autoload-dev' => ['psr-4' => ['0' => 'src/', 'App\\' => 'src/']],
                ],
                \JSON_THROW_ON_ERROR,
            ),
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
