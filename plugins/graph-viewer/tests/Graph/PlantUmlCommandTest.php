<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\PlantUmlCommand;
use Porta\Tests\Support\PreservesPath;

/**
 * The three shapes PlantUML arrives in. Pure, so the argv — including the one
 * that needs a JVM — is pinned without running anything.
 */
final class PlantUmlCommandTest extends TestCase
{
    use PreservesPath;

    public function testAnExplicitPathIsRunDirectly(): void
    {
        $this->assertSame('/opt/bin/plantuml', PlantUmlCommand::tool('/opt/bin/plantuml'));
        $this->assertSame(
            ['/opt/bin/plantuml', '-tpng', '-pipe', '-failfast2', '-charset', 'UTF-8', '-Sdpi=96'],
            PlantUmlCommand::argv('/opt/bin/plantuml', 96),
        );
    }

    public function testAJarIsHandedToJava(): void
    {
        // The shape most installs actually have: a downloaded jar, which cannot
        // be executed on its own.
        $this->assertSame(
            ['java', '-jar', '/home/me/lib/plantuml.jar', '-tpng', '-pipe', '-failfast2', '-charset', 'UTF-8', '-Sdpi=192'],
            PlantUmlCommand::argv('/home/me/lib/plantuml.jar', 192),
        );
        $this->assertSame('java', PlantUmlCommand::argv('/x/PlantUML.JAR', 96)[0], 'the extension test is case-insensitive');
    }

    public function testAnEmptySettingFallsBackToDiscovery(): void
    {
        putenv('PATH=/nonexistent');
        $this->assertNull(PlantUmlCommand::tool(''), 'nothing on PATH, nothing configured');
        $this->assertNull(PlantUmlCommand::tool(null));
        $this->assertNull(PlantUmlCommand::tool('   '), 'a blank setting is not a path');
    }

    public function testTheNaturalSizeIsPlantumlsOwnDefault(): void
    {
        // 96, where graphviz's is 72 — the viewer's 1:1 differs per tool.
        $this->assertSame(96, PlantUmlCommand::BASE_DPI);
    }
}
