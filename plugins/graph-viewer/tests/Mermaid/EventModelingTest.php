<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\EventModelingDiagram;
use Janalis\Viz\Mermaid\EventModelingLayout;
use Janalis\Viz\Mermaid\EventModelingLexer;
use Janalis\Viz\Mermaid\EventModelingParser;
use Janalis\Viz\Mermaid\EventModelingState;
use Janalis\Viz\Mermaid\MermaidColor;
use Janalis\Viz\Mermaid\MermaidPalette;
use Janalis\Viz\Mermaid\MermaidScene;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;

final class EventModelingTest extends TestCase
{
    private static function parse(string $source): EventModelingDiagram
    {
        $parser = new EventModelingParser();
        foreach (new EventModelingLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public static function fixtures(): iterable
    {
        $expected = \json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/eventmodeling-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        $found = \glob(__DIR__.'/../fixtures/mermaid/eventmodeling/*.mmd');
        foreach (false === $found ? [] : $found as $path) {
            yield \basename($path) => [$path, $expected[\basename($path)]];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesMatchTheExecutableUpstreamGrammar(string $path, array $expected): void
    {
        $diagram = self::parse((string) \file_get_contents($path));
        $scene = self::scene((string) \file_get_contents($path));
        $palette = MermaidPalette::defaults('eventmodeling');
        foreach ($expected['colors'] as $index => $fill) {
            $this->assertSame(MermaidColor::css($fill), $palette->resolve($scene->graph->nodes[$index]->fillColor));
        }
        $this->assertNotEmpty(NativeFixtureRows::render((string) \file_get_contents($path), width: 100)->rows);
        $work = EventModelingState::resolve($diagram);
        \iterator_to_array($work);
        $state = $work->getReturn();
        $lanes = [];
        foreach ($diagram->frames as $index => $frame) {
            $lane = $state->frameLanes[$index];
            $lanes[] = ['frame' => $frame['name'], 'index' => $lane, 'label' => $state->lanes[$lane]];
        }
        $this->assertSame($expected['lanes'], $lanes);
        $this->assertSame($expected['relations'], \array_map(static fn (array $edge): array => [$diagram->frames[$edge['from']]['name'], $diagram->frames[$edge['to']]['name']], $state->relations));
        foreach (['frames', 'modelEntities', 'dataEntities', 'noteEntities', 'gwtEntities'] as $key) {
            $actual = [];
            foreach ($diagram->{$key} as $item) {
                unset($item['statement']);
                if ('gwtEntities' === $key) {
                    foreach (['givenStatements', 'whenStatements', 'thenStatements'] as $field) {
                        $item[$field] = \array_map(static fn (array $condition): array => ['entityIdentifier' => $condition['entityIdentifier']], $item[$field]);
                    }
                }
                $actual[] = \array_filter($item, static fn (bool|string|array|null $value): bool => null !== $value);
            }
            $this->assertSame(self::ordered($expected['model'][$key]), self::ordered($actual));
        }
    }

    private static function ordered(array $items): array
    {
        return \array_map(static function (array $item): array {
            \ksort($item);

            return $item;
        }, $items);
    }

    public function testTriviaYieldsAndCanBeCanceledBeforeFramesCommit(): void
    {
        $source = "eventmodeling\n".\str_repeat('/**/', times: 5000)."\ntf 01 ui Screen";
        $tokens = new EventModelingLexer($source)->statements();
        $this->assertSame('eventmodeling', $tokens->current()->text);
        $tokens->next();
        $this->assertSame('', $tokens->current()->text, 'trivia is a cooperative work step');
        $scheduler = new FakeScheduler();
        $finished = [];
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->rows(
            $source,
            80,
            static function () use (&$finished): void {
                $finished[] = 'success';
            },
            static function () use (&$finished): void {
                $finished[] = 'failure';
            },
        );
        $scheduler->fire();
        $this->assertNotEmpty($scheduler->scheduled);
        $this->assertSame([], $finished);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new EventModelingLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }
}
