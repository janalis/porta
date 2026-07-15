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
    }

    #[DataProvider('globCases')]
    // @mago-expect lint:no-boolean-flag-parameter -- $expected is the data-provider's expected result, not a control flag
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
