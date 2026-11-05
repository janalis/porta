<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * Pull diagnostics — LSP 3.17's `textDocument/diagnostic`: the server says
 * what is wrong with a file when asked, rather than publishing it unasked.
 *
 * A server that only answers this way publishes nothing at all — phpantom
 * advertises `diagnosticProvider` and never sends `publishDiagnostics` — so an
 * editor that only listens shows none of its findings. Each answer goes down
 * the same path a publish does, marked `pulled`: a server that also publishes
 * — rust-analyzer pushes its cargo check — keeps the two apart, rather than
 * each wiping the other.
 *
 * One file is asked about when it is opened or changed; every open file when
 * the server asks for a refresh, or on a save when a file's findings depend on
 * other files (`interFileDependencies`). Only the latest question about a file
 * counts: an answer to an earlier one, describing text that has since changed,
 * is dropped.
 */
final class DiagnosticPull
{
    /** @var array<string, int> uri => the number of the latest question about it */
    private array $asked = [];

    /**
     * Questions asked so far, of every file. One count for all, never reset:
     * a file closed and reopened must not ask a question numbered like one
     * still being answered about its old text.
     */
    private int $questions = 0;

    /** @var array<string, string> uri => the server's id for the findings it last sent */
    private array $resultIds = [];

    /**
     * @param array<array-key, mixed>                                   $provider the server's `diagnosticProvider`
     * @param \Closure(string, mixed, callable(mixed, mixed): void): void $request  sends a request to the server
     * @param \Closure(array<string, mixed>): void                       $publish  takes `publishDiagnostics`-shaped params
     */
    public function __construct(
        private readonly array $provider,
        private readonly \Closure $request,
        private readonly \Closure $publish,
    ) {}

    /** Whether a file's findings can change when ANOTHER file does. */
    public function dependsOnOtherFiles(): bool
    {
        return true === ($this->provider['interFileDependencies'] ?? false);
    }

    public function pull(string $uri): void
    {
        $question = ++$this->questions;
        $this->asked[$uri] = $question;
        $params = ['textDocument' => ['uri' => $uri]];
        if (\is_string($this->provider['identifier'] ?? null)) {
            $params['identifier'] = $this->provider['identifier'];
        }
        if (\array_key_exists($uri, $this->resultIds)) {
            $params['previousResultId'] = $this->resultIds[$uri];
        }
        ($this->request)('textDocument/diagnostic', $params, function (mixed $result) use ($uri, $question): void {
            // An error, an older question, or `unchanged`: what is shown stands.
            if ($question !== ($this->asked[$uri] ?? null) || !\is_array($result) || 'full' !== ($result['kind'] ?? null)) {
                return;
            }
            unset($this->resultIds[$uri]);
            if (\is_string($result['resultId'] ?? null)) {
                $this->resultIds[$uri] = $result['resultId'];
            }
            ($this->publish)(['uri' => $uri, 'diagnostics' => \is_array($result['items'] ?? null) ? \array_values($result['items']) : [], 'pulled' => true]);
        });
    }

    /**
     * The file is closed: nobody will ask about it again, so what it showed
     * would stay on screen forever — clear it, as a publishing server does.
     */
    public function forget(string $uri): void
    {
        if (!\array_key_exists($uri, $this->asked)) {
            return;
        }
        unset($this->asked[$uri], $this->resultIds[$uri]);
        ($this->publish)(['uri' => $uri, 'diagnostics' => [], 'pulled' => true]);
    }
}
