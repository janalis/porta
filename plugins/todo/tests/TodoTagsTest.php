<?php

declare(strict_types=1);

namespace Porta\Todo\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Todo\TodoTags;

/** The tag list → regex alternation the scan greps for. */
final class TodoTagsTest extends TestCase
{
    public function testTheListBecomesAnAlternation(): void
    {
        $this->assertSame('TODO|FIXME|HACK|XXX|BUG', TodoTags::pattern(TodoTags::DEFAULT));
        $this->assertSame('HACK', TodoTags::pattern('HACK'), 'one tag is a degenerate alternation');
        $this->assertSame('TODO|NOTE', TodoTags::pattern('  TODO , NOTE  '), 'spacing around a tag is the user\'s, not the pattern\'s');
    }

    /** A tag is matched as written: `FIX.ME` is no wildcard, and `TODO(` no bad regex that finds nothing. */
    public function testATagIsLiteralText(): void
    {
        $pattern = TodoTags::pattern('FIX.ME, TODO(, C++, a\b, [x]{2}|^$');

        $this->assertSame('FIX\.ME|TODO\(|C\+\+|a\\\\b|\[x\]\{2\}\|\^\$', $pattern);
        $this->assertDoesNotMatchRegularExpression('/'.$pattern.'/', 'FIXAME');
        $this->assertMatchesRegularExpression('/'.$pattern.'/', '// TODO( later');
    }

    public function testABlankListFallsBackRatherThanGreppingForNothing(): void
    {
        // An empty alternation matches every line, so the fallback is not
        // politeness — it is what stops "tags: " from listing the project.
        $this->assertSame('TODO|FIXME', TodoTags::pattern(''));
        $this->assertSame('TODO|FIXME', TodoTags::pattern('  ,  , '));
    }
}
