<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\TestCase;
use Porta\Desktop\FolderCompletion;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\TempProject;

/** Completing a typed folder the way a shell does. */
final class FolderCompletionTest extends TestCase
{
    use PreservesPortaHome;

    private TempProject $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = new TempProject([
            'Sites/shop/composer.json' => '{}',
            'Sites/shopify-app/readme.md' => '',
            'Sites/Shelf/readme.md' => '',
            'Sites/blog/readme.md' => '',
            'Sites/shopping-list' => '',
            'Sites/.config/x' => '',
        ]);
        \putenv('PORTA_HOME='.$this->home->dir);
    }

    /** @return list<string> */
    private function names(string $typed): array
    {
        return \array_column(FolderCompletion::candidates($typed), 'name');
    }

    public function testAPathIsWhatAShellWouldReadAsOne(): void
    {
        $this->assertTrue(FolderCompletion::isPath('~/Sites'));
        $this->assertTrue(FolderCompletion::isPath('/usr'));
        $this->assertTrue(FolderCompletion::isPath('./here'));
        $this->assertFalse(FolderCompletion::isPath('shop'));
        $this->assertFalse(FolderCompletion::isPath(''));
    }

    public function testTheFoldersStartingWithTheSegmentAreOfferedInNaturalOrderAnyCase(): void
    {
        $this->assertSame(['Shelf', 'shop', 'shopify-app'], $this->names('~/Sites/sh'));
    }

    public function testAFileIsNoCandidate(): void
    {
        $this->assertNotContains('shopping-list', $this->names('~/Sites/shop'));
    }

    public function testHiddenFoldersOnlyWhenTheSegmentAsksForThem(): void
    {
        $this->assertNotContains('.config', $this->names('~/Sites/'));
        $this->assertSame(['.config'], $this->names('~/Sites/.'));
    }

    public function testACandidateIsSpelledAsTypedAndSaysWhetherItIsAProject(): void
    {
        $this->assertSame(
            [['name' => 'shop', 'path' => '~/Sites/shop/', 'isProject' => true], ['name' => 'shopify-app', 'path' => '~/Sites/shopify-app/', 'isProject' => false]],
            FolderCompletion::candidates('~/Sites/shop'),
        );
    }

    public function testAnAbsolutePathCompletesToo(): void
    {
        $sites = $this->home->path('Sites/');

        $this->assertSame([['name' => 'blog', 'path' => $sites.'blog/', 'isProject' => false]], FolderCompletion::candidates($sites.'bl'));
    }

    public function testAPathWithoutASlashIsLookedForHere(): void
    {
        $previous = (string) \getcwd();
        \chdir($this->home->path('Sites'));
        try {
            $this->assertSame([['name' => 'blog', 'path' => 'blog/', 'isProject' => false]], FolderCompletion::candidates('bl'));
        } finally {
            \chdir($previous);
        }
    }

    public function testABareTildeIsTheHomeFolder(): void
    {
        $this->assertSame(['Sites'], $this->names('~'));
    }

    public function testAMissingFolderOffersNothing(): void
    {
        $this->assertSame([], FolderCompletion::candidates('~/Nowhere/x'));
    }

    public function testTabTakesTheChosenFolder(): void
    {
        $this->assertSame('~/Sites/blog/', FolderCompletion::complete('~/Sites/sh', '~/Sites/blog/'));
    }

    public function testTabCompletesTheOneCandidate(): void
    {
        $this->assertSame('~/Sites/blog/', FolderCompletion::complete('~/Sites/b'));
    }

    public function testTabCompletesAsMuchAsEveryCandidateShares(): void
    {
        $this->assertSame('~/Sites/shop', FolderCompletion::complete('~/Sites/sho'));
        $this->assertSame('~/Sites/shop', FolderCompletion::complete('~/Sites/shop'));
    }

    public function testTabNeverTakesBackWhatWasTyped(): void
    {
        // Shelf and shop share only `sh`, already typed.
        $this->assertSame('~/Sites/sh', FolderCompletion::complete('~/Sites/sh'));
    }

    public function testTabWithNothingToCompleteChangesNothing(): void
    {
        $this->assertSame('~/Sites/zzz', FolderCompletion::complete('~/Sites/zzz'));
    }
}
