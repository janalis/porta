<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Git\BranchParser;

final class BranchParserTest extends TestCase
{
    public function testParsesNamesAndTheCurrentMarker(): void
    {
        $out = "main\t*\nfeature/login\t\n\nrelease-1.0\t\n";

        $this->assertSame(
            [
                ['name' => 'main', 'current' => true],
                ['name' => 'feature/login', 'current' => false],
                ['name' => 'release-1.0', 'current' => false],
            ],
            BranchParser::parse($out),
        );
    }

    public function testEmptyAndMalformedLinesAreSkipped(): void
    {
        $this->assertSame([], BranchParser::parse(''));
        $this->assertSame([], BranchParser::parse("\n \n\t\n"));
        $this->assertSame([], BranchParser::parse("\t*\n"), 'a marker without a name is skipped');
        $this->assertSame([['name' => 'solo', 'current' => false]], BranchParser::parse("solo\r\n"), 'a line without the tab still parses');
    }
}
