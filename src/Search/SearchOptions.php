<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * The three matching flags a project search carries together — regex, case
 * sensitivity and whole-word — as one immutable value instead of a bool triplet
 * threaded through every {@see ProjectSearch} method. The results panel toggles
 * them by its short keys ('regex' | 'case' | 'word'), which {@see toggled()} and
 * {@see panelState()} translate to and from this object's fields.
 */
final readonly class SearchOptions
{
    public function __construct(
        public bool $regex = false,
        public bool $caseSensitive = false,
        public bool $wholeWord = false,
    ) {}

    /** A copy with the option under the panel key ('regex' | 'case' | 'word') flipped. */
    public function toggled(string $key): self
    {
        return match ($key) {
            'regex' => new self(!$this->regex, $this->caseSensitive, $this->wholeWord),
            'case' => new self($this->regex, !$this->caseSensitive, $this->wholeWord),
            'word' => new self($this->regex, $this->caseSensitive, !$this->wholeWord),
            default => $this,
        };
    }

    /**
     * The panel's `['regex' => bool, 'case' => bool, 'word' => bool]` view of these flags.
     *
     * @return array{regex: bool, case: bool, word: bool}
     */
    public function panelState(): array
    {
        return ['regex' => $this->regex, 'case' => $this->caseSensitive, 'word' => $this->wholeWord];
    }
}
