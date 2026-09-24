<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\CompletionItem;
use Porta\Editor\Document;
use Porta\Editor\Templates;
use Porta\Editor\TextMetrics;
use Porta\I18n\Translator;
use Porta\Support\Scheduler;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorCompletionHandler;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * "As you type" language features: completion, its deferred resolve
 * (auto-imports), inlay hints, signature help, and the debounced didChange that
 * re-requests inlay hints once the server has the new text.
 */
final class CompletionController extends AbstractLspController implements EditorCompletionHandler
{
    /**
     * Lines fetched beyond the viewport on each side. Generous: a file under
     * ~1000 lines is always fully hinted, and the settle re-fetch below only
     * fires when a scroll actually leaves the window.
     */
    private const int HINT_MARGIN_LINES = 500;

    /** How long the viewport must hold still before hints re-fetch. */
    private const float SCROLL_SETTLE_SECONDS = 0.25;

    /** @var array{string, int, int}|null path + line range of the last hint request */
    private ?array $hintedRange = null;

    /** Pending settle timer; null when none is armed. */
    private ?string $settleTimer = null;

    /**
     * Which completion request the popup is currently showing.
     *
     * Several servers answer one Ctrl+Space, at their own speeds, and a user
     * keeps typing while they do. Without a generation a late answer to the
     * request before last would reinstate a list for a prefix that is no
     * longer on screen — the list does not merely look stale, it is a list of
     * the wrong things.
     */
    private int $completionGeneration = 0;

    /**
     * The items merged so far for {@see $completionGeneration}, by label so a
     * server offering what another already offered does not double the row.
     * First answer wins that row, which in practice is the language's own
     * server — it is asked first and usually replies first.
     *
     * Each server's block keeps its OWN order and they are appended, rather
     * than re-sorted together: `sortText` is server-relative, so one server's
     * "1" and another's "aaa" say nothing about each other, and mixing them
     * would invent a ranking neither server offered. The popup windows what it
     * paints, so a longer list costs scrolling and not frames — but note that
     * `LspMapper`'s 80-item cap is per ANSWER, so two servers can contribute
     * 160 rows where one contributed 80.
     *
     * @var array<string, CompletionItem>
     */
    private array $completionItems = [];

    public function __construct(
        LspSessionPool $lsp,
        CodeEditorWidget $editor,
        Tui $tui,
        Notifier $notifier,
        Translator $translator,
        private readonly ?Scheduler $scheduler = null,
        private readonly Templates $templates = new Templates(),
    ) {
        parent::__construct($lsp, $editor, $tui, $notifier, $translator);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function templatesFor(string $languageId): array
    {
        return $this->templates->forLanguage($languageId);
    }

    /** Debounce a didChange for $doc, then refresh inlay hints. */
    #[\Override]
    public function scheduleDidChange(Document $doc): void
    {
        $this->lsp->scheduleDidChange(
            $doc,
            fn (): ?string => $this->editor->getFilePath(),
            $this->requestInlayHints(...),
        );
    }

    #[\Override]
    public function requestCompletion(int $line, int $utf16Col): bool
    {
        // Deliberately `ready()` and not `readyOrExplain()`: this path is
        // reached both from Ctrl+Space and from the automatic trigger after
        // `->` and `::`, and the two are indistinguishable here. A flash would
        // fire on ordinary keystrokes in every buffer without a server.
        // Pinned as an allowlisted silent site by LspRefusalTest.
        if (null === ($path = $this->ready())) {
            return false; // Ctrl+Space's own popup says so; the automatic trigger stays silent
        }

        $generation = ++$this->completionGeneration;
        $this->completionItems = [];
        // Every server that says it completes is asked, and the popup grows as
        // they answer: a Symfony server contributing route names beside a PHP
        // server's methods is the whole point of a second server on one
        // language. Answers arrive in any order and some never do, so each one
        // re-renders what is in hand rather than waiting for the set.
        $asked = $this->lsp->requestAll(
            'textDocument/completion',
            $this->positionParams($path, $line, $utf16Col),
            'completionProvider',
            function (mixed $result, ?array $error, string $owner) use ($generation): void {
                // Two different reasons to drop an answer, kept apart: one is
                // about WHEN it arrived, the other about what it says.
                if ($generation !== $this->completionGeneration) {
                    return; // a later request is what the user is waiting on
                }
                if (null !== $error || null === $result) {
                    // Still an answer: with nothing in hand yet, the popup
                    // that said "Loading…" says there is nothing to offer.
                    if ([] === $this->completionItems) {
                        $this->editor->showCompletions([]);
                    }

                    return;
                }
                /** @var list<array<string, mixed>> $items */
                $items = $result['items'] ?? (\is_array($result) && \array_is_list($result) ? $result : []);
                foreach (LspMapper::mapCompletionItems(ServerOwner::stampAll($items, $owner)) as $item) {
                    $this->completionItems[$item->label] ??= $item;
                }
                $this->editor->showCompletions(\array_values($this->completionItems));
                $this->tui->requestRender();
            },
        );

        return 0 < $asked;
    }

    /**
     * Fetch inlay hints (parameter names / inferred types) for the viewport
     * (± {@see self::HINT_MARGIN_LINES}) and hand them to the editor to render
     * as dim inline text. Servers that never advertised `inlayHintProvider`
     * are not asked — the old unconditional request earned an error response
     * per edit burst.
     */
    public function requestInlayHints(): void
    {
        /** @var array<string, mixed>|bool|null $capability LSP: boolean or an InlayHintOptions object */
        $capability = $this->lsp->serverCapability($this->editor->getFilePath(), 'inlayHintProvider');
        if (null === $capability || false === $capability) {
            return;
        }
        $path = (string) $this->editor->getFilePath();
        $lastLine = \max(0, $this->editor->getDocument()->getLineCount() - 1);
        $viewport = $this->editor->viewport();
        $first = \max(0, \min($viewport->vScroll - self::HINT_MARGIN_LINES, $lastLine));
        $last = \min($lastLine, $viewport->vScroll + $viewport->renderRows + self::HINT_MARGIN_LINES);
        $this->hintedRange = [$path, $first, $last];
        $this->lsp->request(
            'textDocument/inlayHint',
            [
                'textDocument' => ['uri' => $this->uri($path)],
                'range' => [
                    'start' => ['line' => $first, 'character' => 0],
                    'end' => ['line' => $last, 'character' => 0],
                ],
            ],
            function (mixed $result) use ($path): void {
                // Ignore errors (result is null) and stale responses after a switch.
                if (\is_array($result) && $path === $this->editor->getFilePath()) {
                    /** @var list<array<string, mixed>> $result */
                    $this->editor->decorations->setInlayHints(LspMapper::mapInlayHints($this->editor->getDocument(), $result));
                    $this->tui->requestRender();
                }
            },
        );
    }

    /**
     * A detach-scroll moved the viewport: once it settles, re-fetch hints if
     * the visible lines left the last requested window. (No scheduler = bare
     * test construction: act immediately, the container always injects one.)
     */
    #[\Override]
    public function viewportScrolled(): void
    {
        if (null === $this->scheduler) {
            $this->refetchHintsIfViewportLeftRange();

            return;
        }
        if (null !== $this->settleTimer) {
            $this->scheduler->cancel($this->settleTimer);
        }
        $this->settleTimer = $this->scheduler->delay(self::SCROLL_SETTLE_SECONDS, function (): void {
            $this->settleTimer = null;
            $this->refetchHintsIfViewportLeftRange();
        });
    }

    private function refetchHintsIfViewportLeftRange(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path) {
            return;
        }
        $viewport = $this->editor->viewport();
        $lastVisible = \min(\max(0, $this->editor->getDocument()->getLineCount() - 1), $viewport->vScroll + $viewport->renderRows);
        $range = $this->hintedRange;
        if (null !== $range && $range[0] === $path && $range[1] <= $viewport->vScroll && $lastVisible <= $range[2]) {
            return; // still inside the hinted window — nothing new to fetch
        }
        $this->requestInlayHints();
    }

    /**
     * Fetch a completion item's deferred `additionalTextEdits` (auto-imports)
     * via completionItem/resolve and apply them to the buffer the completion
     * came from, without moving the caret. Both phpactor and intelephense
     * defer `use` insertions to resolve rather than the initial response.
     *
     * @param array<string, mixed> $item the raw LSP CompletionItem
     */
    #[\Override]
    public function resolveCompletion(array $item): void
    {
        $path = (string) $this->editor->getFilePath();
        $document = $this->editor->getDocument();
        // Back to whoever offered it. Sending a resolve to a server that never
        // saw the item is at best an error response, and at worst a server
        // acting on an id that means something else to it.
        [$owner, $item] = ServerOwner::take($item);
        $this->lsp->requestTo($owner, 'completionItem/resolve', $item, function (mixed $result) use ($path, $document): void {
            /** @var list<array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}> $edits */
            $edits = \is_array($result) ? $result['additionalTextEdits'] ?? [] : [];
            if ([] === $edits) {
                return;
            }
            // Imports may insert lines above an active snippet; drop the session
            // rather than let its tab stops drift.
            $this->editor->clearSnippetSession();
            $document->applyEditsKeepingCursor($edits);
            $this->editor->invalidate();
            $this->lsp->notifyFullChange($path, $document);
            $this->tui->requestRender();
        });
    }

    /**
     * Fetch an item's `detail` — its type or signature — for the popup's type
     * column, from servers that defer it.
     *
     * Measured, and the reason this exists: phpantom_lsp puts `detail` in the
     * completion response, phpactor puts NOTHING there and answers
     * `pub greet(string $who): string` only to `completionItem/resolve`.
     *
     * Gated on the server saying it resolves: sending the request to one that
     * does not is an error response per item per popup, and a server that
     * answers nothing useful should cost nothing at all.
     *
     * The docblock rides along, from the same round-trip: both servers send it
     * here and nowhere else, and asking twice for two halves of one answer
     * would double the traffic for nothing.
     *
     * @param array<string, mixed>           $item
     * @param \Closure(string, string): void $onDetail
     */
    #[\Override]
    public function resolveCompletionDetail(array $item, \Closure $onDetail): void
    {
        [$owner, $item] = ServerOwner::take($item);
        // Asked of THAT server's own capabilities, not of a merged claim: one
        // server resolving details says nothing about another, and the whole
        // point of the gate is to stop asking a server that will only answer
        // an error.
        /** @var mixed $provider */
        $provider = $this->lsp->capabilityOf($owner, 'completionProvider');
        if (!\is_array($provider) || true !== ($provider['resolveProvider'] ?? false)) {
            return;
        }
        $this->lsp->requestTo($owner, 'completionItem/resolve', $item, function (mixed $result) use ($onDetail): void {
            /** @var mixed $detail */
            $detail = \is_array($result) ? $result['detail'] ?? null : null;
            $documentation = \is_array($result) ? LspMapper::documentationText($result['documentation'] ?? null) : null;
            $detail = \is_string($detail) ? $detail : '';
            if ('' === $detail && (null === $documentation || '' === $documentation)) {
                return; // nothing came back that the popup could show
            }
            $onDetail($detail, $documentation ?? '');
            $this->tui->requestRender();
        });
    }

    #[\Override]
    public function requestSignatureHelp(int $line, int $col): void
    {
        // Silent for the same reason as requestCompletion() above: Ctrl+Shift+P
        // and the automatic trigger on `(` and `,` arrive here identically, and
        // typing an argument list in a buffer with no server must not flash on
        // every comma. Allowlisted in LspRefusalTest.
        if (null === ($path = $this->ready())) {
            return;
        }

        $this->lsp->request(
            'textDocument/signatureHelp',
            $this->positionParams($path, $line, $col),
            function (mixed $result, ?array $error): void {
                /** @var array{signatures?: list<array<string, mixed>>, activeSignature?: int, activeParameter?: int} $result */
                $active = \array_key_exists($result['activeSignature'] ?? 0, $result['signatures'] ?? []) ? $result['activeSignature'] ?? 0 : 0;
                $signature = $result['signatures'][$active] ?? null;
                $label = \is_array($signature) ? (string) ($signature['label'] ?? '') : '';
                if (!\is_array($signature) || '' === $label) {
                    $this->editor->setSignatureHint(null);
                    $this->tui->requestRender();

                    return;
                }

                // Every overload with its own active argument, so Alt+↑/↓ can
                // walk them without asking the server again.
                $overloads = [];
                foreach ($result['signatures'] ?? [] as $other) {
                    $otherLabel = (string) ($other['label'] ?? '');
                    $overloads[] = [$otherLabel, self::activeParameterRange($other, $otherLabel, $result['activeParameter'] ?? null)];
                }
                $this->editor->setSignatureHint($label, self::activeParameterRange($signature, $label, $result['activeParameter'] ?? null), $overloads, $active);
                $this->tui->requestRender();
            },
        );
    }

    /**
     * Which slice of the signature label is the argument you are typing.
     *
     * The server has always sent this and Porta took only the label, so a
     * five-parameter signature said the same thing at every comma. LSP gives
     * the parameter either as UTF-16 offsets into the label or as the substring
     * itself; both forms are in the wild, so both are read.
     *
     * Returns BYTE offsets into $label, or null when the server named no
     * parameter or named one that does not fit the label it sent.
     *
     * @param array<string, mixed> $signature
     *
     * @return array{0: int, 1: int}|null
     */
    private static function activeParameterRange(array $signature, string $label, mixed $fallbackIndex): ?array
    {
        /** @var list<array<string, mixed>> $parameters */
        $parameters = \is_array($signature['parameters'] ?? null) ? \array_values($signature['parameters']) : [];
        $index = match (true) {
            \is_int($signature['activeParameter'] ?? null) => $signature['activeParameter'],
            \is_int($fallbackIndex) => $fallbackIndex,
            default => 0,
        };
        $parameter = $parameters[$index] ?? null;
        if (null === $parameter) {
            return null;
        }

        /** @var mixed $name */
        $name = $parameter['label'] ?? null;
        $range = match (true) {
            \is_string($name) => self::rangeOfSubstring($label, $name),
            \is_array($name) && \is_int($name[0] ?? null) && \is_int($name[1] ?? null) => [
                TextMetrics::utf16ColToByteCol($label, $name[0]),
                TextMetrics::utf16ColToByteCol($label, $name[1]),
            ],
            default => null,
        };

        // A range the label cannot contain would emphasise the wrong text, or
        // nothing; saying nothing is the honest answer.
        return null !== $range && $range[0] >= 0 && $range[1] > $range[0] && $range[1] <= \strlen($label) ? $range : null;
    }

    /**
     * No empty-needle guard: `strpos()` answers 0 for one, which yields a
     * zero-width range that the caller's own validation already refuses. A
     * guard no mutation can kill is not a guard.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function rangeOfSubstring(string $label, string $name): ?array
    {
        $at = \strpos($label, $name);

        return false === $at ? null : [$at, $at + \strlen($name)];
    }
}
