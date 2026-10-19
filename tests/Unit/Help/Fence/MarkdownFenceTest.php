<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help\Fence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Help\Fence\FenceResult;
use Porta\Help\Fence\MarkdownFenceRegistry;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PluginException;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\PngFixture;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class MarkdownFenceTest extends TestCase
{
    public function testResultKeepsBytesAndRelativeOrigins(): void
    {
        $bytes = PngFixture::bytes(2, 3);
        $image = FenceResult::png($bytes, error: 'renderer warning');
        $this->assertSame('renderer warning', $image->error);
        $this->assertSame($bytes, $image->png);
        $this->assertSame([], $image->rows);
        $this->assertSame([], $image->origins);
        $rows = FenceResult::rows(['漢字', 'next'], [0, 8]);
        $this->assertNull($rows->png);
        $this->assertSame(['漢字', 'next'], $rows->rows);
        $this->assertSame([0, 8], $rows->origins);
        $this->assertSame([], FenceResult::rows([], [])->rows);
        $this->assertNull($rows->error);
        $this->assertSame('mmdc failed', FenceResult::rows([], [], error: 'mmdc failed')->error);
    }

    public function testRejectsNonPngBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FenceResult::png('not a PNG');
    }

    public function testRejectsSignatureWithoutDimensions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FenceResult::png("\x89PNG\r\n\x1a\n");
    }

    public function testRejectsMissingOrigins(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FenceResult::rows(['one'], []);
    }

    public function testRejectsNegativeOrigins(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FenceResult::rows(['one'], [-1]);
    }

    public function testCommitIsBufferedAndDisableRestoresNextProvider(): void
    {
        $registry = new MarkdownFenceRegistry();
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings, $registry);
        $api = new PluginApi('first', $manager, '/p', $settings);
        $changes = 0;
        $unsubscribe = $registry->onChange(static function () use (&$changes): void {
            ++$changes;
        });
        $callback = static fn (): \Closure => static function (): void {};
        $second = static fn (): \Closure => static function (): void {};
        $api->registerMarkdownFence(['Mermaid', 'mermaid', 'dot'], $callback);
        $this->assertNull($registry->renderer('mermaid'), 'activate buffers cannot become visible before commit');
        $manager->commit($api);
        $this->assertSame(1, $changes);
        $this->assertSame($callback, $registry->renderer('MERMAID'));
        $this->assertSame($callback, $registry->renderer('dot'));
        $this->assertNull($registry->renderer('plantxml'));
        $next = new PluginApi('second', $manager, '/p', $settings);
        $next->registerMarkdownFence(['mermaid'], $second);
        $manager->commit($next);
        $this->assertSame($callback, $registry->renderer('mermaid'));
        $manager->deactivate('first', new CommandRegistry());
        $this->assertSame($second, $registry->renderer('mermaid'));
        $this->assertNull($registry->renderer('dot'));
        $this->assertSame(3, $changes);
        $registry->remove('unknown');
        $registry->add([]);
        $this->assertSame(3, $changes, 'no-op registry updates do not restart rendering');
        $unsubscribe();
        $manager->deactivate('second', new CommandRegistry());
        $this->assertNull($registry->renderer('mermaid'));
        $this->assertSame(3, $changes);
    }

    public function testRegistrationAfterCommitIsRejected(): void
    {
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $api = new PluginApi('demo', $manager, '/p', $settings);
        $manager->commit($api);
        $this->expectException(PluginException::class);
        $api->registerMarkdownFence(['dot'], static fn (): \Closure => static function (): void {});
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidLanguages(): iterable
    {
        yield 'empty' => [[]];
        yield 'blank token' => [['']];
        yield 'whitespace' => [['mermaid dot']];
        yield 'punctuation' => [['dot;']];
    }

    /** @param list<string> $languages */
    #[DataProvider('invalidLanguages')]
    public function testInvalidLanguageRegistrationIsRejected(array $languages): void
    {
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $api = new PluginApi('demo', $manager, '/p', $settings);
        $this->expectException(PluginException::class);
        $api->registerMarkdownFence($languages, static fn (): \Closure => static function (): void {});
    }
}
