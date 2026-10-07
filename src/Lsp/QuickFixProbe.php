<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\DiagnosticSeverity;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\DiagnosticsChanged;
use Porta\Support\Scheduler;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Asks the language servers, quietly, what they would do about ONE problem —
 * for the problem card's "Fix:" line, which PhpStorm shows under the message
 * of the squiggle the pointer rests on.
 *
 * Answers are kept per problem until the file's diagnostics change or another
 * file comes up, so resting on a squiggle twice asks once. Only the problem
 * asked about LAST hears its answer: a pointer sweeping across several
 * squiggles leaves earlier requests to land in the cache unannounced, which is
 * the cancelling a pool without `$/cancelRequest` can do.
 *
 * The request goes out on the next loop turn, never in the caller's own call
 * stack: a pointer route can run inside a frame hook, and a frame is no place
 * to write to a server.
 */
#[AsIdeService]
final class QuickFixProbe
{
    /** Automatic: asked for on the user's behalf, not by an explicit Alt+Enter. */
    private const int TRIGGER_AUTOMATIC = 2;

    /** @var array<string, FixLookup> problem => what is known about its fixes */
    private array $known = [];

    /** @var array<string, array{offered: list<array<string, mixed>>, answered: int, asked: int}> problems still being answered */
    private array $asking = [];

    /** The problem asked about last: the only one whose answer is announced. */
    private ?string $current = null;

    public function __construct(
        private readonly LspSessionPool $lsp,
        private readonly CodeEditorWidget $editor,
        private readonly Scheduler $scheduler,
    ) {}

    /**
     * What is known about a problem's fixes now. When that is "still asking",
     * $onSettled hears the answer — if no other problem has been asked about
     * since.
     *
     * @param array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>, data?: mixed} $diag
     * @param \Closure(FixLookup): void $onSettled
     */
    public function lookup(int $line, array $diag, \Closure $onSettled): FixLookup
    {
        $path = $this->lsp->ready($this->editor->getFilePath(), $this->editor->getDocument(), FlushMode::Keep);
        if (null === $path) {
            // Not remembered: a server that is still starting may answer later.
            return FixLookup::none();
        }
        $key = \hash('xxh128', \serialize([$path, $line, $diag['col'], $diag['endCol'] ?? null, $diag['message'], $diag['code'] ?? null, $diag['source'] ?? null]));
        $this->current = $key;
        $known = $this->known[$key] ?? null;
        if (null !== $known) {
            return $known;
        }
        $this->known[$key] = FixLookup::pending();
        $lspDiag = CodeActionContext::lspDiagnostic($line, $diag);
        $this->scheduler->delay(0.0, function () use ($key, $path, $lspDiag, $onSettled): void {
            $this->ask($key, $path, $lspDiag, $onSettled);
        });

        return $this->known[$key];
    }

    /** The file's problems changed: what was offered for the old ones may not hold. */
    #[AsEventListener]
    public function onDiagnosticsChanged(DiagnosticsChanged $event): void
    {
        $this->forget();
    }

    #[AsEventListener]
    public function onActiveBufferChanged(ActiveBufferChanged $event): void
    {
        $this->forget();
    }

    /** Every answer, and every question still out: one that lands later is about problems gone. */
    private function forget(): void
    {
        $this->known = [];
        $this->asking = [];
    }

    /**
     * @param array<string, mixed>      $lspDiag
     * @param \Closure(FixLookup): void $onSettled
     */
    private function ask(string $key, string $path, array $lspDiag, \Closure $onSettled): void
    {
        $this->asking[$key] = ['offered' => [], 'answered' => 0, 'asked' => \PHP_INT_MAX];
        $asked = $this->lsp->requestAll(
            'textDocument/codeAction',
            [
                'textDocument' => ['uri' => Uri::fromPath($path)],
                'range' => $lspDiag['range'],
                'context' => ['diagnostics' => [$lspDiag], 'triggerKind' => self::TRIGGER_AUTOMATIC],
            ],
            'codeActionProvider',
            function (mixed $result, ?array $error) use ($key, $onSettled): void {
                $this->answer($key, null === $error && \is_array($result) ? $result : [], $onSettled);
            },
        );
        // Answers arrive on a later loop turn, never inside requestAll():
        // each one is a server's reply over its pipe.
        $this->asking[$key]['asked'] = $asked;
        if (0 === $asked) {
            $this->settle($key, FixLookup::none(), $onSettled);
        }
    }

    /**
     * One server's answer. A preferred fix is the answer whoever else is
     * still thinking; otherwise every server that was asked has its say.
     *
     * @param array<array-key, mixed>   $result
     * @param \Closure(FixLookup): void $onSettled
     */
    private function answer(string $key, array $result, \Closure $onSettled): void
    {
        $asking = $this->asking[$key] ?? null;
        if (null === $asking) {
            return; // settled already
        }
        /** @var list<array<string, mixed>> $offered */
        $offered = [...$asking['offered'], ...\array_values(\array_filter($result, static fn (mixed $a): bool => \is_array($a) && '' !== (string) ($a['title'] ?? '')))];
        $answered = $asking['answered'] + 1;
        $this->asking[$key] = ['offered' => $offered, 'answered' => $answered, 'asked' => $asking['asked']];
        $fix = CodeActionContext::firstQuickFix($offered);
        $last = $answered >= $asking['asked'];
        if (null !== $fix && (true === ($fix['isPreferred'] ?? null) || $last)) {
            $this->settle($key, FixLookup::found((string) $fix['title'], \count($offered)), $onSettled);

            return;
        }
        if ($last) {
            $this->settle($key, FixLookup::none(\count($offered)), $onSettled);
        }
    }

    /** @param \Closure(FixLookup): void $onSettled */
    private function settle(string $key, FixLookup $lookup, \Closure $onSettled): void
    {
        unset($this->asking[$key]);
        $this->known[$key] = $lookup;
        if ($key === $this->current) {
            $onSettled($lookup);
        }
    }
}
