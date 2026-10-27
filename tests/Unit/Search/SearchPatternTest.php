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
        $this->assertSame('/a\+b/iu', SearchPattern::build('a+b', MatchMode::Literal, WordMode::Anywhere, CaseMode::Insensitive), 'literal queries are quoted');
        $this->assertSame('/a+b/u', SearchPattern::build('a+b', MatchMode::Regex, WordMode::Anywhere, CaseMode::Sensitive), 'regex queries pass through');
        $this->assertSame('/\b(?:foo)\b/iu', SearchPattern::build('foo', MatchMode::Literal, WordMode::WholeWord, CaseMode::Insensitive));
        $this->assertSame('/\b(?:fo+)\b/u', SearchPattern::build('fo+', MatchMode::Regex, WordMode::WholeWord, CaseMode::Sensitive));
    }

    /** `/` delimits the built pattern, so a regex of a path is a path — not a bad regex. */
    public function testARegexSlashIsTheUsersNotTheDelimiter(): void
    {
        $build = static fn (string $query): string => SearchPattern::build($query, MatchMode::Regex, WordMode::Anywhere, CaseMode::Sensitive);

        $this->assertSame('/src\/Fo+/u', $build('src/Fo+'));
        $this->assertMatchesRegularExpression($build('src/Fo+'), 'src/Foo');
        $this->assertSame('/a\/b/u', $build('a\/b'), 'already escaped: kept as typed');
        $this->assertSame('/a\\\\\/b/u', $build('a\\\\/b'), 'an escaped backslash, then a bare slash');
        $this->assertMatchesRegularExpression($build('a\\\\/b'), 'a\/b');
        $this->assertSame('/a\.b\/\//u', $build("a\\.b//"), 'other escapes untouched, every bare slash escaped');
    }

    /** Inside `\Q…\E` every character is literal, so a slash there is closed out of the quote, not given a backslash. */
    public function testASlashInsideAQuoteIsStillASlash(): void
    {
        $build = static fn (string $query): string => SearchPattern::build($query, MatchMode::Regex, WordMode::Anywhere, CaseMode::Sensitive);

        $this->assertSame('/\Qa\E\/\Qb\E/u', $build('\Qa/b\E'));
        $this->assertMatchesRegularExpression($build('\Qa/b\E'), 'a/b');
        $this->assertDoesNotMatchRegularExpression($build('\Qa/b\E'), 'a\/b');
        $this->assertMatchesRegularExpression($build('\Q/\E+'), '//', 'the quote closed only around the slash: what follows it is still regex');
        $this->assertSame('/\\\\Q\//u', $build('\\\\Q/'), 'an escaped backslash before a Q opens no quote');
    }

    /** Ignoring case folds É to é as it folds E to e; a line that is not UTF-8 is still searched, byte for byte. */
    public function testIgnoringCaseFoldsEveryLetterAndALatin1LineIsStillSearched(): void
    {
        $pattern = SearchPattern::build('CAFÉ', MatchMode::Literal, WordMode::Anywhere, CaseMode::Insensitive);

        $this->assertMatchesRegularExpression($pattern, 'un café noir');
        $this->assertMatchesRegularExpression(SearchPattern::build('café', MatchMode::Literal, WordMode::WholeWord, CaseMode::Sensitive), 'un café noir', 'é is a letter to \\b');
        $latin1 = "un caf\xe9 noir, CAFE";
        $this->assertMatchesRegularExpression(
            SearchPattern::fit(SearchPattern::build('cafe', MatchMode::Literal, WordMode::Anywhere, CaseMode::Insensitive), $latin1),
            $latin1,
            'a Latin-1 line is matched byte for byte',
        );
        $this->assertSame($pattern, SearchPattern::fit($pattern, 'café'), 'a UTF-8 line keeps the Unicode pattern');
    }
}
