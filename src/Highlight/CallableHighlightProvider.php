<?php

declare(strict_types=1);

namespace Porta\Highlight;

/**
 * A {@see SyntaxHighlightProvider} assembled from closures — how a plugin's
 * registration reaches the registry.
 *
 * The language list is resolved lazily on every `supports()` so a plugin whose
 * claim list follows its own settings picks up an edit without re-registering,
 * the same laziness `registerLanguageServer`'s command resolver has. `'*'`
 * claims every language.
 *
 * `$isAvailable` mirrors that resolver's "unavailable means unserved" rule: a
 * backend whose binary is missing declines, so the language falls through to
 * the next provider instead of rendering as an error.
 */
final class CallableHighlightProvider implements SyntaxHighlightProvider
{
    /**
     * @param \Closure(): list<string>        $languages   resolved per query; `['*']` claims everything
     * @param \Closure(string, string, \Closure(string): void): void $highlight (content, language, deliver)
     * @param (\Closure(): bool)|null          $isAvailable null means always available
     */
    public function __construct(
        private readonly string $id,
        private readonly \Closure $languages,
        private readonly \Closure $highlight,
        private readonly ?\Closure $isAvailable = null,
    ) {}

    #[\Override]
    public function id(): string
    {
        return $this->id;
    }

    #[\Override]
    public function supports(string $language): bool
    {
        if ('' === $language) {
            return false;
        }
        if (null !== $this->isAvailable && !($this->isAvailable)()) {
            return false;
        }

        $claimed = ($this->languages)();

        return \in_array('*', $claimed, strict: true) || \in_array($language, $claimed, strict: true);
    }

    #[\Override]
    public function highlight(HighlightRequest $request, HighlightSink $sink): void
    {
        ($this->highlight)($request->text, $request->language, static function (string $markup) use ($request, $sink): void {
            $sink->deliver($request->documentId, $request->version, $markup);
        });
    }
}
