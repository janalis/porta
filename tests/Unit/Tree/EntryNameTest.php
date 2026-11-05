<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Tree;

use PHPUnit\Framework\TestCase;
use Porta\Tree\EntryName;

final class EntryNameTest extends TestCase
{
    public function testAPlainNameIsOneSegment(): void
    {
        $this->assertTrue(EntryName::isPlain('A.php'));
        $this->assertTrue(EntryName::isPlain('..A.php'), 'dots inside a name are the name');
        $this->assertFalse(EntryName::isPlain('src/A.php'));
        $this->assertFalse(EntryName::isPlain('..'));
    }

    public function testAPathBelowNeverClimbs(): void
    {
        $this->assertTrue(EntryName::isBelow('sub/A.php'));
        $this->assertFalse(EntryName::isBelow('sub/../../A.php'));
        $this->assertFalse(EntryName::isBelow('./A.php'));
    }

    public function testANulNamesSomethingElse(): void
    {
        $this->assertFalse(EntryName::isBelow("A.php\0.txt"), 'the system call stops at the NUL');
    }
}
