<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceSymbolClient;

final class WorkspaceSymbolClientTest extends TestCase
{
    public function testMapProjectRelativizesAndLabelsByKind(): void
    {
        $mapped = WorkspaceSymbolClient::map(
            [
                ['name' => 'User', 'kind' => 5, 'location' => ['uri' => 'file:///proj/src/User.php', 'range' => ['start' => ['line' => 9]]]],
                ['name' => 'greet', 'kind' => 12, 'location' => ['uri' => 'file:///proj/src/f.php', 'range' => ['start' => ['line' => 2]]]],
            ],
            '/proj',
        );

        $this->assertSame(
            [
                ['path' => 'src/User.php', 'line' => 9, 'name' => 'User', 'kind' => 5, 'label' => '[class] User'],
                ['path' => 'src/f.php', 'line' => 2, 'name' => 'greet', 'kind' => 12, 'label' => '[fn] greet'],
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
            '/proj',
        );

        $this->assertCount(1, $mapped, 'the uri-less symbol is dropped');
        $this->assertSame('Bare', $mapped[0]['label'], 'unknown kind → no [kind] prefix');
        $this->assertSame(0, $mapped[0]['line']);
        $this->assertSame(0, $mapped[0]['kind']);
    }

    public function testMapOfANonArrayResultIsEmpty(): void
    {
        $this->assertSame([], WorkspaceSymbolClient::map('not an array', '/proj'));
    }

    public function testSearchWithoutAReadyServerReturnsEmptyImmediately(): void
    {
        $pool = new LspSessionPool('/nowhere', static fn (): ?string => null, new Translator('en'));
        $client = new WorkspaceSymbolClient($pool, '/nowhere');

        $got = null;
        $client->search('x', function (array $result) use (&$got): void {
            $got = $result;
        });

        $this->assertSame([], $got, 'no initialized server → empty result, synchronously');
    }
}
