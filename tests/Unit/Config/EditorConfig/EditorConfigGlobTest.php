<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config\EditorConfig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\EditorConfig\EditorConfigGlob;

final class EditorConfigGlobTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function globCases(): iterable
    {
        // star / basename-at-any-depth
        yield 'star ext basename' => ['*.js', 'foo.js', true];
        yield 'star ext in subdir' => ['*.js', 'sub/foo.js', true];
        yield 'star ext mismatch' => ['*.js', 'foo.css', false];
        yield 'star does not cross slash' => ['a*c.txt', 'a/c.txt', false];

        // anchored (contains slash) vs leading-slash anchor
        yield 'dir-relative' => ['lib/*.js', 'lib/a.js', true];
        yield 'dir-relative not deep' => ['lib/*.js', 'lib/sub/a.js', false];
        yield 'leading slash anchors' => ['/root.js', 'root.js', true];
        yield 'leading slash not deep' => ['/root.js', 'sub/root.js', false];

        // double-star
        yield 'doublestar matches zero dirs' => ['**/foo.js', 'foo.js', true];
        yield 'doublestar matches deep' => ['**/foo.js', 'a/b/foo.js', true];
        yield 'doublestar middle zero' => ['a/**/z.txt', 'a/z.txt', true];
        yield 'doublestar middle deep' => ['a/**/z.txt', 'a/b/c/z.txt', true];
        yield 'doublestar middle miss' => ['a/**/z.txt', 'x/z.txt', false];
        yield 'bare doublestar' => ['**', 'any/deep/path.x', true];
        yield 'trailing doublestar' => ['src**', 'srcabc', true];
        // A `**` that is neither before a slash NOR at the end. Every case
        // above has it in one of those two places, where a translator that
        // STOPPED after emitting `.*` produces the same answer as one that
        // carries on — so the miss below is the one that tells them apart: a
        // truncated pattern is a bare `.*` and matches anything at all.
        yield 'doublestar mid-segment' => ['**.js', 'a/b/foo.js', true];
        yield 'doublestar mid-segment miss' => ['**.js', 'a/b/foo.txt', false];

        // question mark
        yield 'question one char' => ['a?c.txt', 'abc.txt', true];
        yield 'question not slash' => ['a?c.txt', 'a/c.txt', false];

        // brace alternation + nesting
        yield 'brace two ext' => ['*.{js,py}', 'a.js', true];
        yield 'brace second ext' => ['*.{js,py}', 'a.py', true];
        yield 'brace neither' => ['*.{js,py}', 'a.rb', false];
        yield 'brace words' => ['{foo,bar}.txt', 'bar.txt', true];
        yield 'brace nested' => ['{a,{b,c}}.txt', 'c.txt', true];
        yield 'class inside brace' => ['{[ab],c}.txt', 'a.txt', true];
        yield 'brace inside class member' => ['{x,[}]}.txt', '}.txt', true];
        yield 'escaped close in brace' => ['{a\\}b,c}.txt', 'a}b.txt', true];

        // single brace is literal
        yield 'single brace literal match' => ['{foo}.txt', '{foo}.txt', true];
        yield 'single brace literal no expand' => ['{foo}.txt', 'foo.txt', false];

        // numeric ranges
        yield 'range middle' => ['file{1..3}.txt', 'file2.txt', true];
        yield 'range out' => ['file{1..3}.txt', 'file4.txt', false];
        yield 'range descending' => ['f{3..1}.txt', 'f2.txt', true];
        yield 'range descending includes its own lower bound' => ['f{3..1}.txt', 'f1.txt', true];
        yield 'range ascending excludes below its lower bound' => ['file{1..3}.txt', 'file0.txt', false];
        yield 'range negative' => ['n{-2..0}.txt', 'n-1.txt', true];

        // character classes
        yield 'class member' => ['[abc].txt', 'b.txt', true];
        yield 'class miss' => ['[abc].txt', 'd.txt', false];
        yield 'class negated' => ['[!abc].txt', 'd.txt', true];
        yield 'class negated excluded' => ['[!abc].txt', 'a.txt', false];
        yield 'class ranges' => ['*.[ch]', 'main.h', true];
        yield 'class leading bracket member' => ['[]ab].txt', '].txt', true];
        yield 'class leading bracket other member' => ['[]ab].txt', 'a.txt', true];
        yield 'class leading bracket miss' => ['[]ab].txt', 'c.txt', false];
        yield 'class escaped bracket' => ['[\\]].txt', '].txt', true];

        // literals / escapes / unterminated
        yield 'escaped star literal' => ['hello\\*.txt', 'hello*.txt', true];
        yield 'escaped star not wildcard' => ['hello\\*.txt', 'helloX.txt', false];
        yield 'trailing backslash literal' => ['a\\', 'a\\', true];
        yield 'unterminated class literal' => ['[abc', '[abc', true];
        yield 'unterminated brace literal' => ['{a,b', '{a,b', true];
        yield 'dot is literal' => ['a.b', 'axb', false];

        // escape not at the very end of the string
        yield 'escaped star mid-string literal' => ['ab\\*', 'ab*', true];
        yield 'escaped star mid-string not wildcard' => ['ab\\*', 'abX', false];

        // double-star not at the very end of the string, crossing a directory
        yield 'doublestar mid-pattern crosses a directory' => ['a/x**', 'a/x/b/c', true];
        yield 'doublestar mid-pattern keeps its own prefix' => ['a/x**', 'totally/different', false];
        yield 'doublestar-slash not at the very end' => ['a/**/z/w.txt', 'a/b/z/w.txt', true];

        // negated class exposes its own `!` marker as a spurious member
        yield 'negated class does not exclude the bang itself' => ['[!abc].txt', '!.txt', true];

        // brace-close-followed-by-more-content (not the very last char)
        yield 'literal brace choice with trailing content' => ['{foo}x.txt', '{foo}x.txt', true];
        yield 'brace numeric range with trailing content' => ['{1..3}x.txt', '2x.txt', true];
        yield 'brace numeric range with trailing content miss' => ['{1..3}x.txt', '9x.txt', false];
        yield 'brace alternation with trailing content' => ['{a,b}x.txt', 'bx.txt', true];

        // numeric range single-value (from === to)
        yield 'range single value' => ['n{5..5}.txt', 'n5.txt', true];
        yield 'range single value miss' => ['n{5..5}.txt', 'n6.txt', false];

        // char-class escape mid-sequence (not the last char before `]`)
        yield 'class escaped bracket with trailing member' => ['[\\]a].txt', 'a.txt', true];

        // nested class inside brace, escape/backslash handling in splitTopLevel
        yield 'brace choice containing an escaped comma' => ['{a\\,b,c}.txt', 'a,b.txt', true];
        yield 'brace nested nested' => ['{a,{b,{c,d}}}.txt', 'd.txt', true];

        // double-star/slash boundary at the very end of the pattern
        yield 'doublestar-slash bare match at the pattern end' => ['a/**/', 'a/', true];

        // alternation/range grouping must stay scoped — a corrupted `|` outside
        // the wrapping (?:...) group would leak into the surrounding literal text
        yield 'brace alternation stays scoped to its own group' => ['*.{js,py}', 'xyzpy', false];
        yield 'numeric range alternation stays scoped to its own group' => ['file{1..3}.txt', '2', false];

        // a range regex without its anchors would accept a garbage prefix/suffix
        // as "close enough" instead of falling back to the literal-brace path
        yield 'range regex start-anchor rejects a garbage prefix' => ['{x1..3}.txt', '{x1..3}.txt', true];
        yield 'range regex end-anchor rejects trailing garbage' => ['{1..3y}.txt', '{1..3y}.txt', true];

        // a `}` that is a MEMBER of a nested class (not the first character
        // after `[`) must not be mistaken for the outer brace's own close
        yield 'a late brace-as-class-member does not end the group early' => ['{[ab}c]end}.txt', '{aend}.txt', true];
        yield 'a late brace-as-class-member does not end the group early other member' => ['{[ab}c]end}.txt', '{cend}.txt', true];
        yield 'a late brace-as-class-member does not end the group early no false accept' => ['{[ab}c]end}.txt', 'aend.txt', false];

        // splitTopLevel's own escape handling must not scramble WHICH char
        // ends up escaped once compileSegment re-parses the split part
        yield 'escaped star survives comma-splitting in brace choices' => ['{a\\*b,c}.txt', 'a*b.txt', true];
        yield 'escaped star inside a brace choice does not become a wildcard' => ['{a\\*b,c}.txt', 'aXXXb.txt', false];

        // a stray closing bracket before any opening one must not push the
        // nesting depth negative and suppress a later, genuinely top-level comma
        yield 'a leading stray close bracket does not swallow the next real comma' => ['{]a,b}.txt', ']a.txt', true];
        yield 'a leading stray close bracket does not swallow the next real comma other choice' => ['{]a,b}.txt', 'b.txt', true];

        // an unterminated class right at the end of the pattern (with, or
        // without, a `!` negation marker) must not read past the string
        yield 'unterminated class at the very end of the pattern' => ['ab[', 'ab[', true];
        yield 'unterminated negated class at the very end of the pattern' => ['ab[!', 'ab[!', true];

        // a nested brace choice, closed, followed by a genuinely top-level
        // comma that is itself preceded by more nested-bracket structure
        yield 'nesting depth tracks two levels deep correctly' => ['{[{a,b}c,z]d,e}.txt', 'ad.txt', true];
        yield 'nesting depth tracks two levels deep correctly other choice' => ['{[{a,b}c,z]d,e}.txt', 'e.txt', true];
        yield 'nesting depth tracks two levels deep correctly no false split' => ['{[{a,b}c,z]d,e}.txt', 'zd.txt', true];
    }

    #[DataProvider('globCases')]
    public function testMatches(string $glob, string $path, bool $expected): void
    {
        $this->assertSame(
            $expected,
            EditorConfigGlob::matches($glob, $path),
            \sprintf('glob=%s path=%s regex=%s', $glob, $path, EditorConfigGlob::compile($glob)),
        );
    }

    public function testCompileReturnsAnchoredPattern(): void
    {
        $this->assertStringStartsWith('#^', EditorConfigGlob::compile('*.js'));
        $this->assertStringEndsWith('$#D', EditorConfigGlob::compile('*.js'));
    }
}
