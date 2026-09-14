<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\SearchOption;
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
        $this->assertTrue(new SearchOptions()->toggled(SearchOption::Regex)->regex);
        $this->assertTrue(new SearchOptions()->toggled(SearchOption::CaseSensitive)->caseSensitive);
        $this->assertTrue(new SearchOptions()->toggled(SearchOption::WholeWord)->wholeWord);
        $this->assertTrue(new SearchOptions()->toggled(SearchOption::PreserveCase)->preserveCase);

        // Toggling one leaves the others untouched.
        $onlyWord = new SearchOptions()->toggled(SearchOption::WholeWord);
        $this->assertFalse($onlyWord->regex);
        $this->assertFalse($onlyWord->caseSensitive);

        // Toggling twice returns to off.
        $this->assertFalse(new SearchOptions()->toggled(SearchOption::Regex)->toggled(SearchOption::Regex)->regex);

        // 'tokens' cycles the three-way token scope: any → in → except → any.
        $this->assertSame(TokenScope::InCommentsStrings, new SearchOptions()->toggled(SearchOption::Tokens)->tokenScope);
        $this->assertSame(TokenScope::ExceptCommentsStrings, new SearchOptions()->toggled(SearchOption::Tokens)->toggled(SearchOption::Tokens)->tokenScope);
        $this->assertSame(TokenScope::Any, new SearchOptions()->toggled(SearchOption::Tokens)->toggled(SearchOption::Tokens)->toggled(SearchOption::Tokens)->tokenScope);
    }

    public function testPanelStateMapsFieldsToTheShortKeys(): void
    {
        $options = new SearchOptions(regex: true, caseSensitive: false, wholeWord: true, preserveCase: true, tokenScope: TokenScope::InCommentsStrings);
        $this->assertSame(['regex' => true, 'case' => false, 'word' => true, 'preserve' => true, 'tokens' => 'in'], $options->panelState());
    }
}
