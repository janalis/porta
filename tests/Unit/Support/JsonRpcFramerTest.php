<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\JsonRpcFramer;

final class JsonRpcFramerTest extends TestCase
{
    public function testEncodeProducesAContentLengthFrame(): void
    {
        $frame = JsonRpcFramer::encode(['seq' => 1, 'type' => 'request']);
        $json = '{"seq":1,"type":"request"}';

        $this->assertSame('Content-Length: '.\strlen($json)."\r\n\r\n".$json, $frame);
    }

    public function testEncodeMeasuresBytesNotCharacters(): void
    {
        $frame = JsonRpcFramer::encode(['msg' => 'héllo⏎']);
        $this->assertSame(1, preg_match('/Content-Length: (\d+)/', $frame, $m));
        [$header, $body] = explode("\r\n\r\n", $frame, limit: 2);
        $this->assertSame((int) $m[1], \strlen($body), 'length is the UTF-8 byte count');
    }

    public function testFeedDecodesFramesSplitAcrossArbitraryChunks(): void
    {
        $framer = new JsonRpcFramer();
        $frame = JsonRpcFramer::encode(['id' => 7]);

        $messages = [];
        foreach (str_split($frame, length: 3) as $chunk) {
            $messages = [...$messages, ...$framer->feed($chunk)];
        }

        $this->assertSame([['id' => 7]], $messages);
    }

    public function testFeedDecodesMultipleFramesFromOneChunk(): void
    {
        $framer = new JsonRpcFramer();
        $data = JsonRpcFramer::encode(['n' => 1]).JsonRpcFramer::encode(['n' => 2]);

        $this->assertSame([['n' => 1], ['n' => 2]], $framer->feed($data));
    }

    public function testCorruptHeaderBlockIsDroppedAndTheStreamResyncs(): void
    {
        $framer = new JsonRpcFramer();
        $data = "X-Garbage: yes\r\n\r\n".JsonRpcFramer::encode(['ok' => true]);

        $this->assertSame([['ok' => true]], $framer->feed($data));
    }

    public function testNonArrayJsonBodiesAreSkipped(): void
    {
        $framer = new JsonRpcFramer();
        $body = '"just a string"';
        $data = 'Content-Length: '.\strlen($body)."\r\n\r\n".$body.JsonRpcFramer::encode(['ok' => 1]);

        $this->assertSame([['ok' => 1]], $framer->feed($data));
    }

    public function testAHeaderlessFloodIsDiscardedAtTheCapAndTheStreamResyncs(): void
    {
        $overflows = [];
        $framer = new JsonRpcFramer(static function (string $reason) use (&$overflows): void {
            $overflows[] = $reason;
        }, maxFrame: 64);

        $this->assertSame([], $framer->feed(str_repeat('x', times: 64)), 'exactly at the cap: retained, not yet an overflow');
        $this->assertSame([], $overflows, 'no report at the boundary');
        $this->assertSame([], $framer->feed(str_repeat('x', times: 36)), 'past the cap now');
        $this->assertCount(1, $overflows, 'the overflow was reported');
        $this->assertStringContainsString('no frame header', $overflows[0]);
        // The flood was discarded, so a well-formed frame decodes right after.
        $this->assertSame([['ok' => true]], $framer->feed(JsonRpcFramer::encode(['ok' => true])));
    }

    public function testAnAbsurdDeclaredLengthIsDroppedLikeACorruptHeader(): void
    {
        $overflows = [];
        $framer = new JsonRpcFramer(static function (string $reason) use (&$overflows): void {
            $overflows[] = $reason;
        }, maxFrame: 64);

        // Without the cap this header would park the parser accumulating
        // "body" bytes forever; with it, corrupt-header semantics: drop, resync.
        $this->assertSame([['ok' => 1]], $framer->feed("Content-Length: 9999999999\r\n\r\n".JsonRpcFramer::encode(['ok' => 1])));
        $this->assertCount(1, $overflows);
        $this->assertStringContainsString('exceeds the 64 cap', $overflows[0]);
    }

    public function testAFrameExactlyAtTheCapStillDecodes(): void
    {
        $framer = new JsonRpcFramer(static function (string $reason): void {
            throw new \RuntimeException('no overflow expected: '.$reason);
        }, maxFrame: 64);
        $json = '{"a":"'.str_repeat('b', times: 56).'"}'; // exactly 64 bytes — at the cap, not past it

        $this->assertSame([['a' => str_repeat('b', times: 56)]], $framer->feed('Content-Length: 64'."\r\n\r\n".$json));
    }
}
