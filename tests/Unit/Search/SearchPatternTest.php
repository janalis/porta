<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\CaseMode;
use Porta\Search\MatchMode;
use Porta\Search\SearchPattern;
use Porta\Search\WordMode;

final class SearchPatternTest extends TestCase
{
    public function testBuildsTheFourOptionCombinations(): void
    {
        $this->assertSame('/a\+b/i', SearchPattern::build('a+b', MatchMode::Literal, WordMode::Anywhere, CaseMode::Insensitive), 'literal queries are quoted');
        $this->assertSame('/a+b/', SearchPattern::build('a+b', MatchMode::Regex, WordMode::Anywhere, CaseMode::Sensitive), 'regex queries pass through');
        $this->assertSame('/\b(?:foo)\b/i', SearchPattern::build('foo', MatchMode::Literal, WordMode::WholeWord, CaseMode::Insensitive));
        $this->assertSame('/\b(?:fo+)\b/', SearchPattern::build('fo+', MatchMode::Regex, WordMode::WholeWord, CaseMode::Sensitive));
    }

    /** `/` delimits the built pattern, so a regex of a path is a path — not a bad regex. */
    public function testARegexSlashIsTheUsersNotTheDelimiter(): void
    {
        $build = static fn (string $query): string => SearchPattern::build($query, MatchMode::Regex, WordMode::Anywhere, CaseMode::Sensitive);

        $this->assertSame('/src\/Fo+/', $build('src/Fo+'));
        $this->assertMatchesRegularExpression($build('src/Fo+'), 'src/Foo');
        $this->assertSame('/a\/b/', $build('a\/b'), 'already escaped: kept as typed');
        $this->assertSame('/a\\\\\/b/', $build('a\\\\/b'), 'an escaped backslash, then a bare slash');
        $this->assertMatchesRegularExpression($build('a\\\\/b'), 'a\/b');
        $this->assertSame('/a\.b\/\//', $build("a\\.b//"), 'other escapes untouched, every bare slash escaped');
    }
}
