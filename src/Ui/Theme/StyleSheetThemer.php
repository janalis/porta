<?php

declare(strict_types=1);

namespace Porta\Ui\Theme;

use Symfony\Component\Tui\Render\Renderer;

/**
 * Writes a theme's rule map into the renderer's live stylesheet. The Renderer
 * clones the sheet it was constructed with, so runtime rules must land on
 * {@see Renderer::getStyleSheet()} — and since a tui StyleSheet can only add
 * rules, a theme switch works by overwriting: every {@see ThemeDefinition}
 * rule map is total over the Role selectors, so re-applying covers every rule
 * of the previous theme. Widget render caches are swept separately, by the
 * ThemeChanged chrome listener.
 */
final class StyleSheetThemer
{
    public function __construct(
        private readonly Renderer $renderer,
    ) {}

    public function apply(ThemeDefinition $definition): void
    {
        $sheet = $this->renderer->getStyleSheet();
        foreach ($definition->rules() as $selector => $style) {
            $sheet->addRule($selector, $style);
        }
    }
}
