<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\SearchPattern;

final class SearchPatternTest extends TestCase
{
    public function testBuildsTheFourOptionCombinations(): void
    {
        $this->assertSame('/a\+b/i', SearchPattern::build('a+b', regex: false, wholeWord: false, caseSensitive: false), 'literal queries are quoted');
        $this->assertSame('/a+b/', SearchPattern::build('a+b', regex: true, wholeWord: false, caseSensitive: true), 'regex queries pass through');
        $this->assertSame('/\b(?:foo)\b/i', SearchPattern::build('foo', regex: false, wholeWord: true, caseSensitive: false));
        $this->assertSame('/\b(?:fo+)\b/', SearchPattern::build('fo+', regex: true, wholeWord: true, caseSensitive: true));
    }
}
