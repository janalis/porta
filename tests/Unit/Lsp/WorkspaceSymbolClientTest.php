<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceSymbolClient;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\FixedActiveFile;

final class WorkspaceSymbolClientTest extends TestCase
{
    public function testMapProjectRelativizesAndLabelsByKind(): void
    {
        $mapped = WorkspaceSymbolClient::map(
            [
                ['name' => 'User', 'kind' => 5, 'location' => ['uri' => 'file:///proj/src/User.php', 'range' => ['start' => ['line' => 9]]]],
                ['name' => 'greet', 'kind' => 12, 'location' => ['uri' => 'file:///proj/src/f.php', 'range' => ['start' => ['line' => 2]]]],
            ],
            new ProjectPaths('/proj'),
        );

        $this->assertSame(
            [
                ['path' => 'src/User.php', 'line' => 9, 'name' => 'User', 'kind' => 5],
                ['path' => 'src/f.php', 'line' => 2, 'name' => 'greet', 'kind' => 12],
            ],
            $mapped,
        );
    }

    public function testMapSkipsUriLessSymbolsAndHandlesUnknownKindAndMissingRange(): void
    {
        $mapped = WorkspaceSymbolClient::map(
            [
                ['name' => 'NoUri', 'kind' => 5], // no location → skipped
                ['name' => 'Bare', 'location' => ['uri' => 'file:///proj/a.php']], // no kind → bare name; no range → line 0
            ],
            new ProjectPaths('/proj'),
        );

        $this->assertCount(1, $mapped, 'the uri-less symbol is dropped');
        $this->assertSame('Bare', $mapped[0]['name']);
        $this->assertSame(0, $mapped[0]['line']);
        $this->assertSame(0, $mapped[0]['kind']);
    }

    public function testMapHasARangeButNoLineIsAlsoZero(): void
    {
        // The 'start' key IS present (unlike the "missing range" case above),
        // just without its own 'line' — a different fallback path to the same 0.
        $mapped = WorkspaceSymbolClient::map(
            [['name' => 'X', 'location' => ['uri' => 'file:///proj/a.php', 'range' => ['start' => []]]]],
            new ProjectPaths('/proj'),
        );

        $this->assertSame(0, $mapped[0]['line']);
    }

    public function testMapCastsRawKindNameAndLineToTheirDeclaredTypes(): void
    {
        // A server that sends numeric strings instead of ints (seen in the
        // wild) must not leak the raw type into the mapped row.
        $mapped = WorkspaceSymbolClient::map(
            [['name' => 123, 'kind' => '5', 'location' => ['uri' => 'file:///proj/a.php', 'range' => ['start' => ['line' => '9']]]]],
            new ProjectPaths('/proj'),
        );

        $this->assertSame(5, $mapped[0]['kind'], 'cast to int, not left as the string "5"');
        $this->assertSame('123', $mapped[0]['name'], 'cast to string, not left as the int 123');
        $this->assertSame(9, $mapped[0]['line'], 'cast to int, not left as the string "9"');
    }

    public function testMapOfANonArrayResultIsEmpty(): void
    {
        $this->assertSame([], WorkspaceSymbolClient::map('not an array', new ProjectPaths('/proj')));
    }

    public function testSearchWithoutAReadyServerReturnsEmptyImmediately(): void
    {
        $pool = new LspSessionPool('/nowhere', new FixedActiveFile(), new Translator('en'));
        $client = new WorkspaceSymbolClient($pool, new ProjectPaths('/nowhere'));

        $got = null;
        $client->search('x', static function (array $result) use (&$got): void {
            $got = $result;
        });

        $this->assertSame([], $got, 'no initialized server → empty result, synchronously');
    }
}
