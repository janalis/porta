<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\FileIndex;
use Porta\Search\TestNavigator;
use Porta\Tests\Support\TempProject;

final class TestNavigatorTest extends TestCase
{
    private function navigator(TempProject $project): TestNavigator
    {
        return new TestNavigator(new FileIndex($project->dir));
    }

    public function testIsTestMatchesTheSuffixButNotABareTestBasename(): void
    {
        $navigator = $this->navigator(new TempProject([]));

        $this->assertTrue($navigator->isTest('/p/tests/FooTest.php'));
        $this->assertFalse($navigator->isTest('/p/src/Foo.php'));
        $this->assertFalse($navigator->isTest('/p/src/Test.php'), 'a bare "Test" names no subject');
        $this->assertFalse($navigator->isTest('/p/src/Latest.php'), 'the suffix is case-sensitive on the T');
    }

    public function testLocatesTheTestFromAClassAndBack(): void
    {
        $project = new TempProject([
            'src/Foo.php' => "<?php\n",
            'tests/Unit/FooTest.php' => "<?php\n",
        ]);
        $navigator = $this->navigator($project);

        $this->assertSame(['tests/Unit/FooTest.php'], $navigator->locate($project->path('src/Foo.php')));
        $this->assertSame(['src/Foo.php'], $navigator->locate($project->path('tests/Unit/FooTest.php')));
    }

    public function testMultipleCandidatesComeBackSortedByPath(): void
    {
        $project = new TempProject([
            'src/Foo.php' => "<?php\n",
            'tests/Unit/FooTest.php' => "<?php\n",
            'tests/E2e/FooTest.php' => "<?php\n",
        ]);

        $this->assertSame(
            ['tests/E2e/FooTest.php', 'tests/Unit/FooTest.php'],
            $this->navigator($project)->locate($project->path('src/Foo.php')),
        );
    }

    public function testIgnoresVendoredFilesAndReportsNothingWhenNoneMatch(): void
    {
        $project = new TempProject([
            'src/Foo.php' => "<?php\n",
            'vendor/lib/tests/FooTest.php' => "<?php\n",
        ]);

        $this->assertSame([], $this->navigator($project)->locate($project->path('src/Foo.php')));
    }

    public function testIsExtensionAgnostic(): void
    {
        $project = new TempProject([
            'src/foo.ts' => '',
            'tests/fooTest.ts' => '',
            'src/foo.php' => "<?php\n",
        ]);

        $this->assertSame(['tests/fooTest.ts'], $this->navigator($project)->locate($project->path('src/foo.ts')), 'the extension restricts the match');
    }

    public function testSeesFilesCreatedAfterConstruction(): void
    {
        $project = new TempProject(['src/Foo.php' => "<?php\n"]);
        $navigator = $this->navigator($project);
        $this->assertSame([], $navigator->locate($project->path('src/Foo.php')));

        file_put_contents($project->path('FooTest.php'), data: "<?php\n");
        $this->assertSame(['FooTest.php'], $navigator->locate($project->path('src/Foo.php')), 'each lookup re-walks the index');
    }
}
