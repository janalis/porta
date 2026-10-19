<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidTitle;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\Tests\Support\FakeScheduler;

final class FlowchartConfigurationTest extends TestCase
{
    public function testLargeTitleSourceMapsStayCancellable(): void
    {
        $scheduler = new FakeScheduler();
        $scene = new MermaidScene(new GraphLayout(20, 200_000, [], [], []), \array_fill(0, count: 100_000, value: 5), []);
        $work = MermaidTitle::apply($scene, new MermaidStatement('Heading', offset: 0, line: 1, column: 0), topMargin: 0.1);
        $complete = false;
        $cancel = new MermaidTask($scheduler)->run(
            $work,
            static function (MermaidScene $result) use (&$complete): void {
                $complete = true;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertFalse($complete);
        $this->assertNotEmpty($scheduler->scheduled);
        $cancel();
        $this->assertEmpty($scheduler->scheduled);
        $this->assertFalse($complete);
        $this->assertCount(100_000, $scene->origins);
    }
}
