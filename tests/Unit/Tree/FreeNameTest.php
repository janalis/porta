<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Tree;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\TempProject;
use Porta\Tree\FreeName;

/** Finder's name for a kept copy: the number before the extension, counting up. */
final class FreeNameTest extends TestCase
{
    public function testAFreeNameIsItself(): void
    {
        $from = new TempProject(['a.php' => '']);
        $into = new TempProject();

        $this->assertSame($into->dir.'/a.php', FreeName::for($from->path('a.php'), $into->dir));
    }

    public function testATakenNameCountsUpBeforeTheExtension(): void
    {
        $from = new TempProject(['a.php' => '', 'lib.tar.gz' => '']);
        $into = new TempProject(['a.php' => '', 'a (2).php' => '', 'lib.tar.gz' => '']);

        $this->assertSame($into->dir.'/a (3).php', FreeName::for($from->path('a.php'), $into->dir));
        $this->assertSame($into->dir.'/lib.tar (2).gz', FreeName::for($from->path('lib.tar.gz'), $into->dir), 'the last dot is the extension');
    }

    public function testAFolderADotfileAndABareNameKeepTheirWholeName(): void
    {
        $from = new TempProject(['src.d/x' => '', '.env' => '', 'Makefile' => '']);
        $into = new TempProject(['src.d/x' => '', '.env' => '', 'Makefile' => '']);

        $this->assertSame($into->dir.'/src.d (2)', FreeName::for($from->path('src.d'), $into->dir));
        $this->assertSame($into->dir.'/.env (2)', FreeName::for($from->path('.env'), $into->dir));
        $this->assertSame($into->dir.'/Makefile (2)', FreeName::for($from->path('Makefile'), $into->dir));
    }

    public function testTheKindOfTheSourceDecidesNotTheKindInTheWay(): void
    {
        $from = new TempProject(['config.d/x' => '', 'notes.txt' => '']);
        $into = new TempProject(['config.d' => '', 'notes.txt/x' => '']);

        $this->assertSame($into->dir.'/config.d (2)', FreeName::for($from->path('config.d'), $into->dir), 'a folder over a file');
        $this->assertSame($into->dir.'/notes (2).txt', FreeName::for($from->path('notes.txt'), $into->dir), 'a file over a folder');
    }

    public function testADanglingLinkStillHoldsItsName(): void
    {
        $from = new TempProject(['a.php' => '']);
        $into = new TempProject();
        \symlink($into->dir.'/gone', $into->dir.'/a.php');

        $this->assertSame($into->dir.'/a (2).php', FreeName::for($from->path('a.php'), $into->dir));
    }
}
