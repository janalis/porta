<?php

declare(strict_types=1);

namespace Porta\GraphViewer;

use Janalis\Viz\Render\Format;
use Porta\GraphViewer\Mermaid\NativeMermaidExport;
use Porta\Help\Fence\FenceAction;
use Porta\Help\Fence\FenceContext;
use Porta\Help\Fence\FenceElement;
use Porta\Support\AnsiText;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** Inline exports use the successful fence snapshot and the plugin's shared cancellable export slot. */
final readonly class NativeFenceActions
{
    /** @param list<FenceElement> $elements
     * @param \Closure(string, array<string, string>): string $translate
     * @param \Closure(string, float): void $flash
     * @param \Closure(string, list<array{label: string, hint: string, value: string}>, \Closure(string): void): \Closure $pick
     */
    public function __construct(
        private string $source,
        private string $path,
        private array $elements,
        private NativeMermaidExport $exports,
        private \Closure $translate,
        private \Closure $flash,
        private \Closure $pick,
    ) {}

    /** @return list<FenceAction> */
    public function actions(): array
    {
        $actions = [];
        if ([] !== $this->elements) {
            $actions[] = new FenceAction('find-node', ($this->translate)('Find node', []), $this->find(...));
        }
        foreach (Format::cases() as $format) {
            $actions[] = new FenceAction('export-'.$format->extension(), \strtoupper($format->extension()), fn (FenceContext $context) => $this->export($context, $format));
        }

        return $actions;
    }

    private function find(FenceContext $context): void
    {
        $choices = [];
        foreach ($this->elements as $element) {
            $choices[] = ['label' => AnsiText::oneLine(AnsiUtils::stripAnsiCodes($element->label)), 'hint' => (string) ($element->origin + 1), 'value' => $element->id];
        }
        ($this->pick)(($this->translate)('Find node', []), $choices, $context->select(...));
    }

    private function export(FenceContext $context, Format $format): void
    {
        $path = \dirname($this->path).'/'.\pathinfo($this->path, flags: \PATHINFO_FILENAME).'-diagram-'.$context->number.'.'.$format->extension();
        $this->exports->export($this->source, $format, $path, function (?string $error) use ($path): void {
            $message = null === $error
                ? ($this->translate)('Exported %file%', ['%file%' => \basename($path)])
                : ($this->translate)('Export failed: %error%', ['%error%' => $error]);
            ($this->flash)($message, null === $error ? 3.0 : 5.0);
        });
    }
}
