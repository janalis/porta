<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\GanttDate;
use Janalis\Viz\Mermaid\GanttDiagram;
use Janalis\Viz\Mermaid\GanttLexer;
use Janalis\Viz\Mermaid\GanttParser;
use Janalis\Viz\Mermaid\GanttSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GanttTest extends TestCase
{
    private static function calendar(): GanttDate
    {
        return new GanttDate(new \DateTimeImmutable('2026-10-18T12:00:00Z'));
    }

    private static function parse(string $source): GanttDiagram
    {
        $parser = new GanttParser(self::calendar());
        foreach (new GanttLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        $diagram = $parser->finish();
        \iterator_to_array(new GanttSchedule(self::calendar())->resolve($diagram));

        return $diagram;
    }

    public static function fixtures(): iterable
    {
        foreach (\glob(__DIR__.'/../fixtures/mermaid/gantt/*.mmd') as $path) {
            yield \basename($path) => [$path];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesParse(string $path): void
    {
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: \file_get_contents($path));
        $source = \preg_replace('/%%\{.*?\}%%/s', replacement: '', subject: $source);
        $this->assertNotEmpty(self::parse($source)->tasks);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
    }
}
