<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help\Fence;

use PHPUnit\Framework\TestCase;
use Porta\Help\Fence\FenceRenderQueue;
use Porta\Help\Fence\FenceResult;
use Porta\Help\Fence\FenceSession;
use Porta\Help\Fence\MarkdownFenceRegistry;
use Porta\Markdown\MarkdownParser;
use Porta\Markdown\Node\CodeBlock;
use Porta\Tests\Support\FakeScheduler;

final class FenceSessionTest extends TestCase
{
    public function testEditsRetainResultsAndCancelStaleRequests(): void
    {
        $registry = new MarkdownFenceRegistry();
        $scheduler = new FakeScheduler();
        $requests = [];
        $stopped = [];
        $changed = 0;
        $registry->add([[
            'plugin' => 'diagram',
            'languages' => ['mermaid'],
            'render' => static function (string $language, string $source, string $path, int $width, \Closure $success, \Closure $error) use (&$requests, &$stopped): \Closure {
                $requests[] = [$language, $source, $path, $width, $success, $error];

                return static function () use ($source, &$stopped): void {
                    $stopped[] = $source;
                };
            },
        ]]);
        $session = new FenceSession($registry, new FenceRenderQueue($scheduler), static function () use (&$changed): void {
            ++$changed;
        });
        $parser = new MarkdownParser();
        $first = $parser->parse("```mermaid\nfirst\n```\n");
        /** @var CodeBlock $code */
        $code = $first->blocks[0];
        $session->prepare($first, '/doc.md', width: 40);
        $block = $session->block($code);
        $this->assertTrue($block->pending);
        $this->assertNull($block->result);
        $this->assertSame([], $requests);
        $scheduler->fire();
        $this->assertSame(['mermaid', "first\n", '/doc.md', 40], \array_slice($requests[0], offset: 0, length: 4));
        $requests[0][4](FenceResult::rows(['diagram'], [0]));
        $this->assertFalse($block->pending);
        $this->assertSame(['diagram'], $block->result->rows);
        $session->prepare($first, '/doc.md', width: 40);
        $this->assertSame($block, $session->block($code));
        $edited = $parser->parse("prose\n\n```mermaid\ninvalid\n```\n");
        /** @var CodeBlock $editCode */
        $editCode = $edited->blocks[1];
        $session->prepare($edited, '/doc.md', width: 40);
        $edit = $session->block($editCode);
        $this->assertTrue($edit->pending);
        $this->assertSame($block->result, $edit->result);
        $scheduler->fire();
        $requests[1][5]('line 1: invalid statement');
        $this->assertFalse($edit->pending);
        $this->assertSame('line 1: invalid statement', $edit->error);
        $this->assertSame(['diagram'], $edit->result->rows);
        $session->prepare($edited, '/doc.md', width: 10);
        $narrow = $session->block($editCode);
        $this->assertNotSame($edit, $narrow);
        $scheduler->fire();
        $requests[2][4](FenceResult::rows(['narrow'], [0], error: 'mmdc failed'));
        $this->assertSame('mmdc failed', $narrow->error);
        $session->prepare($edited, '/other.md', width: 10);
        $this->assertNull($session->block($editCode)->result, 'a different document must not retain even identical fence text');
        $scheduler->fire();
        $session->clear();
        $requests[3][4](FenceResult::rows(['obsolete'], [0]));
        $this->assertNull($session->block($editCode));
        $this->assertContains("invalid\n", $stopped);
        $session->prepare($first, '/other.md', width: 40);
        $this->assertNull($session->block($code)->result, 'navigation cannot reuse another document result');
        $session->clear();
        $this->assertSame([], $scheduler->scheduled);
    }

    public function testRegistryChangesAndRemovalReleaseBlocks(): void
    {
        $registry = new MarkdownFenceRegistry();
        $scheduler = new FakeScheduler();
        $changed = 0;
        $session = new FenceSession($registry, new FenceRenderQueue($scheduler), static function () use (&$changed): void {
            ++$changed;
        });
        $parser = new MarkdownParser();
        $doc = $parser->parse("```mermaid\na\n```\n\n```dot\nb\n```\n\n    ordinary\n");
        /** @var CodeBlock $code */
        $code = $doc->blocks[0];
        $session->prepare($doc, '/doc.md', width: 0);
        $this->assertNull($session->block($code));
        $registry->add([[
            'plugin' => 'diagram',
            'languages' => ['mermaid', 'dot'],
            'render' => static function (string $language, string $source, string $path, int $width, \Closure $success): \Closure {
                self::assertSame(1, $width);
                $success(FenceResult::rows([$language], [0]));

                return static function (): void {};
            },
        ]]);
        $this->assertSame(1, $changed);
        $session->prepare($doc, '/doc.md', width: 0);
        $scheduler->fire();
        $this->assertSame(['mermaid'], $session->block($code)->result->rows);
        $onlyDot = $parser->parse("```dot\nchanged\n```\n");
        /** @var CodeBlock $dot */
        $dot = $onlyDot->blocks[0];
        $session->prepare($onlyDot, '/doc.md', width: 0);
        $this->assertNull($session->block($dot)->result, 'different diagram kinds cannot retain another kind result');
        $scheduler->fire();
        $registry->remove('diagram');
        $this->assertNull($session->block($dot));
        $session->clear();
    }

    public function testInsertionRemovalAndReorderingCannotTransferOtherResults(): void
    {
        $registry = new MarkdownFenceRegistry();
        $scheduler = new FakeScheduler();
        $success = [];
        $registry->add([[
            'plugin' => 'diagram',
            'languages' => ['mermaid'],
            'render' => static function (string $language, string $source, string $path, int $width, \Closure $ok) use (&$success): \Closure {
                $success[$source] = $ok;

                return static function (): void {};
            },
        ]]);
        $session = new FenceSession($registry, new FenceRenderQueue($scheduler), static function (): void {});
        $parser = new MarkdownParser();
        $initial = $parser->parse("```mermaid\nA\n```\n\n```mermaid\nB\n```\n\n```mermaid\nC\n```\n");
        $session->prepare($initial, '/doc.md', width: 40);
        $scheduler->fire();
        $success["A\n"](FenceResult::rows(['A'], [0]));
        $success["B\n"](FenceResult::rows(['B'], [0]));
        $scheduler->fire();
        $success["C\n"](FenceResult::rows(['C'], [0]));
        $reordered = $parser->parse("```mermaid\ninvalid B\n```\n\n```mermaid\nC\n```\n\n```mermaid\nA\n```\n");
        $session->prepare($reordered, '/doc.md', width: 40);
        /**
         * @var CodeBlock $first
         * @var CodeBlock $second
         * @var CodeBlock $third
         */
        [$first, $second, $third] = $reordered->blocks;
        $this->assertNull($session->block($first)->result, 'exact matches are reserved before edited blocks consider their old slot');
        $this->assertSame(['C'], $session->block($second)->result->rows);
        $this->assertSame(['A'], $session->block($third)->result->rows);
        $session->prepare($initial, '/doc.md', width: 40);
        $scheduler->fire();
        $success["B\n"](FenceResult::rows(['B'], [0]));
        $removed = $parser->parse("```mermaid\ninvalid C\n```\n");
        /** @var CodeBlock $remaining */
        $remaining = $removed->blocks[0];
        $session->prepare($removed, '/doc.md', width: 40);
        $this->assertNull($session->block($remaining)->result, 'removing an earlier block cannot transfer its diagram');
        $inserted = $parser->parse("```mermaid\nnew\n```\n\n```mermaid\ninvalid C\n```\n");
        $session->prepare($inserted, '/doc.md', width: 40);
        /** @var CodeBlock $new */
        $new = $inserted->blocks[0];
        $this->assertNull($session->block($new)->result, 'an inserted fence cannot inherit the old ordinal result');
        $session->clear();
        $this->assertSame([], $scheduler->scheduled);
    }

    public function testThrowingCancellationCannotInterruptPluginRemoval(): void
    {
        $registry = new MarkdownFenceRegistry();
        $scheduler = new FakeScheduler();
        $stopped = 0;
        $changed = 0;
        $registry->add([[
            'plugin' => 'diagram',
            'languages' => ['mermaid'],
            'render' => static function () use (&$stopped): \Closure {
                return static function () use (&$stopped): never {
                    ++$stopped;
                    throw new \RuntimeException('broken cancellation');
                };
            },
        ]]);
        $session = new FenceSession($registry, new FenceRenderQueue($scheduler), static function () use (&$changed): void {
            ++$changed;
        });
        $doc = new MarkdownParser()->parse("```mermaid\nA\n```\n\n```mermaid\nB\n```\n");
        $session->prepare($doc, '/doc.md', width: 40);
        $scheduler->fire();
        $registry->remove('diagram');
        $this->assertSame(2, $stopped, 'all cancellation handles run despite a throwing handle');
        $this->assertSame(1, $changed, 'registry notification must finish');
        /** @var CodeBlock $code */
        $code = $doc->blocks[0];
        $this->assertNull($session->block($code));
        $this->assertNull($registry->renderer('mermaid'));
        $session->clear();
    }
}
