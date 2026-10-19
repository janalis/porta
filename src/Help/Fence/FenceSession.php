<?php

declare(strict_types=1);

namespace Porta\Help\Fence;

use Porta\Markdown\Node\CodeBlock;
use Porta\Markdown\Node\Node;

/** One document's requests and completed results; prepare is called outside paint. */
final class FenceSession
{
    /** @var array<int, FenceBlock> body byte offset => current block */
    private array $blocks = [];
    private string $path = '';
    /** @var \Closure(): void */
    private readonly \Closure $unsubscribe;

    /** @param \Closure(): void $changed */
    public function __construct(
        private readonly MarkdownFenceRegistry $registry,
        private readonly FenceRenderQueue $queue,
        private readonly \Closure $changed,
    ) {
        $this->unsubscribe = $registry->onChange(function (): void {
            $this->clear();
            ($this->changed)();
        });
    }

    public function prepare(Node $document, string $path, int $width): void
    {
        if ($path !== $this->path) {
            $this->clear();
            $this->path = $path;
        }
        $previous = \array_values($this->blocks);
        $codes = [];
        $this->collect($document, $codes);
        $next = [];
        $used = [];
        $matched = [];
        foreach ($codes as $index => [$code]) {
            $matched[$index] = self::match($previous, $used, self::language($code), $code->literal);
        }
        foreach ($codes as $index => [$code, $render]) {
            $language = self::language($code);
            $old = $matched[$index] ?? self::previous($previous, $used, $language, $index, \count($codes));
            $next[$code->body->start] = $this->request($language, $code->literal, $path, \max(1, $width), $render, $old);
        }
        foreach ($previous as $block) {
            if (\in_array($block, $next, strict: true)) {
                continue;
            }
            $block->stop();
        }
        $this->blocks = $next;
    }

    public function block(CodeBlock $code): ?FenceBlock
    {
        return $this->blocks[$code->body->start] ?? null;
    }

    /** @param \Closure(int, string): void $selected body-relative source line and semantic ID */
    public function select(int $offset, string $id, \Closure $selected): void
    {
        $block = $this->blocks[$offset] ?? null;
        if (null === $block || !$block->interactive()) {
            return;
        }
        $element = \array_find($block->result->elements ?? [], static fn (FenceElement $element): bool => $id === $element->id);
        if (null !== $element) {
            $last = \substr_count(\rtrim($block->source, characters: "\n"), needle: "\n");
            $selected(\min($last, $element->origin), $id);
        }
    }

    /** @param \Closure(int, string): void $selected */
    public function invoke(int $offset, string $id, \Closure $selected): void
    {
        $block = $this->blocks[$offset] ?? null;
        if (null === $block || !$block->interactive()) {
            return;
        }
        $action = \array_find($block->result->actions ?? [], static fn (FenceAction $action): bool => $id === $action->id);
        if (null === $action) {
            return;
        }
        $number = 1 + (int) \array_search($offset, \array_keys($this->blocks), strict: true);
        $context = new FenceContext($number, function (string $element) use ($offset, $block, $selected): void {
            if (($this->blocks[$offset] ?? null) === $block) {
                $this->select($offset, $element, $selected);
            }
        });
        try {
            ($action->run)($context);
        } catch (\Throwable $failure) {
            $block->error = $failure->getMessage();
            ($this->changed)();
        }
    }

    public function clear(): void
    {
        foreach ($this->blocks as $block) {
            $block->stop();
        }
        $this->blocks = [];
    }

    public function dispose(): void
    {
        $this->clear();
        ($this->unsubscribe)();
    }

    /** @param list<array{CodeBlock, \Closure(string, string, string, int, \Closure(FenceResult): void, \Closure(string): void): \Closure(): void}> $codes */
    private function collect(Node $node, array &$codes): void
    {
        if ($node instanceof CodeBlock && $node->fenced) {
            $render = $this->registry->renderer(self::language($node));
            if (null !== $render) {
                $codes[] = [$node, $render];
            }
        }
        foreach ($node->children() as $child) {
            $this->collect($child, $codes);
        }
    }

    private static function language(CodeBlock $code): string
    {
        return \strtolower(\preg_split('/\s+/', $code->info)[0] ?? '');
    }

    /**
     * @param list<FenceBlock> $previous
     * @param array<int, true> $used
     */
    private static function match(array $previous, array &$used, string $language, string $source): ?FenceBlock
    {
        foreach ($previous as $candidate => $block) {
            if (($used[$candidate] ?? false) || $language !== $block->language || $source !== $block->source) {
                continue;
            }
            $used[$candidate] = true;

            return $block;
        }

        return null;
    }

    /**
     * @param list<FenceBlock> $previous
     * @param array<int, true> $used
     */
    private static function previous(array $previous, array &$used, string $language, int $index, int $count): ?FenceBlock
    {
        // Insertion/removal changes ordinal identity. Retain an edited result
        // only when the number of fences stays fixed and its slot is available.
        $old = $previous[$index] ?? null;
        if (($used[$index] ?? false) || $language !== $old?->language || \count($previous) !== $count) {
            return null;
        }
        $used[$index] = true;

        return $old;
    }

    /** @param \Closure(string, string, string, int, \Closure(FenceResult): void, \Closure(string): void): \Closure(): void $render */
    private function request(string $language, string $source, string $path, int $width, \Closure $render, ?FenceBlock $old): FenceBlock
    {
        if (null !== $old && $language === $old->language && $source === $old->source && $width === $old->width) {
            return $old;
        }
        $old?->stop();
        $block = new FenceBlock($language, $source, $width);
        if ($language === $old?->language) {
            $block->result = $old->result;
            $block->resultSource = $old->resultSource;
        }
        $block->cancel = $this->queue->submit(
            static fn (\Closure $success, \Closure $error): \Closure => $render($language, $source, $path, $width, $success, $error),
            function (FenceResult $result) use ($block): void {
                $block->result = $result;
                $block->resultSource = $block->source;
                $block->error = $result->error;
                $block->pending = false;
                ($this->changed)();
            },
            function (string $message) use ($block): void {
                $block->error = $message;
                $block->pending = false;
                ($this->changed)();
            },
        );

        return $block;
    }
}
