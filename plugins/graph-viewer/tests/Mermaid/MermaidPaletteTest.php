<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;

/** The palette's token grammar and the theme paths no census family reaches. */
final class MermaidPaletteTest extends TestCase
{
    public function testAThemeThatCannotDeriveItsColorsIsALocatedError(): void
    {
        $scheduler = new FakeScheduler();
        $errors = [];
        new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "---\nconfig: {theme: base, themeVariables: {primaryColor: zzz}}\n---\nflowchart TD\nA",
            static function (): void {},
            static function (string $error) use (&$errors): void {
                $errors[] = $error;
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Mermaid theme configuration cannot be drawn', $errors[0]);
    }
}
