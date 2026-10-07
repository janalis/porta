<?php

declare(strict_types=1);

namespace Porta\Custos\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Ide;
use Porta\Lsp\RefactorController;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\Widgets;
use Symfony\Component\Tui\Tui;

/**
 * The custos plugin against the REAL `custos lsp`, through a real IDE: the
 * plugin's own registration (a supplementary php server), the session pool,
 * the diagnostics mapping, RefactorController's merged code actions, the
 * `codeAction/resolve` round trip routed back to custos, and WorkspaceEditor.
 *
 * Every other test of this plugin runs a stub, which proves the wiring and
 * nothing about the protocol — a fixture grants what the code needs. This one
 * is the contract with the actual server, so it needs the actual binary:
 * `CUSTOS_BIN`, else `~/Sites/custos/bin/custos` (`make build` there). Without
 * one it is skipped, and the suite stays deterministic for everyone else.
 */
final class IdeCustosRealServerTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    private const string SOURCE = '<?php $x = !!$y;';

    private string $binary = '';

    protected function setUp(): void
    {
        $binary = self::binary();
        if (null === $binary) {
            $this->markTestSkipped('needs the real custos binary: set CUSTOS_BIN, or build ~/Sites/custos/bin/custos (make build)');
        }
        $this->binary = $binary;
        // Nothing resolved off the PATH: custos is named by its full path,
        // and no other php server is enabled to answer in its place.
        \putenv('PATH=/nonexistent');
    }

    private static function binary(): ?string
    {
        $candidate = \getenv('CUSTOS_BIN');
        if (!\is_string($candidate) || '' === $candidate) {
            $home = \getenv('HOME');
            $candidate = \is_string($home) && '' !== $home ? $home.'/Sites/custos/bin/custos' : '';
        }

        return '' !== $candidate && \is_file($candidate) && \is_executable($candidate) ? $candidate : null;
    }

    /**
     * custos's diagnostics on line 0 of the active buffer.
     *
     * @return list<array<string, mixed>>
     */
    private static function custosDiagnostics(Ide $ide): array
    {
        $diagnostics = Services::resolve($ide, BufferManager::class)->active()->diagnostics[0] ?? [];

        return \array_values(\array_filter($diagnostics, static fn (array $d): bool => 'custos' === ($d['source'] ?? null)));
    }

    /**
     * The actions RefactorController has merged for its latest request.
     *
     * @return list<array<string, mixed>>
     */
    private static function offered(RefactorController $refactor): array
    {
        /** @var list<array<string, mixed>> */
        return Probe::get($refactor, 'actions');
    }

    public function testDiagnosticsQuickFixAndFixAllRoundTripThroughTheRealServer(): void
    {
        $project = new TempProject([
            'a.php' => self::SOURCE,
            '.porta.json' => \json_encode(
                ['enabledPlugins' => ['custos'], 'plugins' => ['custos' => ['binary' => $this->binary]]],
                \JSON_THROW_ON_ERROR,
            ),
        ]);

        $this->runIde($project, $project->path('a.php'), [
            200 => function (Tui $tui, $terminal, Ide $ide): void {
                $this->await(static fn (): bool => [] !== self::custosDiagnostics($ide), what: 'custos to publish diagnostics for a.php', timeout: 20.0);
                $nested = \array_values(\array_filter(self::custosDiagnostics($ide), static fn (array $d): bool => 'NestedNotOperators' === ($d['code'] ?? null)));
                $this->assertCount(1, $nested, 'one NestedNotOperators finding: '.\json_encode(self::custosDiagnostics($ide)));
                $diagnostic = $nested[0];
                $this->assertSame(11, $diagnostic['col'], 'the finding starts at the `!!`');
                $this->assertGreaterThan(11, $diagnostic['endCol'] ?? 0);
                // custos identifies each finding in the diagnostic's `data`
                // (rule + byte span); the mapper must carry it through verbatim
                // so it goes back in the codeAction context.
                $this->assertSame(['rule' => 'NestedNotOperators', 'start' => 11, 'end' => 15], $diagnostic['data'] ?? null, 'the diagnostic `data` survives the mapping');

                $refactor = Services::resolve($ide, RefactorController::class);
                $refactor->requestCodeActions(0, 11);
                $this->await(
                    // The controller is captured; its actions are re-read each turn.
                    static fn (): bool => [] !== self::offered($refactor),
                    what: 'custos to answer textDocument/codeAction',
                    timeout: 20.0,
                );
                $actions = self::offered($refactor);
                $kinds = \array_map(static fn (array $a): string => (string) ($a['kind'] ?? ''), $actions);
                $this->assertContains('source.fixAll.custos', $kinds, 'the fix-all action is offered: '.\json_encode($actions));
                $quickFixes = \array_values(\array_filter(
                    $actions,
                    static fn (array $a): bool => (
                        \str_starts_with((string) ($a['kind'] ?? ''), 'quickfix')
                        && \in_array('NestedNotOperators', \array_map(static fn (mixed $d): mixed => \is_array($d) ? $d['code'] ?? null : null, (array) ($a['diagnostics'] ?? [])), strict: true)
                    ),
                ));
                $this->assertCount(1, $quickFixes, 'one quick-fix for the finding: '.\json_encode($actions));
                $this->assertArrayNotHasKey('edit', $quickFixes[0], 'custos defers the edit to codeAction/resolve, so this proves the resolve path');

                Probe::invoke($refactor, 'applyOrResolve', $quickFixes[0]);
                $editor = Widgets::editor($tui);
                $this->await(
                    static fn (): bool => self::SOURCE !== $editor->getDocument()->getText(),
                    what: 'the resolved quick-fix to be applied to the buffer',
                    timeout: 20.0,
                );
                $this->assertSame('<?php $x = (bool)$y;', $editor->getDocument()->getText());
            },
        ]);
    }
}
