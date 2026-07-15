<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Git\BlameParser;

final class BlameParserTest extends TestCase
{
    private const string SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string SHA_ZERO = '0000000000000000000000000000000000000000';

    public function testMetadataArrivesOnlyOnACommitsFirstBlock(): void
    {
        // Line 1 and 3 share commit A (metadata only on the first block),
        // line 2 is commit B — the interleaving is the porcelain norm.
        $porcelain = implode("\n", [
            self::SHA_A.' 1 1 1',
            'author Alice',
            'author-time 1700000000',
            'summary first',
            "\tcode line one",
            self::SHA_B.' 2 2 1',
            'author Bob',
            'author-time 1710000000',
            'summary second',
            "\tcode line two",
            self::SHA_A.' 3 3',
            "\tcode line three",
        ]);

        $this->assertSame(
            [
                0 => ['sha' => 'aaaaaaaa', 'author' => 'Alice', 'date' => date('Y-m-d', 1700000000)],
                1 => ['sha' => 'bbbbbbbb', 'author' => 'Bob', 'date' => date('Y-m-d', 1710000000)],
                2 => ['sha' => 'aaaaaaaa', 'author' => 'Alice', 'date' => date('Y-m-d', 1700000000)],
            ],
            BlameParser::parse($porcelain),
        );
    }

    public function testUncommittedLinesKeepGitsAuthorAndGetNoDate(): void
    {
        $porcelain = implode("\n", [
            self::SHA_ZERO.' 1 1 1',
            'author Not Committed Yet',
            'summary Version of the file as it is now',
            "\tdirty line",
        ]);

        $this->assertSame([0 => ['sha' => '00000000', 'author' => 'Not Committed Yet', 'date' => '']], BlameParser::parse($porcelain));
    }

    public function testMalformedAndEmptyInputYieldNothing(): void
    {
        $this->assertSame([], BlameParser::parse(''));
        $this->assertSame([], BlameParser::parse("author Orphan\nnot-a-header line\n"), 'metadata before any header is dropped');
    }
}
