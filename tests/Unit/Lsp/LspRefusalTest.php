<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * A user-invoked LSP verb that cannot run must SAY so, not return in silence.
 *
 * Every request site answered a missing server with a bare `return;`, so in a
 * Markdown, JSON or JavaScript buffer — or in any buffer before a PHP server
 * has finished starting — Go to Declaration, Find Usages, Quick Documentation,
 * Peek, File Structure, Quick Fixes, Refactor This, Inline, Optimize Imports
 * and Reformat all did nothing at all, with no message. One verb, Rename, had
 * flashed since long before, which is what made the rest look deliberate.
 *
 * The guard is structural rather than behavioural on purpose. Ten verbs share
 * five request helpers, and a per-verb test would pin the five helpers ten
 * times over while still saying nothing about the ELEVENTH verb someone adds
 * next month behind a sixth helper. This asks the question that actually
 * protects the user: does any `ready()` call site outside the named allowlist
 * exist?
 */
final class LspRefusalTest extends TestCase
{
    /**
     * The sites that must stay silent, and why.
     *
     * Both are reached identically from a keystroke and from a user's explicit
     * request — completion from `->`/`::` as well as Ctrl+Space, signature help
     * from `(`/`,` as well as Ctrl+Shift+P — so a flash here would fire while
     * someone types in any buffer without a server. Silence is the lesser
     * wrong, and naming them here is what keeps it a decision rather than an
     * oversight.
     *
     * Keyed by file basename => the methods allowed to call bare `ready()`.
     *
     * @var array<string, list<string>>
     */
    private const array SILENT_BY_DESIGN = [
        'CompletionController.php' => ['requestCompletion', 'requestSignatureHelp'],
    ];

    /**
     * Files that reach the pool directly rather than through the base class's
     * `ready()`, because they are not answering a user's keystroke at all:
     * folding and format-on-save act on a BUFFER (which may not be the focused
     * one), and the highlight controller calls `ready()` purely to warm the
     * session. Nothing here has a user waiting on a visible answer.
     *
     * @var list<string>
     */
    private const array NOT_USER_INVOKED = [
        'FoldingController.php',
        'FormatOnSave.php',
        'HighlightController.php',
    ];

    public function testEveryUserInvokedRequestSiteExplainsItselfOrIsAllowlisted(): void
    {
        $silent = [];
        foreach (Finder::create()
            ->files()
            ->in(__DIR__.'/../../../src/Lsp')
            ->name('*.php') as $file) {
            $name = $file->getFilename();
            if ('AbstractLspController.php' === $name || \in_array($name, self::NOT_USER_INVOKED, strict: true)) {
                continue;
            }
            $allowed = self::SILENT_BY_DESIGN[$name] ?? [];
            foreach ($this->bareReadyMethods((string) $file->getContents()) as $method) {
                if (\in_array($method, $allowed, strict: true)) {
                    continue;
                }

                $silent[] = $name.'::'.$method;
            }
        }
        sort($silent);

        $this->assertSame(
            [],
            $silent,
            'these call $this->ready() and refuse in silence: use readyOrExplain(), or add them to SILENT_BY_DESIGN with the reason',
        );
    }

    /** The allowlist names sites that exist — an entry left behind after a rename proves nothing. */
    public function testTheSilentAllowlistNamesLiveCallSites(): void
    {
        foreach (self::SILENT_BY_DESIGN as $file => $methods) {
            $source = (string) file_get_contents(__DIR__.'/../../../src/Lsp/'.$file);
            $found = $this->bareReadyMethods($source);
            foreach ($methods as $method) {
                $this->assertContains($method, $found, "{$file}::{$method} no longer calls a bare ready()");
            }
        }
    }

    /**
     * The methods in $source that call `$this->ready(` — matched by walking
     * from each `function name(` to the next one, which is enough structure for
     * a file of ordinary single-class PHP and needs no parser.
     *
     * @return list<string>
     */
    private function bareReadyMethods(string $source): array
    {
        $matches = [];
        preg_match_all('/function\s+(\w+)\s*\(/', $source, $matches, \PREG_OFFSET_CAPTURE);
        $out = [];
        $names = $matches[1];
        foreach ($names as $i => [$name, $offset]) {
            $end = $names[$i + 1][1] ?? \strlen($source);
            $body = substr($source, $offset, $end - $offset);
            if (str_contains($body, '$this->ready(')) {
                $out[] = $name;
            }
        }

        return $out;
    }
}
