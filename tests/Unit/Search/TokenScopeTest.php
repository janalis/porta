<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\TokenScope;

final class TokenScopeTest extends TestCase
{
    public function testNextCyclesThroughAllThreeAndWrapsAround(): void
    {
        $this->assertSame(TokenScope::InCommentsStrings, TokenScope::Any->next());
        $this->assertSame(TokenScope::ExceptCommentsStrings, TokenScope::InCommentsStrings->next());
        $this->assertSame(TokenScope::Any, TokenScope::ExceptCommentsStrings->next());
    }

    public function testEachCaseHasItsOwnGlyph(): void
    {
        $this->assertSame('·"', TokenScope::Any->glyph());
        $this->assertSame('i"', TokenScope::InCommentsStrings->glyph());
        $this->assertSame('x"', TokenScope::ExceptCommentsStrings->glyph());
    }
}
