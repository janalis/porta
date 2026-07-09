<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * The matching flags a project search carries together — regex, case sensitivity,
 * whole-word, preserve-case (during replace), and the language-aware token scope —
 * as one immutable value instead of a tuple threaded through every {@see ProjectSearch}
 * method. The results panel toggles them by its short keys ('regex' | 'case' | 'word' |
 * 'preserve' | 'tokens'), which {@see toggled()} and {@see panelState()} translate to
 * and from this object.
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
     * A copy with the option under the panel key flipped: the booleans toggle,
     * and 'tokens' cycles the {@see TokenScope} (any → in → except → any).
     */
    public function toggled(string $key): self
    {
        return match ($key) {
            'regex' => new self(!$this->regex, $this->caseSensitive, $this->wholeWord, $this->preserveCase, $this->tokenScope),
            'case' => new self($this->regex, !$this->caseSensitive, $this->wholeWord, $this->preserveCase, $this->tokenScope),
            'word' => new self($this->regex, $this->caseSensitive, !$this->wholeWord, $this->preserveCase, $this->tokenScope),
            'preserve' => new self($this->regex, $this->caseSensitive, $this->wholeWord, !$this->preserveCase, $this->tokenScope),
            'tokens' => new self($this->regex, $this->caseSensitive, $this->wholeWord, $this->preserveCase, $this->tokenScope->next()),
            default => $this,
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
