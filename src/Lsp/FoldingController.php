<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Support\RequestRound;
use Porta\Support\Scheduler;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;

/**
 * Fold All / Unfold All, upgraded by the language server when one is ready:
 * Fold All first asks for `textDocument/foldingRange` and feeds the response
 * into the editor's fold model (valid for the current document version), so
 * folds follow real syntax instead of the naive brace scan. No server, an
 * error, or a timeout all fall back to the brace scan silently — the command
 * must never hang or fail just because the server is slow.
 */
final class FoldingController
{
    public function __construct(
        private readonly LspSessionPool $lsp,
        private readonly CodeEditorWidget $editor,
        private readonly Scheduler $scheduler,
        private readonly Tui $tui,
        private readonly RequestRound $round = new RequestRound(),
    ) {}

    public function foldAll(): void
    {
        $buffer = $this->editor->getFilePath();
        if (null === $buffer) {
            return;
        }
        $doc = $this->editor->getDocument();
        $path = $this->lsp->ready($buffer, $doc);
        if (null === $path) {
            $this->apply('fold_all');

            return;
        }

        $token = $this->round->start();
        $version = $doc->getVersion();
        $timer = $this->scheduler->delay(2.0, function () use ($token): void {
            if (!$this->round->supersede($token)) {
                return;
            }
            $this->apply('fold_all'); // brace fallback — the late response is dropped
        });

        $this->lsp->request(
            'textDocument/foldingRange',
            ['textDocument' => Uri::textDocument($path)],
            function (mixed $result, ?array $error) use ($token, $timer, $doc, $version): void {
                if (!$this->round->supersede($token)) {
                    return; // superseded by the timeout or a newer command
                }
                $this->scheduler->cancel($timer);
                $regions = null === $error && $doc->getVersion() === $version ? self::regions($result) : [];
                if ([] !== $regions) {
                    $this->editor->setFoldRegions($regions);
                }
                $this->apply('fold_all');
            },
        );
    }

    public function unfoldAll(): void
    {
        $this->apply('unfold_all');
    }

    private function apply(string $action): void
    {
        $this->editor->runAction($action);
        $this->tui->requestRender();
    }

    /**
     * Multi-line regions from a foldingRange response (0-based lines already).
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function regions(mixed $result): array
    {
        $regions = [];
        /** @var list<array<string, mixed>|scalar|null> $ranges */
        $ranges = \is_array($result) ? $result : [];
        foreach ($ranges as $range) {
            if (!\is_array($range) || !\is_int($range['startLine'] ?? null) || !\is_int($range['endLine'] ?? null)) {
                continue;
            }
            if ($range['endLine'] > $range['startLine']) {
                $regions[] = [$range['startLine'], $range['endLine']];
            }
        }

        return $regions;
    }
}
