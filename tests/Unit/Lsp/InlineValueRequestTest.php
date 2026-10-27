<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Lsp\InlineValueRequest;
use Porta\Lsp\Uri;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeLspPool;
use Porta\Tests\Support\FixedActiveFile;

/**
 * The LSP half of the inline debug values: where the variables are, by name.
 *
 * Measured on phpactor before it was written — the only bundled server that
 * advertises `inlineValueProvider`. Stopped inside a method with three locals
 * it answers one `InlineValueVariableLookup` per variable, and the fixtures
 * below are that answer.
 */
final class InlineValueRequestTest extends TestCase
{
    private FakeLspClient $client;

    private FixedActiveFile $activeFile;

    /** @param array<string, mixed> $capabilities */
    private function request(array $capabilities): InlineValueRequest
    {
        $pool = FakeLspPool::create('/p', $this->activeFile = new FixedActiveFile(), fn (): FakeLspClient => $this->client = new FakeLspClient($capabilities));
        $this->activeFile->set('/p/a.php');
        $pool->notifyDidOpen('/p/a.php', "<?php\n\$count = 41;\n", 'php', 1);

        return new InlineValueRequest($pool);
    }

    public function testAServerThatDoesNotOfferItIsNotAsked(): void
    {
        // phpantom_lsp's case: it advertises fourteen other providers and not
        // this one. Asking anyway is one request per stop, answered with an
        // error, for nothing.
        $request = $this->request([]);

        $request->forRange('/p/a.php', 0, 40, frameId: 1, stoppedLine: 12, deliver: static function (array $lookups): never {
            self::fail('a server that cannot answer should not be asked');
        });

        $this->assertSame([], $this->client->requests);
    }

    public function testTheRangeAndTheStoppedFrameRideAlong(): void
    {
        // `context.frameId` is what makes the answer specific to this stop: a
        // server may report only the variables assigned by the time execution
        // reached the line.
        $request = $this->request(['inlineValueProvider' => true]);

        $request->forRange('/p/a.php', 3, 44, frameId: 7, stoppedLine: 12, deliver: static function (array $lookups): void {});

        // The WHOLE payload, not a field at a time: this is a wire shape, and
        // a server reads every key of it. A range missing its `character`, or a
        // `stoppedLocation` with only a start, is a request some server answers
        // with an error and this one would not notice.
        $this->assertSame('textDocument/inlineValue', $this->client->requests[0]['method']);
        $this->assertSame(
            [
                'textDocument' => ['uri' => Uri::fromPath('/p/a.php')],
                'range' => [
                    'start' => ['line' => 3, 'character' => 0],
                    'end' => ['line' => 44, 'character' => 0],
                ],
                'context' => [
                    'frameId' => 7,
                    'stoppedLocation' => [
                        'start' => ['line' => 12, 'character' => 0],
                        'end' => ['line' => 12, 'character' => 0],
                    ],
                ],
            ],
            $this->client->requests[0]['params'],
        );
    }

    public function testTheServersLookupsComeBackAsLineNameAndColumn(): void
    {
        $request = $this->request(['inlineValueProvider' => true]);
        $seen = [];

        $request->forRange('/p/a.php', 0, 40, frameId: 1, stoppedLine: 12, deliver: static function (array $lookups) use (&$seen): void {
            $seen = $lookups;
        });
        $this->client->requests[0]['respond']([[
            'range' => ['start' => ['line' => 14, 'character' => 8], 'end' => ['line' => 14, 'character' => 14]],
            'variableName' => '$count',
            'caseSensitiveLookup' => true,
        ]]);

        $this->assertSame([['line' => 14, 'endCol' => 14, 'name' => '$count']], $seen);
    }

    public function testAnEmptyANSWERDeliversNothingRatherThanAnEmptyPaint(): void
    {
        // "The server had nothing to say about this range" is not the same as
        // "clear what is on screen" — the caller clears on its own events, and
        // an empty delivery here would race with them.
        $request = $this->request(['inlineValueProvider' => true]);
        $delivered = false;

        $request->forRange('/p/a.php', 0, 40, frameId: 1, stoppedLine: 12, deliver: static function (array $lookups) use (&$delivered): void {
            $delivered = true;
        });
        $this->client->requests[0]['respond']([]);

        $this->assertFalse($delivered);
    }
}
