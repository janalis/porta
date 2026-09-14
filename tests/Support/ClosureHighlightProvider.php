<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Highlight\HighlightRequest;
use Porta\Highlight\HighlightSink;
use Porta\Highlight\SyntaxHighlightProvider;

/**
 * A {@see SyntaxHighlightProvider} driven by a closure, for tests that need to
 * steer the backend — throwing, mangling line structure, or counting calls —
 * without standing up a real engine.
 */
final class ClosureHighlightProvider implements SyntaxHighlightProvider
{
    public int $calls = 0;

    /** @var list<array{0: HighlightRequest, 1: HighlightSink}> answers held back in async mode */
    private array $pending = [];

    /** @param \Closure(string, string): string $highlight (content, language) => markup */
    public function __construct(
        private readonly \Closure $highlight,
        public readonly string $id = 'fake',
        private readonly bool $supports = true,
        private readonly bool $async = false,
    ) {}

    public function supports(string $language): bool
    {
        return $this->supports;
    }

    public function highlight(HighlightRequest $request, HighlightSink $sink): void
    {
        ++$this->calls;

        if ($this->async) {
            // Hold the answer; the test releases it with deliverPending().
            $this->pending[] = [$request, $sink];

            return;
        }

        $sink->deliver($request->documentId, $request->version, ($this->highlight)($request->text, $request->language));
    }

    /** Release every held answer, as an asynchronous backend would from the loop. */
    public function deliverPending(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as [$request, $sink]) {
            $sink->deliver($request->documentId, $request->version, ($this->highlight)($request->text, $request->language));
        }
    }
}
