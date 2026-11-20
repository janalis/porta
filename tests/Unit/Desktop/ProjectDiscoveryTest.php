<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\TestCase;
use Porta\Desktop\ProjectDiscovery;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\TempProject;

/** What a first launch offers: the PHP projects in the folders people keep code in. */
final class ProjectDiscoveryTest extends TestCase
{
    use PreservesPortaHome;

    private TempProject $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = new TempProject([
            'Sites/shop/composer.json' => '{}',
            'Sites/notes/readme.md' => '',
            'Sites/.hidden/composer.json' => '{}',
            'Sites/a-file' => '',
            'code/api/composer.json' => '{}',
            'Documents/elsewhere/composer.json' => '{}',
            'Sites/deep/inside/composer.json' => '{}',
        ]);
        \putenv('PORTA_HOME='.$this->home->dir);
    }

    private function root(string $relative): string
    {
        return (string) \realpath($this->home->path($relative));
    }

    /** @param list<string> $except @return list<string> */
    private function found(array $except = []): array
    {
        return \array_column(new ProjectDiscovery()->found($except), 'value');
    }

    public function testOnlyFoldersHoldingAComposerJsonOneLevelDownInACodeFolderAreFound(): void
    {
        $found = $this->found();

        $this->assertEqualsCanonicalizing([$this->root('Sites/shop'), $this->root('code/api')], $found);
    }

    public function testTheProjectTouchedLastComesFirst(): void
    {
        \touch($this->home->path('Sites/shop/composer.json'), 1_000);
        \touch($this->home->path('code/api/composer.json'), 2_000);

        $this->assertSame([$this->root('code/api'), $this->root('Sites/shop')], $this->found());
    }

    public function testATieIsBrokenByPathSoTheOrderIsStable(): void
    {
        \touch($this->home->path('Sites/shop/composer.json'), 1_000);
        \touch($this->home->path('code/api/composer.json'), 1_000);

        $expected = [$this->root('Sites/shop'), $this->root('code/api')];
        \sort($expected);
        $this->assertSame($expected, $this->found());
    }

    public function testARecentProjectIsNotOfferedTwice(): void
    {
        $this->assertSame([$this->root('code/api')], $this->found([$this->root('Sites/shop')]));
    }

    public function testAProjectIsNamedAsAPersonKnowsIt(): void
    {
        $offered = new ProjectDiscovery()->found([$this->root('Sites/shop')]);

        $this->assertSame([['label' => 'api', 'hint' => $offered[0]['hint'], 'value' => $this->root('code/api')]], $offered);
        $this->assertStringEndsWith('code/api', $offered[0]['hint']);
    }

    public function testNoMoreThanTwentyAreOffered(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $this->home->write("Projects/p{$i}/composer.json", '{}');
        }

        $this->assertCount(20, $this->found());
    }

    public function testOneFolderReachedByTwoNamesIsOfferedOnce(): void
    {
        \symlink($this->home->path('Sites'), $this->home->path('www'));

        $this->assertCount(2, $this->found());
    }

    public function testAnUnreadableCodeFolderIsSkipped(): void
    {
        if (!$this->home->lock('code')) {
            $this->markTestSkipped('root reads every folder');
        }

        $this->assertSame([$this->root('Sites/shop')], $this->found());
    }

    public function testWithoutAHomeNothingIsFound(): void
    {
        $home = \getenv('HOME');
        \putenv('PORTA_HOME');
        \putenv('HOME');
        try {
            $this->assertSame([], $this->found());
        } finally {
            \putenv('HOME='.$home);
        }
    }
}
