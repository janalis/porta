<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\SearchOptions;
use Porta\Search\TokenScope;

final class SearchOptionsTest extends TestCase
{
    public function testDefaultsAreAllOff(): void
    {
        $options = new SearchOptions();

        $this->assertFalse($options->regex);
        $this->assertFalse($options->caseSensitive);
        $this->assertFalse($options->wholeWord);
        $this->assertFalse($options->preserveCase);
        $this->assertSame(TokenScope::Any, $options->tokenScope);
        $this->assertSame(['regex' => false, 'case' => false, 'word' => false, 'preserve' => false, 'tokens' => 'any'], $options->panelState());
    }

    public function testToggledFlipsTheKeyedFlagAndLeavesTheRest(): void
    {
        $this->assertTrue(new SearchOptions()->toggled('regex')->regex);
        $this->assertTrue(new SearchOptions()->toggled('case')->caseSensitive);
        $this->assertTrue(new SearchOptions()->toggled('word')->wholeWord);
        $this->assertTrue(new SearchOptions()->toggled('preserve')->preserveCase);

        // Toggling one leaves the others untouched.
        $onlyWord = new SearchOptions()->toggled('word');
        $this->assertFalse($onlyWord->regex);
        $this->assertFalse($onlyWord->caseSensitive);

        // Toggling twice returns to off.
        $this->assertFalse(new SearchOptions()->toggled('regex')->toggled('regex')->regex);

        // 'tokens' cycles the three-way token scope: any → in → except → any.
        $this->assertSame(TokenScope::InCommentsStrings, new SearchOptions()->toggled('tokens')->tokenScope);
        $this->assertSame(TokenScope::ExceptCommentsStrings, new SearchOptions()->toggled('tokens')->toggled('tokens')->tokenScope);
        $this->assertSame(TokenScope::Any, new SearchOptions()->toggled('tokens')->toggled('tokens')->toggled('tokens')->tokenScope);
    }

    public function testAnUnknownKeyIsANoOp(): void
    {
        $options = new SearchOptions(regex: true);
        $this->assertEquals($options, $options->toggled('nonsense'));
    }

    public function testPanelStateMapsFieldsToTheShortKeys(): void
    {
        $options = new SearchOptions(regex: true, caseSensitive: false, wholeWord: true, preserveCase: true, tokenScope: TokenScope::InCommentsStrings);
        $this->assertSame(['regex' => true, 'case' => false, 'word' => true, 'preserve' => true, 'tokens' => 'in'], $options->panelState());
    }
}
