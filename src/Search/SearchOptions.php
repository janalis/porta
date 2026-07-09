<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * The matching flags a project search carries together — regex, case sensitivity,
 * whole-word, and preserve-case (during replace) — as one immutable value instead
 * of a bool tuple threaded through every {@see ProjectSearch} method. The results
 * panel toggles them by its short keys ('regex' | 'case' | 'word' | 'preserve'),
 * which {@see toggled()} and {@see panelState()} translate to and from this object.
 */
final readonly class SearchOptions
{
    public function __construct(
        public bool $regex = false,
        public bool $caseSensitive = false,
        public bool $wholeWord = false,
        public bool $preserveCase = false,
    ) {}

    /** A copy with the option under the panel key ('regex' | 'case' | 'word' | 'preserve') flipped. */
    public function toggled(string $key): self
    {
        return match ($key) {
            'regex' => new self(!$this->regex, $this->caseSensitive, $this->wholeWord, $this->preserveCase),
            'case' => new self($this->regex, !$this->caseSensitive, $this->wholeWord, $this->preserveCase),
            'word' => new self($this->regex, $this->caseSensitive, !$this->wholeWord, $this->preserveCase),
            'preserve' => new self($this->regex, $this->caseSensitive, $this->wholeWord, !$this->preserveCase),
            default => $this,
        };
    }

    /**
     * The panel's `['regex' => bool, 'case' => bool, 'word' => bool, 'preserve' => bool]` view.
     *
     * @return array{regex: bool, case: bool, word: bool, preserve: bool}
     */
    public function panelState(): array
    {
        return ['regex' => $this->regex, 'case' => $this->caseSensitive, 'word' => $this->wholeWord, 'preserve' => $this->preserveCase];
    }
}
