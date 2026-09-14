<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * The matching flags a project search carries together — regex, case sensitivity,
 * whole-word, preserve-case (during replace), and the language-aware token scope —
 * as one immutable value instead of a tuple threaded through every {@see ProjectSearch}
 * method. The modal toggles them by {@see SearchOption}, and reads them back as
 * {@see panelState()}, keyed by each option's value.
 */
final readonly class SearchOptions
{
    public function __construct(
        public bool $regex = false,
        public bool $caseSensitive = false,
        public bool $wholeWord = false,
        public bool $preserveCase = false,
        public TokenScope $tokenScope = TokenScope::Any,
    ) {}

    /**
     * A copy with the option flipped: the booleans toggle, and
     * {@see SearchOption::Tokens} cycles the {@see TokenScope} (any → in → except → any).
     */
    public function toggled(SearchOption $option): self
    {
        return match ($option) {
            SearchOption::Regex => new self(!$this->regex, $this->caseSensitive, $this->wholeWord, $this->preserveCase, $this->tokenScope),
            SearchOption::CaseSensitive => new self($this->regex, !$this->caseSensitive, $this->wholeWord, $this->preserveCase, $this->tokenScope),
            SearchOption::WholeWord => new self($this->regex, $this->caseSensitive, !$this->wholeWord, $this->preserveCase, $this->tokenScope),
            SearchOption::PreserveCase => new self($this->regex, $this->caseSensitive, $this->wholeWord, !$this->preserveCase, $this->tokenScope),
            SearchOption::Tokens => new self($this->regex, $this->caseSensitive, $this->wholeWord, $this->preserveCase, $this->tokenScope->next()),
        };
    }

    /**
     * The panel's option view; 'tokens' carries the {@see TokenScope} value.
     *
     * @return array{regex: bool, case: bool, word: bool, preserve: bool, tokens: string}
     */
    public function panelState(): array
    {
        return ['regex' => $this->regex, 'case' => $this->caseSensitive, 'word' => $this->wholeWord, 'preserve' => $this->preserveCase, 'tokens' => $this->tokenScope->value];
    }
}
