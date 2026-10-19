<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Mermaid\Theme\MermaidAppearance;
use Porta\GraphViewer\Mermaid\Theme\MermaidDirective;
use Porta\GraphViewer\Mermaid\Theme\MermaidThemes;

/**
 * One renderer's theme and option data: the bundled programs and configuration facts load once,
 * and the last few resolved appearances and options are reused while a document is edited.
 */
final class MermaidStyleSource
{
    private const int REMEMBERED = 16;

    private ?MermaidThemes $themes = null;
    private ?MermaidDirective $directives = null;
    private ?MermaidOptionSchema $schema = null;
    /** @var array<string, array{MermaidAppearance, MermaidOptions}> */
    private array $resolved = [];

    /** @throws \InvalidArgumentException When the chosen theme cannot derive its colors. */
    public function appearance(MermaidConfiguration $configuration, string $type, string $section): MermaidAppearance
    {
        return $this->resolve($configuration, $type, $section)[0];
    }

    /** @throws \InvalidArgumentException When the chosen theme cannot derive its colors. */
    public function options(MermaidConfiguration $configuration, string $type, string $section): MermaidOptions
    {
        return $this->resolve($configuration, $type, $section)[1];
    }

    /**
     * @return array{MermaidAppearance, MermaidOptions}
     * @throws \InvalidArgumentException When the chosen theme cannot derive its colors.
     */
    private function resolve(MermaidConfiguration $configuration, string $type, string $section): array
    {
        // Serialized, not JSON: YAML front matter may hold `.inf` and `.nan`, which JSON cannot write.
        $key = \serialize([$configuration->frontMatter, $configuration->inits, $type, $section]);
        $resolved = $this->resolved[$key] ?? null;
        if (null !== $resolved) {
            return $resolved;
        }
        $this->directives ??= MermaidDirective::bundled();
        $this->themes ??= MermaidThemes::bundled();
        $this->schema ??= MermaidOptionSchema::bundled();
        $directive = $this->directives->document($configuration->frontMatter, $configuration->inits, $type);
        $resolved = [MermaidAppearance::resolve([$directive], $section, $this->themes), new MermaidOptions($directive, $this->schema)];
        if (\count($this->resolved) >= self::REMEMBERED) {
            \array_shift($this->resolved);
        }

        return $this->resolved[$key] = $resolved;
    }
}
