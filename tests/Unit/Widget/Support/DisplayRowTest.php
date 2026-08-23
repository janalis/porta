<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Support;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Support\DisplayRow;
use Porta\Widget\Support\HeaderState;

final class DisplayRowTest extends TestCase
{
    public function testFileHeaderCarriesNoMatchIndexAndIsNotADirectory(): void
    {
        $row = DisplayRow::fileHeader('a.php', 3, HeaderState::Expanded);

        $this->assertSame('a.php', $row->filePath);
        $this->assertSame(3, $row->count);
        $this->assertFalse($row->collapsed);
        $this->assertSame(-1, $row->matchIndex, 'a header row is never a match');
        $this->assertFalse($row->isDirectory);
    }

    public function testDirHeaderIsMarkedAsADirectory(): void
    {
        $row = DisplayRow::dirHeader('src', 5, HeaderState::Collapsed);

        $this->assertTrue($row->collapsed);
        $this->assertTrue($row->isDirectory);
        $this->assertSame(-1, $row->matchIndex);
    }

    public function testMatchRowCarriesTheGivenIndexAndDefaultsEverythingElse(): void
    {
        $row = DisplayRow::match(7);

        $this->assertSame(7, $row->matchIndex);
        $this->assertNull($row->filePath);
        $this->assertSame(0, $row->count, 'a match row has no group count');
        $this->assertFalse($row->collapsed, 'a match row is never collapsed');
        $this->assertSame(-1, $row->contextLine, 'the constructor default: a match row has no context line');
        $this->assertNull($row->contextText);
    }

    public function testContextRowCarriesItsLineAndText(): void
    {
        $row = DisplayRow::context(42, 'the surrounding source line');

        $this->assertNull($row->filePath);
        $this->assertSame(-1, $row->matchIndex, 'a context row is never a match');
        $this->assertSame(42, $row->contextLine);
        $this->assertSame('the surrounding source line', $row->contextText);
    }
}
