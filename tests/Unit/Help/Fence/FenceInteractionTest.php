<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help\Fence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Help\Fence\FenceAction;
use Porta\Help\Fence\FenceContext;
use Porta\Help\Fence\FenceElement;
use Porta\Help\Fence\FenceResult;
use Porta\Help\Fence\FenceSession;
use Porta\Markdown\MarkdownParser;
use Porta\Tests\Support\FenceProvider;
use Porta\Tests\Support\PngFixture;

final class FenceInteractionTest extends TestCase
{
    private static function element(string $id = 'one', int $origin = 0): FenceElement
    {
        return new FenceElement($id, 'Label', $origin, [[0.2, 0.2], [0.8, 0.2], [0.8, 0.8], [0.2, 0.8]]);
    }

    public function testSilhouettesProduceHalfOpenCellSpansIncludingDisconnectedRegions(): void
    {
        $square = self::element();
        $this->assertSame([[2, 8]], $square->spans(0.5, width: 10));
        $this->assertSame([], $square->spans(0.1, width: 10));
        $this->assertSame([], $square->spans(0.8, width: 10));
        $this->assertSame([], $square->spans(0.5, width: 0));
        $triangle = new FenceElement('triangle', 'Triangle', 2, [[0.5, 0.0], [1.0, 1.0], [0.0, 1.0]]);
        $this->assertSame([[2, 8]], $triangle->spans(0.5, width: 10));
        $split = new FenceElement('split', 'Split', 2, [[0.0, 0.0], [0.3, 0.0], [0.3, 0.7], [0.7, 0.7], [0.7, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]]);
        $this->assertSame([[0, 3], [7, 10]], $split->spans(0.2, width: 10));
    }

    public static function invalidElements(): iterable
    {
        $polygon = [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]];
        yield 'empty id' => ['', 'label', 0, $polygon];
        yield 'long id' => [\str_repeat('x', times: 513), 'label', 0, $polygon];
        yield 'negative origin' => ['one', 'label', -1, $polygon];
        yield 'long label' => ['one', \str_repeat('x', times: 131_073), 0, $polygon];
        yield 'no polygon' => ['one', 'label', 0, []];
        yield 'many points' => ['one', 'label', 0, \array_fill(0, count: 513, value: [0.0, 0.0])];
        foreach ([-0.1, 1.1, \INF, \NAN] as $index => $value) {
            yield 'invalid x '.$index => ['one', 'label', 0, [[$value, 0.0], [1.0, 0.0], [1.0, 1.0]]];
            yield 'invalid y '.$index => ['one', 'label', 0, [[0.0, $value], [1.0, 0.0], [1.0, 1.0]]];
        }
    }

    #[DataProvider('invalidElements')]
    public function testInvalidSemanticGeometryIsRejected(string $id, string $label, int $origin, array $polygon): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FenceElement($id, $label, $origin, $polygon);
    }

    public function testResultsPreserveImagesAndRowsAndRejectDuplicateOrExcessiveMetadata(): void
    {
        $element = self::element();
        $action = new FenceAction('one', 'SVG', static function (): void {});
        $result = FenceResult::rows(['row'], [3], error: 'warning')->interactive([$element], [$action]);
        $this->assertSame([['row'], [3], 'warning', [$element], [$action]], [$result->rows, $result->origins, $result->error, $result->elements, $result->actions]);
        $png = PngFixture::bytes(8, 8);
        $this->assertSame($png, FenceResult::png($png)->interactive([$element])->png);
        foreach ([
            [[$element, $element], []],
            [[], [$action, $action]],
            [\array_fill(0, count: 4097, value: $element), []],
            [[], \array_fill(0, count: 17, value: $action)],
        ] as [$elements, $actions]) {
            try {
                $result->interactive($elements, $actions);
                $this->fail('Duplicate or excessive metadata accepted');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('Fence interaction', $exception->getMessage());
            }
        }
    }

    public static function invalidActions(): iterable
    {
        yield ['', 'SVG'];
        yield ['bad:id', 'SVG'];
        yield [\str_repeat('x', times: 65), 'SVG'];
        yield ['export', ''];
        yield ['export', \str_repeat('x', times: 513)];
    }

    #[DataProvider('invalidActions')]
    public function testActionIdentifiersAndLabelsAreBounded(string $id, string $label): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FenceAction($id, $label, static function (): void {});
    }

    public function testActionsUseCurrentResultsClampOriginsAndIgnoreLateSelections(): void
    {
        $provider = new FenceProvider();
        $changed = 0;
        $session = new FenceSession($provider->registry, $provider->queue, static function () use (&$changed): void {
            ++$changed;
        });
        $document = new MarkdownParser()->parse("```mermaid\nfirst\nsecond\n```\n");
        $code = $document->blocks[0];
        $session->prepare($document, '/doc.md', width: 40);
        $provider->drain();
        $contexts = [];
        $action = new FenceAction('find-node', 'Find', static function (FenceContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });
        $selected = [];
        $pick = static function (int $line, string $id) use (&$selected): void {
            $selected[] = [$line, $id];
        };
        $session->invoke($code->body->start, 'find-node', $pick);
        $this->assertSame([], $contexts);
        $provider->requests[0]['success'](FenceResult::rows(['row'], [0])->interactive([self::element(origin: 99)], [$action]));
        $session->invoke($code->body->start, 'unknown', $pick);
        $session->invoke(999, 'find-node', $pick);
        $session->select($code->body->start, 'unknown', $pick);
        $session->select(999, 'one', $pick);
        $session->invoke($code->body->start, 'find-node', $pick);
        $this->assertCount(1, $contexts);
        $this->assertSame(1, $contexts[0]->number);
        $contexts[0]->select('one');
        $this->assertSame([[1, 'one']], $selected);
        $edited = new MarkdownParser()->parse("```mermaid\nchanged\n```\n");
        $session->prepare($edited, '/doc.md', width: 40);
        $contexts[0]->select('one');
        $session->invoke($edited->blocks[0]->body->start, 'find-node', $pick);
        $this->assertCount(1, $contexts);
        $this->assertCount(1, $selected);
        $provider->drain();
        $provider->requests[1]['error']('invalid');
        $session->invoke($edited->blocks[0]->body->start, 'find-node', $pick);
        $this->assertCount(1, $contexts);
        $this->assertGreaterThan(0, $changed);
        $session->clear();
    }

    public function testActionsReceiveTheRenderedDiagramNumber(): void
    {
        $provider = new FenceProvider();
        $session = new FenceSession($provider->registry, $provider->queue, static function (): void {});
        $document = new MarkdownParser()->parse("```mermaid\nfirst\n```\n\n```mermaid\nsecond\n```\n");
        $session->prepare($document, '/doc.md', width: 40);
        $provider->drain();
        $numbers = [];
        $action = new FenceAction('export', 'SVG', static function (FenceContext $context) use (&$numbers): void {
            $numbers[] = $context->number;
        });
        foreach ($provider->requests as $request) {
            $request['success'](FenceResult::rows(['row'], [0])->interactive([], [$action]));
        }
        foreach ($document->blocks as $code) {
            $session->invoke($code->body->start, 'export', static function (): void {});
        }
        $this->assertSame([1, 2], $numbers);
        $session->clear();
    }

    public function testPendingResizesAndReplacedResultsRetireTheirPickerContexts(): void
    {
        $provider = new FenceProvider();
        $session = new FenceSession($provider->registry, $provider->queue, static function (): void {});
        $document = new MarkdownParser()->parse("```mermaid\nsource\n```\n");
        $offset = $document->blocks[0]->body->start;
        $contexts = [];
        $action = new FenceAction('find', 'Find', static function (FenceContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });
        $result = FenceResult::rows(['row'], [0])->interactive([self::element()], [$action]);
        $selected = [];
        $pick = static function (int $line, string $id) use (&$selected): void {
            $selected[] = $id;
        };
        $session->prepare($document, '/doc.md', width: 40);
        $provider->drain();
        $provider->requests[0]['success']($result);
        $session->invoke($offset, 'find', $pick);
        $this->assertCount(1, $contexts);
        $session->prepare($document, '/doc.md', width: 20);
        $session->invoke($offset, 'find', $pick);
        $this->assertCount(1, $contexts, 'a retained result does not activate while its resized replacement is pending');
        $provider->drain();
        $provider->requests[1]['success']($result);
        $contexts[0]->select('one');
        $this->assertSame([], $selected, 'a picker is tied to the result that opened it');
        $session->invoke($offset, 'find', $pick);
        $contexts[1]->select('one');
        $this->assertSame(['one'], $selected);
        $session->clear();
    }

    public function testActionFailuresRetainTheResultAndReportItsError(): void
    {
        $provider = new FenceProvider();
        $session = new FenceSession($provider->registry, $provider->queue, static function (): void {});
        $document = new MarkdownParser()->parse("```mermaid\nsource\n```\n");
        $code = $document->blocks[0];
        $session->prepare($document, '/doc.md', width: 40);
        $provider->drain();
        $action = new FenceAction('bad', 'Broken', static function (): never {
            throw new \RuntimeException('action failed');
        });
        $provider->requests[0]['success'](FenceResult::rows(['row'], [0])->interactive([], [$action]));
        $session->invoke($code->body->start, 'bad', static function (): void {});
        $this->assertSame('action failed', $session->block($code)->error);
        $this->assertSame(['row'], $session->block($code)->result->rows);
        $session->clear();
    }
}
