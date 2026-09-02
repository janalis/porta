<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;

/**
 * The mock language server must ADVERTISE what it ANSWERS.
 *
 * This exists because the same defect landed three times in one round. The
 * mock answered `callHierarchy/incomingCalls` while advertising no
 * `callHierarchyProvider`, so a feature shipped ungated and flashed "no
 * callers found" at every real user — neither bundled PHP server provides call
 * hierarchy. It ignored `context.only`, so a client's kind filter was proven by
 * nothing. And a `WorkspaceEditor` test built its URI through a helper that
 * canonicalised it, so the canonicalisation under test never ran.
 *
 * One shape underneath all three: **a fixture written to make a feature
 * reachable also makes its contract unfalsifiable.** A capability gate is
 * exactly the part a generous fixture erases, so this pins the two lists
 * against each other. Adding a branch to the mock without advertising it fails
 * here, which is the moment to notice.
 */
final class MockServerContractTest extends TestCase
{
    /** LSP method => the server capability a real server gates it behind. */
    private const array GATED_BY = [
        'textDocument/completion' => 'completionProvider',
        'textDocument/hover' => 'hoverProvider',
        'textDocument/signatureHelp' => 'signatureHelpProvider',
        'textDocument/definition' => 'definitionProvider',
        'textDocument/typeDefinition' => 'typeDefinitionProvider',
        'textDocument/implementation' => 'implementationProvider',
        'textDocument/references' => 'referencesProvider',
        'textDocument/documentSymbol' => 'documentSymbolProvider',
        'textDocument/codeAction' => 'codeActionProvider',
        'textDocument/formatting' => 'documentFormattingProvider',
        'textDocument/rangeFormatting' => 'documentRangeFormattingProvider',
        'textDocument/rename' => 'renameProvider',
        'textDocument/prepareRename' => 'renameProvider',
        'textDocument/foldingRange' => 'foldingRangeProvider',
        'textDocument/inlayHint' => 'inlayHintProvider',
        'textDocument/documentHighlight' => 'documentHighlightProvider',
        'textDocument/selectionRange' => 'selectionRangeProvider',
        'textDocument/prepareCallHierarchy' => 'callHierarchyProvider',
        'callHierarchy/incomingCalls' => 'callHierarchyProvider',
        'callHierarchy/outgoingCalls' => 'callHierarchyProvider',
        'workspace/symbol' => 'workspaceSymbolProvider',
        'workspace/executeCommand' => 'executeCommandProvider',
    ];

    public function testTheMockAdvertisesEveryGatedMethodItAnswers(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../Support/mock-lsp-server.php');
        $answered = self::answeredMethods($source);
        $advertised = self::advertisedCapabilities($source);

        self::assertNotSame([], $answered, 'the parse found no handlers — this test would pass vacuously');

        $missing = [];
        foreach ($answered as $method) {
            $capability = self::GATED_BY[$method] ?? null;
            if (null !== $capability && !\in_array($capability, $advertised, strict: true)) {
                $missing[$method] = $capability;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "the mock answers these but advertises no capability for them, so a client that gates on one would find the request unanswerable while every test stayed green:\n  "
                .implode("\n  ", array_map(static fn (string $m, string $c): string => "{$m} needs {$c}", array_keys($missing), $missing)),
        );
    }

    /** And the reverse: a capability nothing answers is a different lie, told the same way. */
    public function testTheMockAnswersEveryGatedCapabilityItAdvertises(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../Support/mock-lsp-server.php');
        $answered = self::answeredMethods($source);
        $backed = [];
        foreach ($answered as $method) {
            $capability = self::GATED_BY[$method] ?? null;
            if (null !== $capability) {
                $backed[] = $capability;
            }
        }

        $gated = array_values(array_unique(array_values(self::GATED_BY)));
        $empty = array_values(array_diff(array_intersect(self::advertisedCapabilities($source), $gated), $backed));

        $this->assertSame([], $empty, 'advertised with no handler behind it: '.implode(', ', $empty));
    }

    /** @return list<string> */
    private static function answeredMethods(string $source): array
    {
        preg_match_all("/'([a-zA-Z\\/\\\$]+\\/[a-zA-Z]+)' === \\\$method/", $source, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> */
    private static function advertisedCapabilities(string $source): array
    {
        $start = strpos($source, needle: "'capabilities' => [");
        self::assertNotFalse($start, 'no capabilities block in the mock');
        $end = strpos($source, needle: "'serverInfo'", offset: $start);
        self::assertNotFalse($end);
        preg_match_all("/'([a-zA-Z]+Provider)'\\s*=>/", substr($source, $start, $end - $start), $m);

        return array_values(array_unique($m[1]));
    }
}
